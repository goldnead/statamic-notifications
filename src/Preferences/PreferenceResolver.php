<?php

namespace Goldnead\Notifications\Preferences;

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Models\NotificationPreference;
use Goldnead\Notifications\Types\TypeRegistry;

/**
 * Decides whether a given person wants a given type on a given channel.
 *
 * Stored rows are deviations only. Absence means "use the type's default", so
 * changing a default actually reaches everyone who never expressed an opinion —
 * the opposite of materialising every combination at signup and then being
 * unable to move.
 */
class PreferenceResolver
{
    public function __construct(protected TypeRegistry $types) {}

    public function allows(Identity $recipient, string $type, string $channel): bool
    {
        $definition = $this->types->get($type);

        // Required types ignore preferences entirely: account security, legal
        // notices. The escape hatch, used sparingly.
        if ($definition->required) {
            return true;
        }

        $stored = $this->stored($recipient, $type, $channel);

        if ($stored !== null) {
            return (bool) $stored->enabled;
        }

        return $definition->usesChannel($channel);
    }

    public function set(Identity $recipient, string $type, string $channel, bool $enabled, ?string $frequency = null): NotificationPreference
    {
        return NotificationPreference::updateOrCreate(
            [
                'user_id' => $recipient->userId,
                'contact_uuid' => $recipient->contactUuid,
                'type' => $type,
                'channel' => $channel,
            ],
            array_filter([
                'enabled' => $enabled,
                'frequency' => $frequency,
            ], static fn ($value) => $value !== null),
        );
    }

    /** The digest cadence this person wants, or the configured default. */
    public function digestFrequency(Identity $recipient): string
    {
        $stored = NotificationPreference::forRecipient($recipient)
            ->where('channel', 'digest')
            ->whereNotNull('frequency')
            ->value('frequency');

        return $stored ?? (string) config('notifications.digest.default_frequency', 'weekly');
    }

    /**
     * The full matrix for a preference centre: every registered type × channel
     * with its effective value, deviations marked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrixFor(Identity $recipient): array
    {
        $channels = array_keys((array) config('notifications.channels', []));
        $matrix = [];

        foreach ($this->types->all() as $handle => $definition) {
            $row = [
                'type' => $handle,
                'label' => $definition->label ?? $handle,
                'required' => $definition->required,
                'channels' => [],
            ];

            foreach ($channels as $channel) {
                $stored = $this->stored($recipient, $handle, $channel);

                $row['channels'][$channel] = [
                    'enabled' => $this->allows($recipient, $handle, $channel),
                    'is_default' => $stored === null,
                ];
            }

            $matrix[] = $row;
        }

        return $matrix;
    }

    protected function stored(Identity $recipient, string $type, string $channel): ?NotificationPreference
    {
        if (! $recipient->isIdentified()) {
            return null;
        }

        return NotificationPreference::forRecipient($recipient)
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();
    }
}
