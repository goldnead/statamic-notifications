<?php

/**
 * Ein Platzhalter fuer die Snapshot-Schicht aus `statamic-email-templates`.
 *
 * Das Geschwister-Addon ist weder `require` noch `require-dev` — die
 * Abhaengigkeit ist in genau eine Richtung optional, und dieses Paket koppelt
 * ausschliesslich ueber den Klassennamen als Zeichenkette. Der Platzhalter ist
 * damit alles, was es braucht, um die eigene Verdrahtung zu pruefen, ohne das
 * Geschwister zu installieren.
 *
 * **Was er beweist und was nicht.** Er beweist, dass dieses Addon einmal je
 * Versand aufzeichnet, mit den richtigen Besitzer-Werten, mit Platzhaltern
 * statt Empfaengertext, und dass der Zeiger auf der Zeile landet. Er beweist
 * **nicht**, dass die echte Klasse den Aufruf annimmt — dafuer ist der
 * Playground da, wo beide Addons wirklich installiert sind.
 *
 * Deshalb ist er so streng wie das Original, wo Strenge etwas bedeutet: die
 * Wiederverwendung ueber `(owner_type, owner_id, content_hash)` und die
 * Abweisung gerenderter Inhalte sind aus `Snapshots` uebernommen. Ein
 * Platzhalter, der alles annimmt, laesst genau die Fehler durch, gegen die er
 * aufgestellt wurde.
 *
 * Bewacht deklariert, damit eine echte Installation gewinnt. Gleiche Regel wie
 * bei den Insights-Platzhaltern nebenan.
 */

namespace Goldnead\EmailTemplates\Snapshots;

if (! class_exists('Goldnead\EmailTemplates\Snapshots\EmailTemplateSnapshot')) {
    class EmailTemplateSnapshot
    {
        public int $id = 0;

        public ?string $owner_type = null;

        public ?string $owner_id = null;

        public string $subject = '';

        public string $body = '';

        public ?string $template_slug = null;

        public ?string $template_source = null;

        public ?string $sender_name = null;

        public ?string $sender_email = null;

        public string $content_hash = '';

        public int $send_count = 1;

        public function getKey(): int
        {
            return $this->id;
        }
    }
}

if (! class_exists('Goldnead\EmailTemplates\Snapshots\Snapshots')) {
    class Snapshots
    {
        /** @var array<int, EmailTemplateSnapshot> */
        public static array $rows = [];

        public static bool $enabled = true;

        public static function reset(): void
        {
            static::$rows = [];
            static::$enabled = true;
        }

        public static function available(): bool
        {
            return static::$enabled;
        }

        public static function record(?string $ownerType, int|string|null $ownerId, array $template, array $meta = []): ?EmailTemplateSnapshot
        {
            if (! static::available()) {
                return null;
            }

            $subject = (string) ($template['subject'] ?? '');
            $body = (string) ($template['body'] ?? $template['html'] ?? '');

            // Wie im Original: gerenderte Post kommt hier nicht herein.
            foreach ([$subject, $body] as $inhalt) {
                if (static::looksRendered($inhalt)) {
                    return null;
                }
            }

            // Der Absender geht wie im Original in den Fingerabdruck ein: eine
            // zweite Marke sendet dieselbe Vorlage unter anderer Adresse, und
            // das ist eine andere Fassung.
            $hash = hash('sha256', $subject."\0".$body."\0".($meta['sender_email'] ?? ''));

            foreach (static::$rows as $row) {
                if ($row->owner_type === $ownerType && $row->owner_id === (string) $ownerId && $row->content_hash === $hash) {
                    $row->send_count++;

                    return $row;
                }
            }

            $row = new EmailTemplateSnapshot;
            $row->id = count(static::$rows) + 1;
            $row->owner_type = $ownerType;
            $row->owner_id = $ownerId === null ? null : (string) $ownerId;
            $row->subject = $subject;
            $row->body = $body;
            $row->template_slug = $template['slug'] ?? null;
            $row->template_source = $template['source'] ?? null;
            $row->sender_name = $meta['sender_name'] ?? null;
            $row->sender_email = $meta['sender_email'] ?? null;
            $row->content_hash = $hash;

            static::$rows[$row->id] = $row;

            return $row;
        }

        public static function find(int|string $id): ?EmailTemplateSnapshot
        {
            return static::available() ? (static::$rows[(int) $id] ?? null) : null;
        }

        public static function looksRendered(string $html): bool
        {
            if ($html === '') {
                return false;
            }

            if (preg_match('/[?&]signature=[a-f0-9]{16,}/i', $html) === 1) {
                return true;
            }

            return preg_match('#/o/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.gif#i', $html) === 1;
        }
    }
}

if (! class_exists('Goldnead\EmailTemplates\Snapshots\SnapshotPreview')) {
    class SnapshotPreview
    {
        /**
         * Setzt `{{ name }}` ein, wie `MergeVariables::apply()` es tut:
         * unbekannte Marken bleiben stehen, eingesetzte Werte werden maskiert.
         */
        public static function document(EmailTemplateSnapshot $snapshot, array $mergeData = []): string
        {
            $mail = static::renderMail($snapshot, $mergeData);

            return '<!doctype html><html><body><div class="et-head">'
                .htmlspecialchars(static::apply($snapshot->subject, $mergeData), ENT_QUOTES, 'UTF-8')
                .'</div><iframe srcdoc="'.htmlspecialchars($mail, ENT_QUOTES, 'UTF-8').'"></iframe></body></html>';
        }

        public static function renderMail(EmailTemplateSnapshot $snapshot, array $mergeData = []): string
        {
            return static::apply($snapshot->body, $mergeData);
        }

        protected static function apply(string $text, array $data): string
        {
            return (string) preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                fn (array $m) => array_key_exists($m[1], $data)
                    ? htmlspecialchars((string) $data[$m[1]], ENT_QUOTES, 'UTF-8')
                    : $m[0],
                $text
            );
        }
    }
}
