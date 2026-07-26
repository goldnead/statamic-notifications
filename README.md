# Statamic Notifications

Persisted, brand-scoped notifications: types, per-type preferences, in-app,
immediate mail, and digests that do not repeat themselves.

## Why it exists

This pattern gets reinvented. In this family it happened three times: a runtime
aggregation over community tables, a CRM addon's own mail notifier with its own
digest command, and Laravel's built-in system used purely as a mail sender. None
of them could be reused by the next domain that needed notifying.

## Install

```bash
composer require goldnead/statamic-notifications
php artisan migrate
php artisan vendor:publish --tag=notifications-config
```

Requires `goldnead/statamic-brand-context` and
`goldnead/statamic-identity-contracts`; both behave inertly in a single-brand
application.

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

Read-only inspector at **Tools → Notifications**: filter by type, user and
unread; open one to see its data, dedupe key, read and digest state. It answers
"did this person get it?", which is the question support actually asks.

Permissions: `view notifications`, `manage notification digests`.

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

## License

MIT
