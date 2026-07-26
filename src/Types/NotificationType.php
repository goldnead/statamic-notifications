<?php

namespace Goldnead\Notifications\Types;

use Closure;
use Goldnead\Notifications\Models\NotificationItem;

/**
 * The definition of one kind of notification: what it is called, which channels
 * it uses by default, and how it renders.
 *
 * Presentation is a callback rather than a template, because the host owns both
 * the wording and the URL structure. The addon never hardcodes a route or a
 * sentence — that is exactly what made the existing community service
 * unextractable.
 */
final class NotificationType
{
    /** @var array<int, string> */
    public array $defaultChannels = ['in_app'];

    public ?Closure $renderer = null;

    public ?string $label = null;

    public ?string $icon = null;

    /** Whether a recipient may switch this type off at all. */
    public bool $required = false;

    public function __construct(public readonly string $handle) {}

    public static function make(string $handle): self
    {
        return new self($handle);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** @param  array<int, string>  $channels */
    public function defaultChannels(array $channels): self
    {
        $this->defaultChannels = $channels;

        return $this;
    }

    /**
     * A required type ignores preferences: account security, legal notices.
     * Use sparingly — it is the one way to reach someone who opted out.
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * @param  Closure(NotificationItem): array{message?: string, link?: string, title?: string}  $renderer
     */
    public function renderUsing(Closure $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /** @return array{message: string|null, link: string|null, title: string|null} */
    public function render(NotificationItem $item): array
    {
        $rendered = $this->renderer === null ? [] : ($this->renderer)($item);

        return [
            'message' => $rendered['message'] ?? $item->message,
            'link' => $rendered['link'] ?? $item->link,
            'title' => $rendered['title'] ?? $this->label ?? $this->handle,
        ];
    }

    public function usesChannel(string $channel): bool
    {
        return in_array($channel, $this->defaultChannels, true);
    }
}
