<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationItem;
use Statamic\Facades\Role;
use Statamic\Facades\User;

require_once __DIR__.'/../Fakes/email-templates-snapshots.php';

/**
 * Was rausging, und was dabei nicht aufgezeichnet wird.
 *
 * Laeuft gegen den Platzhalter in tests/Fakes/email-templates-snapshots.php,
 * nicht gegen das echte Geschwister-Addon — das ist hier nicht installiert.
 * Belegt wird damit die Verdrahtung dieses Addons; dass die echte Klasse den
 * Aufruf annimmt, belegt der Playground.
 */
beforeEach(function (): void {
    Snapshots::reset();

    Notifications::registerType('community.mention', fn ($type) => $type
        ->label('Erwähnung')
        ->defaultChannels(['in_app', 'mail'])
        ->renderUsing(fn (NotificationItem $item) => [
            'message' => 'Bea hat dich in „Probenplan“ erwähnt',
            'link' => 'https://example.test/community/threads/7',
        ]));

    Notifications::registerType('community.digest_only', fn ($type) => $type
        ->label('Nur im Postfach')
        ->defaultChannels(['in_app']));
});

function superuser(): void
{
    $user = User::make()->email('cp@example.com')->makeSuper();
    $user->save();

    test()->actingAs($user);
}

it('zeichnet das Layout mit Platzhaltern auf, nicht die Post eines Empfängers', function (): void {
    Notifications::notify(
        new Identity(type: Identity::TYPE_USER, id: '1', userId: '1', email: 'chorleiter@example.test', name: 'Rita'),
        'community.mention',
        ['actor' => new Identity(type: Identity::TYPE_USER, id: '9', name: 'Bea Beispiel')],
    );

    expect(Snapshots::$rows)->toHaveCount(1);

    $snapshot = Snapshots::$rows[1];

    // Der Vertrag: das sendende Ding, nie der Empfänger.
    expect($snapshot->owner_type)->toBe('notifications:type');
    expect($snapshot->owner_id)->toBe('community.mention');

    // Die Vorlage trägt ihre Platzhalter.
    expect($snapshot->body)->toContain('{{ title }}');
    expect($snapshot->body)->toContain('{{ body }}');
    expect($snapshot->body)->toContain('{{ link }}');
    expect($snapshot->subject)->toBe('{{ title }}');

    // Und keinen einzigen Empfängertext. Das ist der Grund für die ganze
    // Bauart: was hier nicht steht, braucht keine Frist und kein Löschkonzept.
    expect($snapshot->body)->not->toContain('Bea hat dich');
    expect($snapshot->body)->not->toContain('Probenplan');
    expect($snapshot->body)->not->toContain('chorleiter@example.test');
    expect($snapshot->body)->not->toContain('Bea Beispiel');
    expect($snapshot->body)->not->toContain('community/threads/7');
    expect($snapshot->body)->not->toContain('Rita');
});

it('hält den Absender fest, unter dem die Mail wirklich rausging', function (): void {
    config()->set('mail.mailers.marke', ['transport' => 'array']);
    $this->enableMultiBrand();

    $marke = Brand::create(['handle' => 'nordlicht', 'name' => 'Nordlicht', 'settings' => ['mail' => [
        'from_address' => 'post@nordlicht.beispiel',
        'from_name' => 'Nordlicht Studio',
        'mailer' => 'marke',
    ]]]);

    BrandContext::runFor($marke, fn () => Notifications::notify(
        Identity::user(1)->withEmail('rita@example.test'),
        'community.mention',
    ));

    // Ohne diese Angabe trägt die Schicht die Vorschau-Adresse der Marke ein,
    // und die Zeile behauptet einen Absender, den kein Empfänger gesehen hat.
    expect(Snapshots::$rows[1]->sender_email)->toBe('post@nordlicht.beispiel')
        ->and(Snapshots::$rows[1]->sender_name)->toBe('Nordlicht Studio');
});

it('zählt zwei Versände derselben Art auf eine Zeile', function (): void {
    Notifications::notify(Identity::user(1)->withEmail('eins@example.test'), 'community.mention', ['dedupe_key' => 'a']);
    Notifications::notify(Identity::user(2)->withEmail('zwei@example.test'), 'community.mention', ['dedupe_key' => 'b']);

    expect(Snapshots::$rows)->toHaveCount(1);
    expect(Snapshots::$rows[1]->send_count)->toBe(2);

    // Beide Zeilen zeigen auf dieselbe Fassung.
    expect(NotificationItem::query()->pluck('email_template_snapshot_id')->all())->toBe([1, 1]);
});

it('setzt keinen Zeiger, wenn für die Zeile keine Mail rausging', function (): void {
    $item = Notifications::notify(Identity::user(1)->withEmail('nur-inapp@example.test'), 'community.digest_only');

    expect(Snapshots::$rows)->toBeEmpty();
    expect($item->fresh()->email_template_snapshot_id)->toBeNull();
});

it('zeigt auf der Detailseite die Mail, wie sie rausging', function (): void {
    superuser();

    $item = Notifications::notify(Identity::user(1)->withEmail('rita@example.test'), 'community.mention');

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mailPreviewShown', true)
            ->where('mailPreviewUrl', 'http://localhost/cp/notifications/'.$item->id.'/preview'));

    // Die Grenze wird überschritten: die Vorschau-Seite selbst wird geholt und
    // muss den Text dieser einen Zeile eingesetzt zeigen. HTTP 200 allein
    // belegt nichts.
    $antwort = $this->get('/cp/notifications/'.$item->id.'/preview')->assertOk();

    expect($antwort->getContent())->toContain('Bea hat dich in „Probenplan“ erwähnt');
    expect($antwort->getContent())->toContain('Erwähnung');
    expect($antwort->getContent())->not->toContain('{{ body }}');
});

it('sagt auf der Detailseite, dass keine Mail rausging', function (): void {
    superuser();

    $item = Notifications::notify(Identity::user(1)->withEmail('nur-inapp@example.test'), 'community.digest_only');

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mailPreviewShown', true)
            ->where('mailPreviewUrl', null));

    $this->get('/cp/notifications/'.$item->id.'/preview')->assertNotFound();
});

it('lässt das Mail-Feld weg, wenn die Snapshot-Schicht abgeschaltet ist', function (): void {
    superuser();

    $item = Notifications::notify(Identity::user(1)->withEmail('rita@example.test'), 'community.mention');

    Snapshots::$enabled = false;

    $this->get('/cp/notifications/'.$item->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('mailPreviewShown', false));
});

it('verweigert die Vorschau ohne das Leserecht', function (): void {
    $item = Notifications::notify(Identity::user(1)->withEmail('rita@example.test'), 'community.mention');

    Role::make('cp-only')->addPermission('access cp')->save();

    $user = User::make()->email('nobody@example.com')->assignRole('cp-only');
    $user->save();

    $this->actingAs($user)
        ->get('/cp/notifications/'.$item->id.'/preview')
        ->assertRedirect('/cp');
});
