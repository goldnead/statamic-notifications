<?php

namespace Goldnead\Notifications\Preferences;

use Goldnead\Notifications\Types\NotificationType;
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

        /*
         * Ein Kanal, den diese Art nicht unterstuetzt, ist zu — vor allem
         * anderen, auch vor `required`.
         *
         * Vor der Pflicht-Ausnahme, weil sonst genau die Arten, die niemand
         * abschalten darf, den einzigen Weg offen liessen, der gar nicht
         * gemeint war. Und ueberhaupt hier und nicht nur in der Anzeige: was
         * die Selbstbedienungs-Seite nicht anbietet, darf auch nicht
         * verschickt werden, sonst kaeme Post ueber einen Kanal, den niemand
         * waehlen konnte.
         */
        if (is_array($definition->supportedChannels)
            && ! in_array($channel, $definition->supportedChannels, true)) {
            return false;
        }

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
            /*
             * Zwei Filter, bevor eine Zeile ueberhaupt entsteht.
             *
             * Eine Einstellung anzubieten, die nichts bewirken kann, ist
             * schlimmer als keine — sie sieht aus wie eine Wahl. Eine frisch
             * angemeldete Newsletter-Adresse ohne Community-Konto sah hier
             * vier Community-Zeilen und eine interne CRM-Zeile, jede mit drei
             * Kanaelen: fuenfzehn Kaestchen, von denen kein einziges je
             * gewirkt haette.
             */
            if (! $this->appliesTo($definition, $recipient)) {
                continue;
            }

            $rowChannels = $this->channelsFor($definition, $channels);

            // Eine Art ohne einen einzigen erlaubten Kanal ist keine Zeile.
            if ($rowChannels === []) {
                continue;
            }

            $row = [
                'type' => $handle,
                'label' => $definition->label ?? $handle,
                'required' => $definition->required,
                'channels' => [],
            ];

            foreach ($rowChannels as $channel) {
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

    /**
     * Kommt diese Art fuer diesen Empfaenger in Frage?
     *
     * Ohne gesetztes `appliesTo` ja — das ist die Vorgabe und aendert an
     * bestehenden Typen nichts. Wirft das Closure, gilt die Art als nicht
     * anwendbar: eine kaputte Pruefung darf keine Einstellung freischalten,
     * die niemand sehen soll.
     */
    protected function appliesTo(NotificationType $definition, mixed $recipient): bool
    {
        if (! $definition->appliesTo instanceof \Closure) {
            return true;
        }

        try {
            return (bool) ($definition->appliesTo)($recipient);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Die Kanaele, die diese Art anbieten darf — der Schnitt aus dem, was
     * konfiguriert ist, und dem, was die Art unterstuetzt.
     *
     * Der Schnitt und nicht die Liste der Art allein: ein Kanal, den die
     * Installation gar nicht kennt, darf nicht dadurch entstehen, dass eine
     * Art ihn nennt.
     *
     * @param  array<int, string>  $configured
     * @return array<int, string>
     */
    protected function channelsFor(NotificationType $definition, array $configured): array
    {
        if ($definition->supportedChannels === null) {
            return $configured;
        }

        return array_values(array_intersect($configured, $definition->supportedChannels));
    }
}
