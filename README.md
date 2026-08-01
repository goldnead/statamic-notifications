<!-- statamic:hide -->
# Statamic Notifications

> Persisted, brand-scoped notifications: types, per-type preferences, in-app,
> immediate mail, and digests that do not repeat themselves.
<!-- /statamic:hide -->

## Why it exists

This pattern gets reinvented. In this family it happened three times: a runtime
aggregation over community tables, a CRM addon's own mail notifier with its own
digest command, and Laravel's built-in system used purely as a mail sender. None
of them could be reused by the next domain that needed notifying.

## Requirements

| | |
| --- | --- |
| Statamic | 6.0+ |
| Laravel | 12.40+ or 13 |
| PHP | 8.3+ |
| Database | MySQL 8, MariaDB 10.6 or SQLite. The tables are Eloquent, not flat files. |
| Queue | not required. Mail is sent inline; digests run from your scheduler. |

Also requires `goldnead/statamic-brand-context`,
`goldnead/statamic-identity-contracts` and `goldnead/statamic-suppression`. All
three behave inertly in a single-brand application.

## Installation

```bash
composer require goldnead/statamic-notifications
php artisan migrate
php artisan vendor:publish --tag=notifications-config
```

The Control Panel screen appears under **Tools → Notifications** for anyone
holding the `view notifications` permission.

### Publish tags

| Tag | What it copies |
| --- | --- |
| `notifications-config` | `config/notifications.php` |
| `notifications-translations` | `lang/vendor/notifications` (`de`, `en`) |
| `notifications-migrations` | the four migrations, if you want to own them |
| `notifications-views` | `resources/views/vendor/notifications` — mail templates and the two CP views |

## Usage

```php
use Goldnead\Notifications\Facades\Notifications;

Notifications::notify($user, 'community.mention', [
    'message' => 'Bea hat dich erwähnt.',
    'link' => '/account/community/posts/'.$post->id,
    'dedupe_key' => 'mention:'.$mention->id,
]);

Notifications::unreadCount($user);
Notifications::markRead($item);
```

That is the whole minimum: a recipient, a type handle, and a payload. Everything
below is the detail — types, preferences, channels and digests.

## Notifying

```php
use Goldnead\Notifications\Facades\Notifications;

Notifications::notify($user, 'community.mention', [
    'actor' => $author,               // anything IdentityContext can resolve
    'subject' => $post,               // any Eloquent model
    'message' => 'Bea hat dich erwähnt.',
    'link' => '/account/community/posts/'.$post->id,
    'dedupe_key' => 'mention:'.$mention->id,
]);

Notifications::notifyMany($subscribers, 'lms.lesson_published', [
    'dedupe_key' => 'lesson:'.$lesson->id,   // scoped per recipient automatically
]);
```

**A recipient must be identifiable.** Notifying an anonymous visitor returns
null — there would be no way to ever show it to them again.

**Notifying is idempotent** when you pass a `dedupe_key`: the same fact reaching
two producers yields one notification. **Notifying never breaks the caller**: a
mail transport error must not roll back the comment that caused it.

## Types

A type says what a notification is called, which channels it uses by default,
and how it renders:

```php
Notifications::registerType('community.mention', function ($type) {
    $type->label('Erwähnung')
        ->defaultChannels(['in_app', 'mail'])
        ->renderUsing(fn ($item) => [
            'message' => $item->actor_name.' hat dich erwähnt.',
            'link' => '/account/community/posts/'.$item->subject_id,
        ]);
});
```

Rendering is a callback, not a template: the host owns the wording and the URL
structure. The addon never hardcodes a sentence or a route — that is exactly
what made the system it replaces impossible to extract.

Unregistered types still deliver (in-app, using whatever the producer passed), so
a missing registration never silently swallows someone's notification.

**Register types in a service provider, not in the calling code.** The registry
lives per process. A type registered ad hoc — inside a controller, a console
one-off — is unknown to the scheduled digest process, falls back to the `in_app`
default and is silently skipped there. The notification exists and is never
summarised. That skipping is deliberate (it is what stops an immediate e-mail
being repeated days later), which is precisely why the registration has to be
global.

`->required()` makes a type ignore preferences. For account security and legal
notices only.

## Preferences

Per type × channel, stored **only as deviations**. Absence means "use the type's
default", so changing a default actually reaches everyone who never expressed an
opinion.

```php
$preferences = app(PreferenceResolver::class);
$preferences->set($user, 'community.mention', 'mail', false);
$preferences->matrixFor($user);   // for a preference centre
```

Note the asymmetry: **the persisted row is always written**, because it is the
record that this happened. Preferences govern how someone is *reached* — turning
off `in_app` silences the realtime nudge, it does not erase history.

## Channels

| Channel | Behaviour |
| --- | --- |
| `in_app` | the row itself, plus an optional realtime nudge |
| `mail` | one e-mail per notification, rendered through the type |
| `digest` | no-op at notify time; the item waits for the next digest run |

Register your own with `Notifications::registerChannel()`.

## Digests

```bash
php artisan notifications:send-digests --frequency=weekly [--dry-run] [--now=…]
```

Two things this does that the system it replaces did not:

- **A window.** Daily covers 24 hours, weekly covers 7 days. The old digest took
  "everything currently unread", which is unbounded and unrelated to the period
  being reported.
- **A record of the send.** `notification_digest_runs` is unique on
  (brand, recipient, frequency, window start), and every collected item is
  stamped `digested_at`. Without this an unread item went out **again every
  week** for as long as it stayed unread.

Scheduling is left to the host — register the command in your own scheduler so
the send window matches your audience.

## Checking the uniqueness constraints

```bash
php artisan notifications:uniqueness-integrity [--repair]
```

`php artisan migrate` reporting success means the migrations ran. It does not
mean the constraints they were supposed to leave behind are in place, and it
says nothing at all about the rows. This reads the indexes that are on
`notification_preferences` and `notification_digest_runs` right now, and the
rows that are in them, and says plainly whether one recipient still means one
row per key. It changes nothing.

You will be pointed at it by `migrate` itself. Installs created before 1.0.4
could hold duplicate rows for contact recipients, because the unique of the day
led with `user_id` and no engine constrains a NULL. Where those rows exist the
migration stops and names them rather than choosing between them: which of two
preferences is the one a person currently holds is not a decision a schema
change gets to make. Delete the rows that are not the ones to keep, then run
`migrate` again. `--repair` rebuilds the index alone once nothing is in the way,
and refuses while anything is.

### Digest sources

Other addons contribute things nobody was notified about:

```php
Notifications::registerSource('community', CommunityDigestSource::class);
```

A source answers "what should this person also see for this window?" — open
follow-ups, upcoming events. A failing source is reported and skipped: one
addon's broken query must not silence everybody's weekly mail.

A LeadHub source ships bundled and attaches only when that addon is installed.

## Realtime

Off by default. When enabled, a **content-free** refresh signal broadcasts on
`users.{id}`; the client re-fetches through the normal authorised endpoint, so a
socket subscriber can never see more than the API would have given them.

```php
'realtime' => ['enabled' => true, 'channel_prefix' => 'users'],
```

## Laravel interop

This addon does **not** build on Laravel's `notifications` table. That schema has
no brand column (isolation would have to hide inside the JSON payload — exactly
what brand-context exists to prevent), no dedupe key, and identifies people by
`notifiable_type/id` rather than by the identity the rest of the platform shares.
The table here is `notification_items`, so enabling Laravel's database channel
later still works.

Existing `$user->notify()` call sites route in through a channel:

```php
public function via($notifiable): array { return ['notifications']; }

public function toNotifications($notifiable): array
{
    return ['type' => 'crm.lead_assigned', 'message' => '…', 'link' => '…'];
}
```

## Control Panel

Read-only inspector at **Tools → Notifications**. It is Statamic's own listing
component, so it behaves like the Entries screen: search, sortable columns,
saved views, column customisation, pagination, and three filters — type, read
state, and recipient (which matches on user id, e-mail or contact uuid, because
support knows the person and not the column). Open a row to see its message,
link, actor, dedupe key, payload, and read and digest state.

It answers "did this person get it?", which is the question support actually
asks. Nothing on the screen writes.

Permissions: `view notifications`, `manage notification digests`. Both CP routes
authorise server-side; a user without the permission is redirected out of the
screen. Set `notifications.cp.enabled` to `false` to remove it entirely.

## Configuration

Every key in `config/notifications.php`, with its default:

| Key | Default | Effect when wrong |
| --- | --- | --- |
| `enabled` | `true` | Off means `notify()` writes nothing and sends nothing. |
| `channels` | `in_app`, `mail`, `digest` | A handle removed here can no longer be named by a type or a preference; existing preferences referencing it are ignored. |
| `digest.default_frequency` | `weekly` | Applies to anyone who never chose one. A value your scheduler never calls means those people get no digest at all. |
| `realtime.enabled` | `false` | On without a working broadcaster throws on every notify. |
| `realtime.channel_prefix` | `users` | Must match what your client subscribes to, or the nudge never arrives. |
| `list_limit` | `30` | Cap for `list()`/`unreadCount()` in your own front end. Not used by the CP. |
| `cp.enabled` | `true` | Off removes the inspector, its nav item and its routes. |
| `sources.leadhub` | `true` | Off keeps the bundled LeadHub digest source out even when the addon is installed. |
| `preferences_url` | `null` | Null means digest mails print no link to a preference centre. The addon ships no such page; the host owns it. |

## Multi-site

Notifications are **not** scoped per Statamic site. They are scoped per *brand*
through `goldnead/statamic-brand-context`: every row carries a `brand_id`, and a
global scope on the model means an operator in brand A cannot read brand B's
rows even by guessing an id. In a single-brand installation — which is what a
plain multi-site Statamic is — everything lands in one brand and the scoping is
invisible.

## Not in v1

Webhook and push channels, quiet hours, timezone-aware send windows, frequency
caps, notification templates. All of them need a scheduler with timezone logic,
and without real operational data their design would be guessed rather than
derived.

## Tests

```bash
composer install && vendor/bin/pest
```

The Integration suite exercises the bundled LeadHub source against the real
addon and skips itself when it is not installed.

The default run uses in-memory SQLite. The same suite runs against a real MySQL
server too, and CI runs it that way on every push — it is a job, not a
release-day ritual:

```bash
vendor/bin/pest -c phpunit.mysql.xml
```

SQLite is not a substitute for it. It has no index length limit, no fixed column
widths and no per-character byte cost, so a schema that MySQL refuses outright
can pass a fully green SQLite run — which is how v1.0.4's defect reached
production. `tests/Unit/IndexKeyLengthTest.php` closes that particular gap
without needing a server: it compiles the migrations through Laravel's MySQL
grammar and measures every index against InnoDB's 3072-byte limit.

## Support · Changelog · License

Issues and questions: <https://github.com/goldnead/statamic-notifications/issues>.
Best effort, no response-time promise. Report anything security-relevant
privately to <info@adriangoldner.com> rather than in a public issue.

Release notes: [CHANGELOG.md](CHANGELOG.md).

MIT.
