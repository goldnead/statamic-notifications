<?php

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Preferences\PreferenceResolver;
use Goldnead\Notifications\Support\Settings;
use Statamic\Facades\User;

/**
 * Die Einstellungsseite gehoert nicht diesem Addon. Speicher, Formular,
 * Validierung und Seite liegen in `statamic-brand-context`; hier steht nur die
 * Erklaerung, welche Schluessel ein Betreiber anfassen darf.
 *
 * Geprueft wird deshalb genau das: dass die Anmeldung stattfindet, dass die
 * Namen stimmen — und dass ein geaenderter Wert **beim Leser ankommt**. Nicht
 * dass er in der Tabelle steht: eine Zeile in `brand_settings`, die keine
 * Codezeile je liest, ist kein Schalter, sondern ein Formular.
 */
beforeEach(function (): void {
    $user = User::make()->email('cp@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    // Das Formular schickt immer alle Felder eines Namensraums; ein
    // Teil-Datensatz ist eine 422 und kein stiller Teil-Schreibvorgang.
    $vollstaendig = function (array $overrides): array {
        $settings = [];

        foreach (array_keys(app(SettingsRegistry::class)->fields('notifications')) as $key) {
            $settings[$key] = config('notifications.'.$key);
        }

        return array_replace($settings, $overrides);
    };

    $this->patch = fn (array $settings) => $this->patchJson(cp_route('brand-context.settings.update'), [
        'namespace' => 'notifications',
        'settings' => $vollstaendig($settings),
    ]);
});

it('meldet sich bei der gemeinsamen Einstellungs-Schicht an', function (): void {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('notifications'))->toBeTrue('bootAddon() hat den Einstellungs-Anbieter nicht angemeldet')
        ->and($registry->provider('notifications'))->toBe(Settings::class)
        ->and($registry->configPath('notifications'))->toBe('notifications')
        ->and($registry->permission('notifications'))->toBe('manage notifications settings');
});

it('bietet keinen Schlüssel an, der beim Booten gelesen wird', function (): void {
    $keys = array_keys(app(SettingsRegistry::class)->fields('notifications'));

    // `cp.enabled` steht in der Nav-Registrierung, `sources.leadhub` in der
    // Quellen-Anmeldung — beides laeuft im Boot, also bevor die
    // Ueberschreibungen greifen. Ein Schalter, der erst beim naechsten Deploy
    // wirkt, ist eine Luege in der Oberflaeche.
    expect($keys)->not->toContain('cp.enabled')
        ->and($keys)->not->toContain('sources.leadhub')
        // Klassennamen und der Kanalname des Broadcast-Vertrags gehoeren
        // ebenfalls nicht dem Betreiber.
        ->and($keys)->not->toContain('channels')
        ->and($keys)->not->toContain('realtime.channel_prefix');
});

it('lässt einen geänderten Standard-Takt beim Leser ankommen', function (): void {
    expect(app(PreferenceResolver::class)->digestFrequency(Identity::user(1)))->toBe('weekly');

    ($this->patch)(['digest.default_frequency' => 'daily'])->assertRedirect();

    $row = BrandSetting::query()->where('key', 'digest.default_frequency')->first();

    expect($row?->namespace)->toBe('notifications')
        ->and($row?->brand_id)->not->toBeNull()
        // Der Leser, nicht die Config: `digestFrequency()` entscheidet, welchen
        // Lauf jemand bekommt, der nie selbst gewaehlt hat.
        ->and(app(PreferenceResolver::class)->digestFrequency(Identity::user(1)))->toBe('daily');
});

it('lässt eine geänderte Listenlänge beim Leser ankommen', function (): void {
    foreach (range(1, 5) as $n) {
        Notifications::notify(Identity::user(7), 'community.mention', ['dedupe_key' => 'k'.$n]);
    }

    expect(Notifications::forRecipient(Identity::user(7)))->toHaveCount(5);

    ($this->patch)(['list_limit' => 2])->assertRedirect();

    expect(Notifications::forRecipient(Identity::user(7)))->toHaveCount(2);
});

it('weist einen Takt ab, den kein Lauf bedient', function (): void {
    // `DigestBuilder::window()` kennt nur `daily` und `weekly`, und
    // `notifications:send-digests` weist alles andere ab.
    ($this->patch)(['digest.default_frequency' => 'monthly'])->assertStatus(422);
});

it('speichert eine leere Einstellungsseiten-Adresse als null', function (): void {
    ($this->patch)(['preferences_url' => ''])->assertRedirect();

    expect(config('notifications.preferences_url'))->toBeNull();
});
