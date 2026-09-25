<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Sources\LeadHubSource;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * "Lead zugewiesen" und "Follow-up fällig" gehen an Leute im Team, nicht an
 * Kontakte. In ChoirLive (25.09.2026) hat jede Newsletter-Adresse ein Konto,
 * und das Preference Center zeigte ihr beide Arten. Die Grenze ist deshalb
 * nicht "hat ein Konto", sondern "darf LeadHub sehen".
 */
beforeEach(function (): void {
    LeadHubSource::registerTypes(app('notifications'));

    $this->rows = fn (Identity $recipient) => collect(app(PreferenceResolver::class)->matrixFor($recipient))
        ->pluck('type')
        ->intersect(['crm.lead_assigned', 'crm.followup_due'])
        ->values()
        ->all();
});

it('hides the internal lead types from a subscriber with an account', function (): void {
    $user = User::make()->email('mara@chor.test');
    $user->save();

    expect(($this->rows)(Identity::user($user->id(), 'mara@chor.test')))->toBe([]);
});

it('hides them from a contact without an account', function (): void {
    expect(($this->rows)(Identity::contact('c-1', 'kontakt@example.com')))->toBe([]);
});

it('shows them to someone who may view LeadHub', function (): void {
    Role::make('crm')->addPermission('access cp')->addPermission('view leadhub')->save();

    $user = User::make()->email('team@example.com')->assignRole('crm');
    $user->save();

    expect(($this->rows)(Identity::user($user->id(), 'team@example.com')))
        ->toBe(['crm.lead_assigned', 'crm.followup_due']);
});

it('shows them to a super user', function (): void {
    $user = User::make()->email('chef@example.com')->makeSuper();
    $user->save();

    expect(($this->rows)(Identity::user($user->id(), 'chef@example.com')))
        ->toBe(['crm.lead_assigned', 'crm.followup_due']);
});
