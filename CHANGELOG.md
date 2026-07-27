# Changelog

## 1.0.3 — 2026-07-27

### Fixed — the mail channel never sent a single notification

- **Every immediate e-mail failed silently.** The view was handed a variable named `message`, and Laravel's Mailer always injects its own `Illuminate\Mail\Message` under exactly that name. Rendering therefore died with *"htmlspecialchars(): Argument #1 must be of type string"*, the exception was swallowed by the deliberate `report()` in `dispatchChannels()`, and `notify()` still reported success. Three notifications with channel `mail`, zero mails sent. Renamed to `body`.
- **The existing tests could not have caught it.** `Mail::assertSent()` records a mailable without ever rendering it. Two new tests call `->render()` on both mailables and assert on the produced HTML — that is the only assertion that executes the view.

### Fixed — scheduled digests were a silent no-op under multi-brand

- **`notifications:send-digests` sent nothing when run by a scheduler.** A console run has no CP session, so no brand is current; the global scope then fails closed and every query returns nothing. The command reported "0 digest(s)" and looked healthy. It now walks every brand itself, with `--brand=<handle|id>` to restrict.
- Two new tests cover the scheduler case (no current brand, one mail per brand) and the restriction.

### Notes

- Both found in the local QA run against a real multi-brand hub. Neither was visible in the addon's own suite, which runs single-brand with a faked mailer.
- Suite: **61 passed (130 assertions)**.

## 1.0.2 — 2026-07-27

### Fixed — the CP inspector was largely unstyled

Same two causes as `statamic-activity` v1.0.1, fixed the same way:

- **Statamic 6 ships no utility classes.** Its Control Panel is a Vue component library and its stylesheet contains only what Statamic's own source uses; an addon's Blade file is never scanned. `mb-4`, `flex`, `gap-3`, `btn-primary` and `badge-sm` therefore did not exist at runtime — filter inputs rendered invisible, buttons as bare text, no spacing. `.card`, `.data-table` and `.input-text` do exist and are kept.
- **A `<style>` tag inside the page content is silently dropped**, because Statamic 6 compiles a Blade CP page into a Vue component template and Vue's compiler strips `<style>`. The rules now live in `@section('scripts')`, which the layout yields outside the `#statamic` mount point.
- Styling derives from the CP's design tokens (`--color-primary`, `--radius-*`, `--text-*`) and `currentColor`, so light and dark follow automatically. The unread marker is a real pill again, and the "unread only" checkbox reads as one line instead of a label stacked over a lone box.

### Notes

- Found by looking at the pages in a browser. The tests asserted HTTP 200 and expected strings — true throughout, and blind to styling.
- Suite unchanged: **57 passed (121 assertions)**.

## 1.0.1 — 2026-07-26

### Fixed — immediate mails were repeated in the digest

- **A digest collected every pending item, regardless of channel.** A type whose default channels include `mail` was therefore sent immediately *and* summarised again days later. The digest now collects only what the recipient actually wants digested; items considered and skipped are stamped too, so a later preference change cannot resurface weeks-old notifications.
- Found by the hub integration, not by the addon's own suite: the bundled LeadHub type is the first one in the family whose defaults are `in_app` + `mail`, so nothing in the standalone tests exercised that combination. Two regression tests added.

### Notes

- Suite: **57 passed (121 assertions)**.

## 1.0.0 — 2026-07-26

### Added — persisted notifications

- **`notification_items`, brand-scoped from the first migration.** Deliberately not Laravel's own `notifications` table: that schema has no brand column (isolation would have to hide inside the JSON payload), no dedupe key, and identifies people by `notifiable_type/id` rather than by the shared identity. Naming it differently also means a host can still enable Laravel's database channel later.
- **`Notifications::notify()` / `notifyMany()`.** Idempotent when given a dedupe key — the same fact reaching two producers yields one notification, and `notifyMany` scopes the key per recipient so one shared fact still yields one each. Never throws into the caller: a mail transport error must not roll back the comment that caused it.
- **Type registry with callback rendering.** A type owns its label, default channels and how it renders. Rendering is a closure rather than a template, so wording and URL structure stay with the host. Unregistered types still deliver, so a missing registration cannot silently swallow someone's notification.
- **Per-type × per-channel preferences, stored as deviations only.** Absence means "use the default", so changing a default reaches everyone who never expressed an opinion. Preferences govern how someone is *reached*; the persisted row is always written, because it is the record that this happened.
- **Channels:** `in_app` (the row, plus an optional realtime nudge), `mail` (one per notification), `digest` (a deliberate no-op — the item simply waits, rather than being copied into a second queue that would become a second source of truth).
- **Digests with a window and a send record.** `notification_digest_runs` is unique on (brand, recipient, frequency, window start) and collected items are stamped `digested_at`.
- **Digest source registry.** `Notifications::registerSource()` lets other addons contribute things nobody was notified about — open follow-ups, upcoming events. A failing source is reported and skipped: one addon's broken query must not silence everybody's weekly mail.
- **Bundled LeadHub source**, attaching only when that addon is installed. LeadHub had already grown its own notifier and follow-up digest — the second independent invention of this pattern in the family, which is what justifies a shared one.
- **Optional realtime adapter.** Broadcasts a content-free refresh signal; the client re-fetches through the normal authorised endpoint, so a socket subscriber can never see more than the API would have given them.
- **Laravel channel adapter** so existing `$user->notify()` call sites can route into the persisted store via `via(['notifications'])`.
- **Read-only CP inspector** with `view notifications` / `manage notification digests`.

### Fixed — carried over from the system this replaces

- **The weekly digest repeated itself.** With no window and no record of the send, an unread item went out again every single week for as long as it stayed unread. Both halves are now enforced in the schema, and a regression test drives the command twice to prove one mail.
- **The bundled LeadHub source bypassed brand isolation.** Reading through the query builder skips the sibling's global scope; the brand filter is applied explicitly. Caught by an integration test against the real addon, which also corrected a wrong assumption about where a follow-up's owner lives (on the contact, not the follow-up).

### Notes

- Suite green: **55 passed (117 assertions)** across Unit, Feature and Integration. Coverage includes brand isolation for items, preferences and digest runs (incl. fail-closed with no current brand and the CP detail route refusing another brand's row), idempotency, digest windows and non-repetition, preference precedence, Laravel interop both ways, and realtime payload minimalism.
- v1 deliberately omits webhook/push channels, quiet hours, timezone-aware windows, frequency caps and templates — all of them need a scheduler with timezone logic, and without operational data their design would be guessed.
