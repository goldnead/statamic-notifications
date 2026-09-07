<?php

namespace Goldnead\Notifications\Sending;

use Goldnead\Notifications\Contracts\SenderIdentityResolver;
use Goldnead\Notifications\Http\Controllers\Cp\NotificationController;
use Goldnead\Notifications\Models\NotificationItem;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Was als E-Mail rausging, festgehalten in der gemeinsamen Snapshot-Schicht.
 *
 * ## Warum hier nichts gespeichert wird
 *
 * Es gibt genau eine Tabelle fuer Versendetes, `email_template_snapshots`, und
 * sie liegt in `statamic-email-templates`. Dieses Addon ist Verbraucher. Es
 * legt keine eigene Tabelle an, es importiert nichts aus dem Geschwister-Paket
 * — der Klassenname steht als Zeichenkette da und wird mit `class_exists`
 * geprueft, weil die Abhaengigkeit nur in eine Richtung optional ist.
 *
 * ## Was hier die „Vorlage" ist
 *
 * Der Vertrag ist auf Vorlagen mit `{{ … }}`-Platzhaltern zugeschnitten. Dieses
 * Addon hat keine Vorlagen im Sinne von marketing: die Art rendert ihren Text
 * ueber ein Closure, und was dabei herauskommt, ist bereits der fertige Satz
 * fuer genau einen Empfaenger. Ein Platzhalter-Stadium gibt es dort nicht.
 *
 * Was es gibt, ist das Mail-Layout: `notifications::mail.notification`, mit
 * genau drei Variablen — `title`, `body`, `link`. Genau das wird aufgezeichnet:
 * das Layout, gerendert mit `{{ title }}`, `{{ body }}` und `{{ link }}` an
 * Stelle der Werte. Damit ist der Snapshot das, was der Vertrag verlangt — die
 * Vorlage, wie sie beim Versand aussah, mit Platzhaltern statt Personendaten —
 * und er aendert sich genau dann, wenn der Host das Layout veroeffentlicht und
 * umbaut. Zehntausend Mails einer Art sind eine Zeile.
 *
 * Eingesetzt wird erst beim Ansehen, in
 * {@see NotificationController::preview()},
 * aus den Werten dieser einen Zeile. Nichts davon wird gespeichert.
 *
 * ## Kanaele ohne Mail
 *
 * Fuer `in_app` und fuer jeden Kurznachrichten-Kanal, den ein Host registriert
 * (Telegram, Push, SMS), wird **nichts** aufgezeichnet, und die Detailseite
 * sagt das. Der Grund ist nicht Bequemlichkeit: eine Kurznachricht hat kein
 * Layout. Ihre „Vorlage" waere nichts als der konkrete Text an eine konkrete
 * Person — also genau der personenbezogene Inhalt, den diese Tabelle nicht
 * tragen darf, ohne eine Frist und ein Loeschkonzept fuer die ganze Suite
 * nachzuziehen. Der zugestellte Text steht ohnehin schon in
 * `notification_items.message`, wird dort seit jeher gezeigt und faellt mit der
 * Zeile, die ihn traegt. Eine zweite Kopie waere ein zweiter Ort zum Loeschen.
 *
 * Deshalb ist `email_template_snapshot_id` auch die ehrliche Antwort auf „ging
 * hier eine Mail raus": gesetzt heisst ja, leer heisst nein.
 *
 * ## Nichts hier bricht einen Versand
 *
 * Jeder Pfad faengt `Throwable` und schreibt hoechstens eine Warnung. Eine
 * Benachrichtigung, die nicht ankommt, weil ihre Buchhaltung gestolpert ist,
 * waere der teuerste denkbare Tausch.
 */
class SentSnapshot
{
    /**
     * Eingefroren. Die Zeichenkette steht wortgleich in drei Repos; ein
     * Tippfehler faellt auf nichts als „keine Snapshots" zurueck.
     */
    private const SNAPSHOTS = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';

    private const SNAPSHOT_PREVIEW = 'Goldnead\\EmailTemplates\\Snapshots\\SnapshotPreview';

    /**
     * Bindend laut Vertrag. `$ownerId` ist der Handle der Benachrichtigungsart,
     * nie der Empfaenger und nie die Zeile je Empfaenger.
     */
    private const OWNER_TYPE = 'notifications:type';

    /** Steht die Schicht ueberhaupt zur Verfuegung? */
    public static function available(): bool
    {
        if (! class_exists(self::SNAPSHOTS)) {
            return false;
        }

        $class = self::SNAPSHOTS;

        return (bool) $class::available();
    }

    /**
     * Das Mail-Layout aufzeichnen, wie es beim Versand stand.
     *
     * Gibt die id der Snapshot-Zeile zurueck, oder `null`, wenn nichts
     * aufgezeichnet wurde. Der Aufrufer schreibt sie erst auf die Zeile, wenn
     * der Versand durch ist — ein Snapshot fuer eine Mail, die nie das Haus
     * verliess, sieht auf der Detailseite aus wie ein Erfolg.
     *
     * @param  array{message: string|null, link: string|null, title: string|null}  $rendered
     */
    public static function record(NotificationItem $item, array $rendered): ?int
    {
        if (! self::available()) {
            return null;
        }

        try {
            // Ueber die Factory und nicht ueber den `view()`-Helfer: dessen
            // Signatur verlangt eine Ansicht, die die statische Analyse auf der
            // Platte findet, und ein Addon-Namensraum ist zur Analysezeit nicht
            // aufgeloest. Es ist derselbe Aufruf, den auch die Mailable macht.
            $body = app(Factory::class)->make('notifications::mail.notification', [
                'item' => $item,
                'title' => self::placeholder($rendered['title'] ?? null, 'title'),
                'body' => self::placeholder($rendered['message'] ?? null, 'body'),
                'link' => self::placeholder($rendered['link'] ?? null, 'link'),
            ])->render();

            if (self::carriesPersonalText($body, $item, $rendered)) {
                return null;
            }

            $class = self::SNAPSHOTS;

            $snapshot = $class::record(self::OWNER_TYPE, $item->type, [
                // Der Betreff ist zur Laufzeit `$rendered['title']`. Als
                // Platzhalter statt als heutiger Wert, weil eine Art ihren Titel
                // je Zeile umschreiben darf — ein festgeschriebener Betreff waere
                // fuer jede solche Art eine Behauptung ueber Mails, die anders
                // rausgingen.
                'subject' => '{{ title }}',
                'body' => $body,
                'slug' => $item->type,
                'source' => 'notifications',
            ], self::sender($item));

            return $snapshot?->getKey();
        } catch (Throwable $e) {
            Log::warning('statamic-notifications: der Versand-Snapshot konnte nicht aufgezeichnet werden.', [
                'type' => $item->type,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Die Snapshot-Zeile zu dieser Benachrichtigung, oder `null`.
     *
     * Bewusst ueber die gespeicherte id und nicht ueber `latestForOwner()`: die
     * neueste Fassung einer Art ist nicht die, die im Maerz rausging, und die
     * Frage auf der Detailseite lautet, was rausging.
     */
    public static function find(NotificationItem $item): ?object
    {
        if ($item->email_template_snapshot_id === null || ! self::available()) {
            return null;
        }

        $class = self::SNAPSHOTS;

        return $class::find($item->email_template_snapshot_id);
    }

    /**
     * Die fertige Vorschau-Seite: der Snapshot mit den Werten dieser Zeile.
     *
     * Die Hinweisleiste kommt aus der Schicht und sagt selbst, woher die
     * eingesetzten Werte stammen. Sie wird hier nicht wiederholt.
     *
     * @param  array{message: string|null, link: string|null, title: string|null}  $rendered
     */
    public static function document(NotificationItem $item, array $rendered): ?string
    {
        $snapshot = self::find($item);

        if ($snapshot === null || ! class_exists(self::SNAPSHOT_PREVIEW)) {
            return null;
        }

        $preview = self::SNAPSHOT_PREVIEW;

        $values = array_filter([
            'title' => $rendered['title'] ?? null,
            'body' => $rendered['message'] ?? null,
            'link' => $rendered['link'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $preview::document($snapshot, $values);
    }

    /**
     * Der Absender, unter dem diese Mail wirklich rausging.
     *
     * Ohne diese Angabe traegt die Schicht die Vorschau-Absenderadresse der
     * Marke ein, und die Zeile behauptete dann einen Absender, den kein
     * Empfaenger gesehen hat. Die Marke kommt von der Zeile und nicht aus dem
     * Umgebungskontext — dieselbe Begruendung wie beim Versand selbst.
     *
     * @return array<string, string|null>
     */
    private static function sender(NotificationItem $item): array
    {
        $identity = app(SenderIdentityResolver::class)->resolve((int) $item->brand_id);

        if ($identity->fromAddress === null) {
            // Die Marke hat keine eigene Adresse erklaert; dann entscheidet
            // `config('mail.from')`, und die Schicht kommt selbst darauf.
            return [];
        }

        return [
            'sender_email' => $identity->fromAddress,
            'sender_name' => $identity->fromName,
        ];
    }

    /** `{{ name }}`, solange es an dieser Stelle ueberhaupt etwas zu setzen gab. */
    private static function placeholder(?string $value, string $name): ?string
    {
        return ($value === null || $value === '') ? null : '{{ '.$name.' }}';
    }

    /**
     * Steht im gerenderten Layout Text, der einer Person gehoert?
     *
     * Die drei Variablen werden durch Platzhalter ersetzt, also kann dort
     * nichts Personenbezogenes stehen — es sei denn, der Host hat die Ansicht
     * veroeffentlicht und greift darin selbst auf `$item` zu. Genau dieser Fall
     * ist der Grund fuer die Probe: das Layout eines fremden Hosts ist nicht
     * unsere Datei, und die Tabelle traegt keine Frist, hinter der ein Fehler
     * hier verschwindet.
     *
     * @param  array{message: string|null, link: string|null, title: string|null}  $rendered
     */
    private static function carriesPersonalText(string $body, NotificationItem $item, array $rendered): bool
    {
        $personal = array_filter([
            $item->email,
            $item->actor_name,
            $item->contact_uuid,
            $rendered['message'] ?? null,
            $rendered['link'] ?? null,
        ], fn ($value) => is_string($value) && mb_strlen($value) >= 4);

        foreach ($personal as $value) {
            if (str_contains($body, $value) || str_contains($body, e($value))) {
                Log::warning(
                    'statamic-notifications: kein Versand-Snapshot aufgezeichnet — das Mail-Layout setzt '
                    .'Empfaengerdaten selbst ein. Eine veroeffentlichte Fassung von '
                    .'resources/views/vendor/notifications/mail/notification.blade.php darf nur die '
                    .'uebergebenen Variablen ausgeben, nicht $item.',
                    ['type' => $item->type]
                );

                return true;
            }
        }

        return false;
    }
}
