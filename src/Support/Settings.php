<?php

namespace Goldnead\Notifications\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Die Einstellungen, die ein Betreiber im Control Panel aendern darf.
 *
 * Diese Klasse ist eine Erklaerung und nichts weiter. Tabelle, Formular,
 * Validierung, Speicher, Rechtepruefung und die Seite unter
 * `/cp/brand-settings` kommen aus `statamic-brand-context`; hier steht nur die
 * Feldliste, und die speist Schirm, Regel und Config-Ueberschreibung
 * gleichermassen.
 *
 * ## Was nicht auf die Seite kommt, und warum
 *
 * Die Ueberschreibungen laufen aus einem `app->booted()`-Rueckruf. Alles, was
 * frueher gelesen wird, sieht noch den Paketwert — ein Schalter, der erst beim
 * naechsten Deploy wirkt, ist eine Luege in der Oberflaeche. Deshalb fehlen
 * hier:
 *
 * - `cp.enabled` — beim Booten gelesen, in der Nav-Registrierung
 *   (`ServiceProvider::registerNavigation()`).
 * - `sources.leadhub` — ebenfalls beim Booten gelesen, in
 *   `ServiceProvider::registerBundledSources()`. Die Quelle wird dort einmal
 *   angemeldet; danach aendert der Wert nichts mehr. Zusaetzlich ist der
 *   Schalter zur Haelfte eine Erkennung (`class_exists`), und eine Erkennung
 *   gehoert Composer, nicht dem Betreiber.
 * - `channels` — Klassennamen, also Code, und eine verschachtelte Abbildung.
 * - `realtime.channel_prefix` — Teil des Broadcast-Vertrags mit dem Client.
 *   Wer ihn hier aendert, ohne den Client mitzuaendern, dreht die
 *   Echtzeit-Meldungen ab, ohne dass irgendwo ein Fehler entsteht.
 *
 * Diese vier bleiben in `config/notifications.php`. Die Gruppentexte unten
 * sagen das, statt es zu verschweigen.
 */
class Settings implements ProvidesSettings
{
    /**
     * Fuer immer stabil: steht in `brand_settings.namespace` auf jeder Zeile,
     * die dieses Addon besitzt. Umbenennen verwaist jede Ueberschreibung.
     */
    public static function settingsNamespace(): string
    {
        return 'notifications';
    }

    /** Die Config-Wurzel, der nicht gesetzte Werte weiter folgen. */
    public static function settingsConfigPath(): string
    {
        return 'notifications';
    }

    /**
     * Neu mit dieser Seite, deshalb der Name aus der Suite-Regel:
     * `manage <handle> settings` mit dem Paketnamen ohne `statamic-`-Praefix.
     * Die beiden bestehenden Rechte dieses Addons — `view notifications` und
     * `manage notification digests` — bleiben unangetastet; sie haengen an
     * echten Benutzergruppen, und ein umbenanntes Recht ist ein stiller
     * Rechteentzug.
     */
    public static function settingsPermission(): string
    {
        return 'manage notifications settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('Zustellung'),
                'description' => __('Ob überhaupt benachrichtigt wird. Welche Kanäle es gibt, steht weiterhin in config/notifications.php, weil dort Klassennamen stehen.'),
                'fields' => [
                    [
                        'key' => 'enabled',
                        'type' => 'boolean',
                        'label' => __('Benachrichtigungen zustellen'),
                        'description' => __('Aus heißt: es wird nichts mehr gespeichert und nichts mehr zugestellt. Bereits gespeicherte Benachrichtigungen bleiben lesbar.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'list_limit',
                        'type' => 'integer',
                        'label' => __('Einträge je Abruf'),
                        'description' => __('Wie viele Benachrichtigungen das eigene Frontend pro Abruf bekommt. Die Liste im Control Panel richtet sich nicht danach, sondern nach der Seitengröße von Statamic.'),
                        'nullable' => false,
                        'min' => 1,
                    ],
                ],
            ],
            [
                'title' => __('Digest'),
                'description' => __('Die Sammelmail. Wann sie läuft, entscheidet der Zeitplan des Hosts, nicht diese Seite.'),
                'fields' => [
                    [
                        'key' => 'digest.default_frequency',
                        'type' => 'select',
                        'options' => [
                            'daily' => __('Täglich'),
                            'weekly' => __('Wöchentlich'),
                        ],
                        // Nur diese beiden. `DigestBuilder::window()` kennt kein
                        // drittes Fenster und `notifications:send-digests`
                        // weist alles andere ab — ein hier angebotenes
                        // "monatlich" waere eine Einstellung, die nie einen
                        // Lauf bekommt.
                        'label' => __('Standard-Takt'),
                        'description' => __('Der Takt für alle, die selbst keinen gewählt haben. Eine eigene Wahl bleibt davon unberührt.'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'preferences_url',
                        'type' => 'string',
                        'label' => __('Adresse der Einstellungsseite'),
                        'description' => __('Steht als Link am Fuß jeder Digest-Mail. Das Addon bringt keine solche Seite mit; leer lässt den Link weg.'),
                        'nullable' => true,
                    ],
                ],
            ],
            [
                'title' => __('Echtzeit'),
                'description' => __('Das inhaltsleere Auffrisch-Signal an angemeldete Clients. Der Kanalname bleibt in config/notifications.php, weil er Teil des Vertrags mit dem Client ist.'),
                'fields' => [
                    [
                        'key' => 'realtime.enabled',
                        'type' => 'boolean',
                        'label' => __('Signal senden'),
                        'description' => __('An heißt: bei jeder neuen Benachrichtigung geht ein Signal an den angemeldeten Client, der daraufhin neu lädt. Braucht einen laufenden Broadcaster; ohne einen wirkt der Schalter nicht.'),
                        'nullable' => false,
                    ],
                ],
            ],
        ];
    }
}
