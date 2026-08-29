# Changelog

## 1.8.0 — 2026-08-29

### Neu: die Zahlen dieses Addons erscheinen in Insights

`statamic-insights` ist ab 1.1.0 keine Umsatzauswertung mehr, sondern die Auswertungs-Schicht der
Familie: jedes Addon meldet an, was es zählen kann, und bekommt dafür Zeitraum, Vergleich mit dem
Vorzeitraum, Diagramm, Aufteilungen und zwei fertige Schirme.

Die Kopplung ist in **beide** Richtungen freiwillig. Ohne Insights fehlt hier nichts; ohne dieses
Addon fehlt dort nur seine Gruppe. `suggest`, nie `require`.

Jede Zahl hält sich an die Hausregeln des Vertrags: **null ist nicht null** (eine Quote ohne Nenner
hat keine Antwort und zeigt keine 0 %), `available()` entscheidet über die Existenz und nie über die
Daten, Lücken im Verlauf füllt Insights und nicht die Kennzahl, und ein Filter, den eine Zahl nicht
versteht, wird ignoriert statt zum Fehler.

Vier Zahlen: verschickt, gelesen, Leserate, Zusammenfassungen.

**Die Leserate ist nicht „Gelesen geteilt durch Verschickt".** In der Gelesen-Zahl stecken auch
ältere Meldungen, die erst in diesem Zeitraum geöffnet wurden; die Rate misst dagegen die Kohorte
des Zeitraums. Das steht in der Beschreibung, weil die zwei Kacheln nebeneinander sonst wie ein
Rechenfehler aussehen.

Die Zusammenfassungen rechnen auf einer zweiten Tabelle und erben die Marken-Bedingung mit.

### Behoben: eine Zahl zählt nur noch die aktive Marke

Beim Bauen der Anbindung bekam diese Frage in der Familie vier verschiedene Antworten, und auf einem
Schirm nebeneinander ist das schlimmer als gar keine: eine Kachel zeigte den Umsatz dreier fremder
Marken, während die daneben korrekt filterte. Die Regel steht jetzt einmal in
`TableMetric::brandScoped()`, als Abschrift von `BrandScope::apply()`; hier wird nur noch die Spalte
genannt, und Zahl, Diagramm und jede Aufteilung verengen gemeinsam.

Ist keine Marke gewählt, liest die Kachel **0 und bleibt stehen**. Ein Leser versteht eine Null;
eine verschwundene Kachel bemerkt er nicht.

## 1.7.0 — 2026-08-22

### Added — nur zeigen, was für diesen Menschen gelten kann

Eine frisch angemeldete Newsletter-Adresse ohne Community-Konto sah auf der
Selbstbedienungs-Seite vier Community-Zeilen und eine interne CRM-Zeile, jede
mit drei Kanälen: **fünfzehn Kästchen, von denen kein einziges je gewirkt
hätte.** Der Grund war einfach — die Matrix listete jede registrierte Art mal
jeden konfigurierten Kanal und fragte nie, ob das für den Betrachter überhaupt
in Frage kommt.

Eine Einstellung anzubieten, die nichts bewirken kann, ist schlimmer als keine:
sie sieht aus wie eine Wahl.

Zwei neue Angaben an einer Art, beide freiwillig und beide ohne Wirkung auf
bestehende Typen:

- **`appliesTo(Closure)`** — für wen die Art überhaupt in Frage kommt. Wer
  nein sagt, sieht sie gar nicht; keine ausgegraute Zeile, keine Erklärung.
  Eine Zeile, die nicht gelten kann, ist kein Hinweis, sondern Rauschen. Wirft
  die Prüfung, gilt die Art als nicht anwendbar — im Zweifel verbergen.
- **`supportedChannels(array)`** — welche Kanäle die Art überhaupt anbieten
  darf. Der Unterschied zu `defaultChannels()`: dort steht, was voreingestellt
  an ist, hier was zur Wahl steht.

### Changed — ein nicht unterstützter Kanal ist auch beim Versand zu

`allows()` prüft `supportedChannels` **vor** der `required`-Ausnahme. Sonst
ließen genau die Arten, die niemand abschalten darf, den einen Weg offen, der
nicht gemeint war — und es käme Post über einen Kanal, den auf der Seite
niemand wählen konnte.

## 1.6.0 — 2026-08-12
### Changed

- **The five sender-identity classes moved to `goldnead/statamic-brand-context` 1.8.0**, which is
  now required at `^1.8`. They were four byte-identical copies with four namespaces — this package,
  marketing, preference-center and automations each grew their own on 12.08.2026 — and copies drift:
  by the evening the marketing one had stopped refusing a transport without an address. Which
  address a brand sends under is a property of the brand, so the rule lives with the brand.

  Behaviour is unchanged here, down to the log lines. `Goldnead\Notifications\Contracts\SenderIdentityResolver`
  and `Sending\BrandMailer` stay as this package's own extension points, so a host can still answer
  "who does a notification come from" separately from "who does marketing post come from".
  `Sending\SenderIdentity` and `Sending\SaidRecently` are gone from this namespace; use the
  `Goldnead\BrandContext\Sending\` versions.

## 1.5.0 — 2026-08-12

### Fixed — every brand sent as whoever the process had sent as first

`MailChannel` and `notifications:send-digests` both ended in `Mail::to(...)->send(...)`. That is
the process-wide default mailer with the process-wide `mail.from`, identically for every brand.
A host serving several brands out of one queue worker — or one digest run walking its brand list —
therefore delivered the second brand's mail under the first brand's name and address, and a relay
that verifies sending domains per account (Scaleway TEM, Postmark, SES) answers that by refusing
the message or rewriting the From to whichever identity the shared account owns. Either way the
recipient hears from the wrong company, and nothing turns red.

**Who a notification goes out as is now resolved per message, from values, and never from config.**
`Contracts\SenderIdentityResolver` answers "which mailer, which From, which locale for brand N";
`Sending\BrandMailer` is the single door both send paths leave through, and it puts the answer on
the message (`Mailable::from()`, `Mail::mailer($name)`).

Not on the config, deliberately: Laravel reads `mail.from` the first time a mailer name is resolved,
burns it into that mailer instance via `alwaysFrom()`, and caches the instance in the `mail.manager`
singleton. A scoped `Config::set` therefore escapes its own `finally` — whichever brand sends first
leaves its address standing for the rest of the process. That is the same bug one layer down, and
it is why nothing here writes to `mail.*`.

### Fixed — the digest could burn a recipient's window without sending anything

`DigestBuilder::markSent()` stamps `digested_at` on every collected item *before* the mail leaves.
A brand that cannot produce a usable sender identity would have had each recipient's whole window
marked as delivered with nothing delivered, and the items would never have resurfaced. The check
now runs in front of the recipient loop: the brand is skipped, nothing is collected, nothing is
stamped, and everything stays pending until the settings are fixed.

### Changed — a brand that declares a broken mail identity sends nothing

Read from `brands.settings.mail`:

| key | meaning |
| --- | --- |
| `from_address` | required once `mail` is present at all |
| `from_name` | defaults to the brand name |
| `mailer` | a mailer from `config/mail.php` |
| `locale` | the language its mail is written in |

A brand that declares `mail` without `from_address`, or names a `mailer` that `config/mail.php`
does not define, now sends **nothing** and says so at error level (throttled per brand, so a
fan-out cannot bury it). The alternative was delivering under the host-wide From, which on a
multi-brand host is somebody else's identity — quietly.

**A single-brand install is unchanged, and that is covered by tests rather than intent.** So is a
multi-brand install whose brands carry no `settings.mail`: no brand, or no mail settings, resolves
to the config identity and the send is byte for byte the one it always was. A host that keeps
sender identities somewhere else rebinds `SenderIdentityResolver` in its own provider instead of
patching this package.

## 1.4.1 — 2026-08-09

### Fixed — the sibling constraint excluded the new majors

`goldnead/statamic-leadhub` was pinned to `^1.4` to the 1.x line. LeadHub 2.0.0 and Marketing 2.0.0 carry no code change over 1.12.2
and 1.13.0 — that major is the licence switch alone. A site running both this package and an
updated sibling could not resolve its dependencies at all. The constraints now accept both
lines.

## 1.4.0 — 2026-08-05

### Added — Wegweiser zu den Mail-Regeln in `statamic-automations`

Transaktionale Mail-Regeln („wenn ein Formular abgeschickt wird, sende die Dankesmail") werden im
Addon `goldnead/statamic-automations` konfiguriert. Gesucht werden sie zuerst hier, im Addon, das
Notifications heißt. Deshalb gibt es jetzt einen Nav-Eintrag **Benachrichtigungen → Mail-Regeln**,
der dorthin zeigt.

Der Eintrag erscheint nur, wenn zwei Bedingungen gelten (`Support\AutomationRules`): das Addon ist
installiert, **und** die installierte Version hat den Bildschirm auch (er kam mit automations 1.11).
Ohne die zweite Prüfung bekäme ein älterer Stand einen Nav-Eintrag auf einen 404 — schlechter als
gar keiner, weil er wie ein kaputtes Feature aussieht statt wie ein fehlendes.

**Ein Wegweiser, keine zweite Implementierung.** Dieses Addon bekommt keinen eigenen Weg von einem
Ereignis zu einer Mail. Könnten beide Addons das, hätte „warum kam diese Mail" zwei mögliche
Antworten und von außen keine Möglichkeit, sie zu unterscheiden. Ein Test hält das fest: er schlägt
fehl, sobald hier ein Event-Listener auftaucht.

## 1.3.0 — 2026-08-04
### Changed — the two Control Panel screens are Inertia pages with a real build

Both CP screens moved off Blade onto Vue single-file components rendered through
Inertia, the way core and the eight sibling addons do it. `resources/views/cp/`
is gone; `resources/js/pages/Index.vue` and `resources/js/pages/Show.vue` take
its place, registered in `resources/js/cp.js` and served by
`Inertia::render('notifications::Index')` and `…::Show`.

Nothing about the screens changed. Same columns in the same order, same three
filters, same sort default, same saved views and pagination, same detail fields,
same routes and route names. The listing endpoint at `notifications.listing` is
untouched — it was always the listing component's contract rather than a view
concern.

What the move buys, beyond ending the last Blade-shell exception in the family:

- **Inertia navigation between the two screens** instead of a full page load
  each way, and shared props.
- **The mustache hazard is gone.** While the pages were Blade, core compiled
  them into a Vue template, so a `{{ … }}` in a producer-supplied message was a
  compile error that silently stopped the screen from rendering. Every value had
  to be pushed into a static attribute to avoid it. Props are data and are never
  compiled; the rule and the gymnastics it forced both retire.
- **The detail page sends only what it shows.** `brand_id`, the recipient and
  actor type discriminators and anything a later migration adds no longer travel
  to the browser just because the model was handed over whole.
- **The way back to the index is in the command palette**, like every core
  page-level action.

The build is the standard one: `vite.config.js`, `package.json`,
`resources/css/cp.css` importing `@statamic/cms/tailwind.css`, and the `$vite`
property on the service provider — which is the only place Statamic 6 reads it
from; `extra.statamic.vite` in `composer.json` is kept in sync but is not
consulted. `resources/dist/build` is committed, because a Marketplace or
Composer install never runs npm, and `npm run build:check` plus a `build-check`
CI job fail if the committed bundle drifts from source.

Tests: 19 Vitest component tests for the two pages, and the PHP CP suite now
asserts the Inertia component name and props rather than rendered HTML. It also
pins that the addon's `notifications::cp.*` translation keys reach the Control
Panel's Javascript translator — without that registration the screens would
render raw keys and nothing else would fail.

`resources/views` stays published and `$viewNamespace` stays set: the mail
templates (`notifications::mail.notification`, `notifications::mail.digest`) are
Blade and remain so.

## 1.2.0 — 2026-08-01
### Fixed — the Control Panel was below the standard the rest of the package holds

`resources/views/cp/_styles.blade.php` is gone. Its header comment justified 112 lines of substitute
CSS with the claim that `mb-4`, `flex` and `gap-3` do not exist at runtime. All three ship in
`statamic/cms` v6.26.0's CP bundle. The class the views actually relied on is `.card`, and *that* one
has been hollowed out to nothing but `border-radius` in v6 — which is why every panel rendered as a
transparent box. A correct observation ("the screen looks wrong") led to a wrong diagnosis and then
to a hand-built HTML table propped up by unowned CSS.

Both screens are now core components. The listing is Statamic's own `<ui-listing>`, so it brings
search, sortable columns, saved views, column customisation, pagination and a native filter stack —
type, read state and recipient — instead of three text inputs and a submit button. The detail screen
is `<ui-header>`, `<ui-panel>`, `<ui-card>` and `<ui-table>`. No Vite build was introduced: core's
documented non-Inertia path compiles the yielded Blade into a Vue template, where every `<ui-*>`
component resolves.

One hazard comes with that path and is worth stating, because nothing looks wrong when it bites: the
page's Blade *is* a Vue template, so producer-supplied text containing a mustache would be a compile
error and the screen would simply not render. Every database value now goes into a static attribute
rather than element text, and a test holds that in place.

Also: the nav icon is `->icon('bell')` instead of 300 characters of inline SVG, `message`, `link`,
`actor` and `dedupe_key` are translated like every neighbouring label, and the CP test count went
from 5 to 18 — permissions per route, filters alone and combined, search, a sort whitelist that
rejects injected input, pagination, column visibility, and brand isolation for the listing as well
as the detail page.

### Fixed — config keys the code reads but the config file never shipped

`notifications.cp.enabled` and `notifications.sources.leadhub` were read by the service provider and
absent from `config/notifications.php`, so publishing the config gave you no way to switch either
off. Both are in the file now, with `NOTIFICATIONS_CP_ENABLED` and `NOTIFICATIONS_SOURCE_LEADHUB`.

### Changed — version constraints that were never installable

`laravel/framework` narrows from `^11.0|^12.0|^13.0` to `^12.0|^13.0` and `php` from `^8.2` to
`^8.3`. Neither is a reduction in what works: `statamic/cms ^6.0` requires `laravel/framework
^12.40 || ^13.0`, every Laravel 11 release up to v11.55.0 is covered by security advisories Composer
refuses to install, and Laravel 12.40+ requires PHP 8.3. `orchestra/testbench` follows to `^10|^11`.

### Changed — CI now runs what the README says matters

`phpunit.mysql.xml` shipped in every release and no workflow had ever executed it, while the README
called that run the thing standing between us and a repeat of v1.0.4's index defect. It is a job
now. The matrix also crosses PHP 8.3/8.4 with Laravel 12/13 and `prefer-lowest`/`prefer-stable`
instead of testing PHP alone, and Pint, PHPStan (level 5, baselined) and addon-lint are gates.

### Changed — the test bed uses Statamic's own harness

`tests/TestCase.php` extends `Statamic\Testing\AddonTestCase`. The hand-rolled Testbench setup had
no addon manifest, so `getAddon()` returned null and the provider's entire boot chain never ran;
`bootAddon()` and the CP routes were invoked by hand, and the CP tests therefore ran with plain
`web` middleware rather than the real CP stack. They now go through it, which is what surfaced that
a denied operator sees Statamic's redirect rather than a 403.


## 1.1.0 — 2026-07-30

### Added — this addon stops writing to mailboxes another addon already gave up on

Both paths that reach a mailbox — the immediate `MailChannel` and the weekly digest — now consult
`goldnead/statamic-suppression` before sending. New dependency at `^1.0`.

This is not a feature this addon asked for. It is the reason the suppression layer was built as its
own package instead of inside `statamic-marketing`, and if it were absent that separation would have
bought nothing.

**A hard bounce is a property of the mailbox, not of the relationship.** That sentence is what makes
a bounce global across brands rather than scoped to one, and it applies just as directly one level
up: it says nothing about which addon happens to be sending. An address marketing had already given
up on kept receiving assignment notifications and a weekly digest from this addon — the same
application, the same sending reputation, the same dead mailbox. A block that depends on which addon
is holding the pen is not a block.

### Added — the one thing a channel may decide for itself

`Contracts\Channel` says, and has always said, that channels never decide *whether* to deliver: the
preference resolver has done that before they are called. The gate is a deliberate exception to that
sentence, and the docblock now says so rather than leaving the contradiction to be discovered.

The reason it cannot go where it "belongs": `PreferenceResolver::allows()` returns `true`
unconditionally for a `required` type, before it reads anything stored. That is correct for
preferences — an outage notice should not be silenceable — and fatal for suppression. A legal block
that a notification type can declare itself exempt from is not a block. So the check sits in the
channel, immediately in front of the only line that reaches a mailbox.

The persisted row is still written either way. This decides how somebody is reached, never whether
the thing happened.

### Added — the digest gate sits before `markSent()`, and that placement is the feature

`markSent()` stamps `digested_at` on every collected item and writes the run row for the window. A
suppression check *after* it would burn the recipient's items: marked as digested, never delivered,
and never resurfaced if the suppression is later released. The check therefore runs before it, and
the test asserts the placement rather than the presence — it suppresses a recipient, runs the digest,
requires zero items to be stamped, then releases the suppression and requires the next run to deliver
them.

### Added — both paths fail closed

A gate that cannot answer withholds the mail. "The suppression list could not be read" is not
permission to write to a mailbox that may have complained, and a notification is never urgent enough
to be worth that trade.

`MailChannel` logs and returns rather than throwing, because
`NotificationManager::dispatchChannels()` swallows channel exceptions through `report()` — throwing
there would stop the send and leave nothing anybody reads. The digest reports and skips, leaving the
items pending for the run after the database recovers.

### Notes

- Suite: **114 passed (325 assertions)**, baseline 107. Seven new cases, all of them about an address
  that must not be written to.
- `MailChannel::__construct()` takes the gate as a second argument. `ChannelRegistry::resolve()` goes
  through `app()`, so nothing in the service provider or in a host's config needed changing.
- `notifications:send-digests` now reports a suppressed count alongside empty and already-sent.

## 1.0.7 — 2026-07-28

### Fixed — a migration was deleting rows, and a log line is not consent

`2026_07_28_000001_rebuild_notification_uniqueness_keys`, shipped in 1.0.4, resolved duplicate preference and digest-run rows by keeping the highest id of each group and deleting the rest. It reported that with a single `info()` line and carried on. That line went to the log, during `php artisan migrate`, after the rows were already gone.

**Affected: installs on SQLite or Postgres that were created under 1.0.3 or earlier, had contact recipients, and updated to 1.0.4, 1.0.5 or 1.0.6.** Nothing else. Two exclusions worth stating rather than leaving to be worked out:

- **No MySQL host can be affected.** The pre-1.0.4 `notification_preferences` table carries a five-column unique over four `varchar(255)` columns — 3212 bytes against InnoDB's 3072 — so on MySQL it could never be created. That is the failure 1.0.4 was released to fix. A MySQL host has never held a pre-1.0.4 preference row, so it has never had one to lose.
- **No install without contact recipients can be affected.** The duplicates in question are rows the old index permitted, and it permitted them only where `user_id` is NULL, which is the case for contacts and for nothing else. An install whose recipients are all users had nothing for the deletion to find.

**How to tell whether it happened to you.** The deletion left exactly one trace, and it is in the application log rather than in the database:

```
grep 'Removed .* duplicate row' storage/logs/laravel*.log
```

A line reading `[notifications] Removed 3 duplicate row(s) from notification_preferences that the previous unique index could not prevent.` is that migration, and the number is how many rows it removed. If your logs have rotated past the update, there is no other fingerprint: the rows are gone, nothing recorded what was in them, and the migration is recorded as having run successfully. **This is not recoverable.** What was lost is bounded and worth knowing: for `notification_preferences`, superseded opinions a contact had expressed about a type and channel, where the row that survived is the most recently created one; for `notification_digest_runs`, older records of a digest window already recorded as sent, where the surviving row still holds the send. Neither loses a current preference or causes an e-mail to be resent. Restore from a backup taken before the update if the historical rows matter to you.

**What changed.** The migration now stops. It reports every group that is in the way by its natural values — `brand_id`, `user_id`, `contact_uuid`, `type`, `channel` for preferences, and the frequency and window for digest runs — with the ids of the rows involved, and it changes nothing. Which of two preference rows is the opinion a person currently holds, and which digest run is the one the install will stand behind when somebody asks whether an e-mail went out, are questions about that install's history. A migration cannot answer them and should not try.

`php artisan notifications:uniqueness-integrity` prints the same groups in full, with ids and timestamps, and reads the indexes that are on the two tables right now rather than asking whether a migration ran — those are different statements, and treating them as one is how an install ends up believing a guarantee it does not have. `--repair` rebuilds the index alone once nothing is in the way, and refuses while anything is.

**And the abort no longer costs anything.** The duplicate check runs *before* the old unique comes off, so an install that stops here still has the constraint it started with. That is the shape that took `statamic-marketing` down through three releases: the statement that failed came after the statement that dropped, no engine rolls DDL back, and the install was left with no constraint at all and no record that anything had happened.

### Fixed — the migration could not pick itself back up

The 1.0.6 version returned as soon as it saw a `uniqueness_key` column. An interrupted run has already added that column, so on the retry it walked straight past the index it had never built and reported success. Every step now asks the schema what is actually there: the column is added only where missing, the backfill touches only rows with no key, and the index is built unless it is already in place over the right columns. Re-running it on a half-migrated install finishes the update.

### Changed — the migration no longer calls into two Eloquent models

It imported `NotificationPreference` and `NotificationDigestRun` for their static `uniquenessKeyFor()`, and in the digest case *instantiated* the model to normalise the window, which boots the `HasBrand` global scope and the saving hook inside a schema step. That looks like reuse and is a dependency in the wrong direction. A migration is a statement about one moment in a database's history and has to keep making it unchanged for as long as any install might still be behind it; a model is the current shape of the code and is meant to move. Rename either class and `migrate` stops at "Class not found" on every install that has not run this file yet, with nothing in the message pointing at the migration directory. Change the encoding — which `Support\UniquenessKey`'s own class doc contemplates, and which needs its own recompute migration when it happens — and this file silently starts writing the new format onto installs the recompute migration will never be told about, because as far as their migrations table is concerned they are up to date.

The encoding is now frozen into the migration at the shape it published. The cost of freezing is drift, so `tests/Unit/FrozenUniquenessKeyTest.php` requires the frozen copy to still agree with both models and with `UniquenessKey` itself, over nulls, empty strings and values containing the encoding's own separators. If they ever diverge, that is the moment somebody has to write the recompute migration, not the moment two formats quietly start coexisting.

### Added — the migrations are finally tested against a database with data in it

This is the actual finding. Not the deletion — the fact that no migration path in this addon had ever been run over anything but tables the test had created itself moments earlier, so the only code path that deletes anything had nowhere to be exercised. Three releases went out green without ever executing it once.

`tests/Migrations/` is a suite of its own, on a connection of its own, and it is in both `phpunit.xml` and `phpunit.mysql.xml`, because the two engines start from different schemas here and one run cannot speak for the other.

It does not name the migration that was wrong. It walks `database/migrations/` and runs the files one at a time, seeding every notifications table that exists before each one — so every migration in the addon meets rows written by an older schema, including migrations added long after this was written. `tests/Fixtures/released-migrations/` holds the migration sets as published in 1.0.3 and 1.0.6, and the suite installs each of them, puts data in and upgrades forward: notification items, preferences and digest runs across users and contacts, with the contact rows carrying the NULL `user_id` that the old index never constrained.

**The load-bearing case is the one that counts rows, not the one that checks the abort.** `it does not delete a row it was never told it could delete` installs 1.0.3, seeds it, adds the second contact preference the old schema permitted, runs the current migrations, and then requires the table to hold the same ids with the same values it held before — column by column, not merely the same count. Beside it, `it shows what the 1.0.6 migration did with the same rows` runs the identical setup through the 1.0.6 migration set exactly as published and measures the result: eight rows in, seven rows out. The claim in the section above is not asserted, it is demonstrated.

Every check is behavioural. "The migration ran" and "the constraint is there" are not the same statement. So nothing here asserts that `migrate` exited zero, or that an index of a given name exists. It writes the row the constraint is supposed to refuse and requires the database to refuse it.

### Notes

- Suite: **107 passed (309 assertions)** on SQLite, baseline 81. Green against MySQL 8.0 as well, through `phpunit.mysql.xml`, including the new `Migrations` suite.
- The five new checks were each verified to fail against the 1.0.6 migration before the fix went in, which is the only thing that makes them worth having.

## 1.0.6 — 2026-07-28

### Changed — the route parameter guard checks the rule, not a snapshot of the siblings

No defect in this addon, no route changed, and nothing in `src/` was touched. What changed is that 1.0.5's guard test was asserting something false.

That test carried a hand-written map of the names other installed packages bind application-wide, and it named `webhook`, `endpoint`, `rule` and `template` as claimed by `goldnead/statamic-webhook-manager` and `automation` as claimed by `goldnead/statamic-automations`. Webhook-manager renamed its four in its 1.7.0 and automations renamed its one in its 1.6.0. All five names are free. The entries were harmless — an entry for a name nobody binds matches nothing, which is why the suite stayed green — but a check that describes the world incorrectly is a check nobody can rely on, and correcting the five names would only have reset the clock on the same problem.

A snapshot of the siblings can only ever describe them as they are today. It says nothing about the addon that starts binding `{handle}` next month, which is exactly the case that hurts, and it has to be maintained by five repositories at once. What replaces it is the rule webhook-manager arrived at in its 1.7.0:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

That is a property of *this* package, so this package's own suite can enforce it without knowing anything about its neighbours.

`it binds only parameter names that belong to this addon` reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `notification` + a capital. This addon binds nothing at all today, so the rule costs it nothing, which is precisely why it is worth pinning now: the binding that hurts is never the one somebody weighed, it is the one added later because binding by the entity's obvious name looked like the obvious thing to do.

`it does not swallow a sibling addon's generic route parameter` is the behavioural half. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one answers with what it was given. They live in the bed rather than in the test body deliberately: a route added from inside a test body is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

Demonstrated rather than asserted: with a `Route::bind('handle', …)` added to a service provider in this family, the old three-test file stayed **green on all three**, while the new file fails three of its five and names `{handle}` in both directions — once as bound-but-not-ours, once as a sibling route answering 404 instead of its own value.

`1.0.5`'s first test is kept as it was: it pins that the CP bed mounts `SubstituteBindings`, without which no `Route::bind()` has any effect in tests and the whole file would pass for nothing. So is the check against `statamic/cms`, reduced to the ten CMS entity names it actually binds — that list is third-party, short and stable, and stays hand-kept for the same reason the sibling list could not.

**What deliberately did not change: `{id}`, this addon's only route parameter. It is as generic as a name gets and it is staying. Renaming it would move text without removing any exposure, because it is not bound — nothing resolves it, so nothing can collide. The rule above is what protects it.**

## 1.0.5 — 2026-07-28

### Added — the route parameter name is checked against the rest of the family

No defect in this addon, and no route changed. What is added is the check that would have caught one, and a pin on the property that made the check possible.

`Route::bind()` is registered on the router, not on a package. A binding one addon registers for `{rule}` or `{template}` applies to every route with that parameter name in every other addon installed beside it. Nothing warns, nothing logs, and the losing route does not fail loudly: it resolves its id against a repository that has never heard of it and returns 404. `goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository, and on the production hub, which has both, editing or deleting a scoring rule did nothing at all and said nothing at all, through a release.

**Why a green suite did not find it.** Two things have to hold before that failure is observable in an addon's own bed: the sibling addon has to be installed there, which it never is, and the bed has to mount the CP routes with `SubstituteBindings`, the middleware that applies a binding at all. LeadHub's bed had neither. This one mounts its CP routes through the `web` group, which already carries the middleware, so the second half was true here by inheritance rather than by decision — and nothing asserted it, so narrowing that group would have taken it away silently. It is now asserted: swap `middleware(['web'])` for `middleware([])` in `tests/TestCase.php` and the first case in the new `tests/Feature/RouteParameterCollisionTest.php` fails while the other 78 tests stay green.

The rest of that file reads this addon's parameter names out of `routes/cp.php` — string literals only, so example URLs in comments are not mistaken for routes — and checks them two ways: exactly, against a hand-maintained list of names that packages installed beside this one bind application-wide (`automation` from statamic-automations, `webhook` / `endpoint` / `rule` / `template` from statamic-webhook-manager, ten CMS entity names from statamic/cms), and then softly, by requiring every generic name to be recorded with a reason so that a *new* one has to be a decision.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand and will not catch an addon that starts binding a name nobody binds today — and `{id}`, this addon's only parameter, is exactly such a name. It is recorded as accepted rather than renamed: nothing binds it, the URL would be identical either way, and the honest statement is that the hub is where that answer is measurable. What the test buys is that the next `{rule}` fails in the addon that introduces it, before it reaches a hub.

## 1.0.4 — 2026-07-28

### Fixed — two tables could not be created on MySQL at all

`notification_preferences` and `notification_digest_runs` never existed on the production hub. Their migrations failed with *SQLSTATE 1071: Specified key was too long; max key length is 3072 bytes* and the whole notifications feature has been unusable there since it shipped, through four releases.

`notif_pref_unique` spanned `brand_id, user_id, contact_uuid, type, channel`. Under utf8mb4 every character costs four bytes, so each `varchar(255)` costs 1020 and the index came to 3212 — 140 bytes past what InnoDB will build.

**Why a green suite did not find it.** The suite runs on in-memory SQLite, and every mechanism in that paragraph is a MySQL mechanism. SQLite has no index length limit, stores no fixed column widths (it accepts `varchar(255)` and ignores the 255), and has no per-character byte cost to multiply. The migration was not passing the test — there was no test for it to pass, because the constraint it violates does not exist in the engine the tests use. The same blind spot covers every future index in this addon, so it is closed rather than worked around: see the coverage note below.

**Why the index was replaced rather than shortened.** A prefix index (`type(64)`) would have fit and would have been the smaller diff. It would also have declared two types that share their first 64 characters to be the same preference — swapping a migration that fails loudly for data that is quietly wrong. Narrowing the columns themselves is defensible for `type` and `channel`, which come from a registry this addon owns, but not for `user_id`: that is the host application's identifier, ours to store and not ours to cap.

Both tables now carry a `uniqueness_key` — a SHA-256 of the natural tuple, maintained by the model on every save — and the unique is `(brand_id, uniqueness_key)`, 264 bytes. Every byte of every column is still covered, no value is truncated, and `brand_id` stays a column of the index rather than an ingredient of the hash, so the tenant boundary remains legible in the schema and usable as a range. Tenant separation is unchanged in every other respect.

### Fixed — neither unique constrained contact recipients at all

Found while replacing the index, and the more serious of the two. A SQL unique does not constrain NULL, on any engine, and `user_id` is NULL for every contact recipient. So `notif_pref_unique` permitted a contact to accumulate any number of contradictory preferences for one type and channel, with the resolver reading whichever came back first — and `notif_digest_run_unique` permitted the same digest window to be recorded as sent twice, which is exactly the repetition that table was introduced to end.

Hashing turns the absence of a value into a definite one, so both constraints now cover the rows they were written for. `DigestBuilder::markSent()` looks a run up by the same key the index is built on, so the check and the constraint can no longer disagree.

### Changed — the digest-runs unique was one column from the same wall

It measured 2196 bytes of 3072 and would have been accepted by MySQL; it appeared in the failure report only because the run died at the preferences table before reaching it. Being under the limit by accident is what made the original design fragile, so the new test asserts headroom rather than mere survival: no index in this addon may use more than half the limit. The widest is now 1036 bytes.

### Migration

- **MySQL hosts** need nothing beyond `php artisan migrate`. Their original run was never recorded, so the corrected create-migrations execute normally and build the tables for the first time.
- **SQLite and Postgres hosts** ran the original migrations successfully, so editing those files reaches nothing there. `2026_07_28_000001_rebuild_notification_uniqueness_keys` adds the column, backfills it from existing rows and swaps the index. It is idempotent, and a no-op on a fresh install.
- That migration deletes duplicate rows the old index could not prevent, keeping the most recent per recipient — for a preference the last opinion the person expressed, for a digest run the one whose `sent_at` is closest to now. Deletions are reported in the log. There will be none unless contacts were in use.

### Notes

- **The suite now covers this class of defect without needing MySQL.** `IndexKeyLengthTest` compiles the addon's own migration files through Laravel's MySQL grammar in pretend mode — no server, no connection, nothing to install in CI — and measures every index the way InnoDB would. It reads the real migration files, so it cannot drift from them. Reverted against the 1.0.3 index it reports 3212 bytes and fails, which is the check that was missing.
- **The whole suite can now be run against a real MySQL server**: `vendor/bin/pest -c phpunit.mysql.xml`. Same tests, `DB_DRIVER=mysql`. Verified green against MySQL 8.4 as well as SQLite; the schema and upgrade tests were written to pass on both.
- Suite: **76 passed (217 assertions)** on SQLite, baseline 61.

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
