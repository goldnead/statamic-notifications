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

    /**
     * Die Kanaele, die diese Art ueberhaupt verwenden kann. `null` heisst alle
     * konfigurierten — die Vorgabe, damit ein bestehender Typ sich nicht
     * aendert.
     *
     * Der Unterschied zu $defaultChannels: dort steht, was VOREINGESTELLT an
     * ist, hier was ueberhaupt zur Wahl steht. Eine Tagesübersicht ergibt Sinn,
     * wenn an einem Tag zehn Reaktionen zusammenkommen — fuer "dir wurde eine
     * Aufgabe zugewiesen" ist sie sinnlos, denn die Aufgabe waere dann einen
     * Tag alt. Ohne diese Angabe bot die Selbstbedienungs-Seite jeden Kanal
     * fuer jede Art an und war ein Drittel groesser als noetig.
     *
     * @var array<int, string>|null
     */
    public ?array $supportedChannels = null;

    /**
     * Ob diese Art fuer diesen Empfaenger ueberhaupt in Frage kommt.
     *
     * `null` heisst ja, fuer alle — die Vorgabe. Wer es setzt, sagt: zeig das
     * niemandem, der es nie bekommen kann.
     *
     * Der Grund ist eine echte Beobachtung: eine frisch angemeldete
     * Newsletter-Adresse ohne Community-Konto sah vier Community-Zeilen und
     * eine interne CRM-Zeile — fuenfzehn Kaestchen, von denen kein einziges je
     * gewirkt haette. Eine Einstellung anzubieten, die nichts bewirken kann,
     * ist schlimmer als keine: sie sieht aus wie eine Wahl.
     *
     * Die Frage kann nur das registrierende Paket beantworten, deshalb ein
     * Closure und keine Liste von Rollen.
     *
     * @var Closure(mixed): bool|null
     */
    public ?Closure $appliesTo = null;

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
     * Die Kanaele, die diese Art ueberhaupt anbieten darf.
     *
     * Alles, was nicht hier steht, taucht in der Selbstbedienungs-Seite nicht
     * auf und wird beim Versand nicht bedient.
     *
     * @param  array<int, string>  $channels
     */
    public function supportedChannels(array $channels): self
    {
        $this->supportedChannels = $channels;

        return $this;
    }

    /**
     * Fuer wen diese Art ueberhaupt in Frage kommt.
     *
     * Bekommt den Empfaenger und antwortet ja oder nein. Wer nein sagt, sieht
     * die Art gar nicht — keine ausgegraute Zeile, keine Erklaerung, warum sie
     * nicht gilt. Eine Zeile, die nicht gelten kann, ist kein Hinweis, sondern
     * Rauschen.
     *
     * @param  Closure(mixed): bool  $callback
     */
    public function appliesTo(Closure $callback): self
    {
        $this->appliesTo = $callback;

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
