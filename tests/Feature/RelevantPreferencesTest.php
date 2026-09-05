<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Preferences\PreferenceResolver;

/**
 * Nur zeigen, was gelten kann — und nur anbieten, was zugestellt wird.
 *
 * Beobachtet an adriangoldner.com: eine frisch angemeldete Newsletter-Adresse
 * ohne Community-Konto sah auf der Selbstbedienungs-Seite vier Community-Zeilen
 * und eine interne CRM-Zeile, jede mit drei Kanaelen. Fuenfzehn Kaestchen, von
 * denen kein einziges je gewirkt haette.
 *
 * Eine Einstellung anzubieten, die nichts bewirken kann, ist schlimmer als
 * keine: sie sieht aus wie eine Wahl.
 */
beforeEach(function (): void {
    $this->preferences = app(PreferenceResolver::class);
});

it('zeigt eine Art nicht, die fuer diesen Empfaenger nicht gilt', function (): void {
    Notifications::registerType('community.mention', function ($type): void {
        $type->label('Erwähnung')
            // Lose verglichen: userId kommt als Zeichenkette zurueck.
            ->appliesTo(fn (Identity $recipient) => (int) $recipient->userId === 1);
    });

    $matrix = collect($this->preferences->matrixFor(Identity::user(2)));

    expect($matrix->firstWhere('type', 'community.mention'))->toBeNull();
});

it('zeigt sie dem, fuer den sie gilt', function (): void {
    Notifications::registerType('community.mention', function ($type): void {
        $type->label('Erwähnung')
            // Lose verglichen: userId kommt als Zeichenkette zurueck.
            ->appliesTo(fn (Identity $recipient) => (int) $recipient->userId === 1);
    });

    $matrix = collect($this->preferences->matrixFor(Identity::user(1)));

    expect($matrix->firstWhere('type', 'community.mention'))->not->toBeNull();
});

it('zeigt eine Art ohne Angabe weiterhin allen', function (): void {
    // Die Vorgabe aendert an bestehenden Typen nichts — sonst waere aus einer
    // neuen Moeglichkeit ueber Nacht eine stille Abschaltung geworden.
    Notifications::registerType('account.security', function ($type): void {
        $type->label('Sicherheit');
    });

    $matrix = collect($this->preferences->matrixFor(Identity::user(99)));

    expect($matrix->firstWhere('type', 'account.security'))->not->toBeNull();
});

it('haelt eine kaputte Pruefung fuer ein Nein', function (): void {
    // Eine Pruefung, die wirft, darf keine Einstellung freischalten, die
    // niemand sehen soll. Im Zweifel verbergen, nicht zeigen.
    Notifications::registerType('community.mention', function ($type): void {
        $type->label('Erwähnung')
            ->appliesTo(function (): bool {
                throw new RuntimeException('kaputt');
            });
    });

    $matrix = collect($this->preferences->matrixFor(Identity::user(1)));

    expect($matrix->firstWhere('type', 'community.mention'))->toBeNull();
});

it('bietet nur die Kanaele an, die eine Art unterstuetzt', function (): void {
    /*
     * Eine Tagesuebersicht ergibt Sinn, wenn an einem Tag zehn Reaktionen
     * zusammenkommen. Fuer "dir wurde eine Aufgabe zugewiesen" ist sie sinnlos
     * — die Aufgabe waere dann einen Tag alt.
     */
    Notifications::registerType('crm.task_assigned', function ($type): void {
        $type->label('Aufgabe zugewiesen')
            ->supportedChannels(['mail', 'in_app']);
    });

    $zeile = collect($this->preferences->matrixFor(Identity::user(1)))
        ->firstWhere('type', 'crm.task_assigned');

    // Die Reihenfolge folgt der Konfiguration (in_app, mail, digest), nicht
    // der Aufzaehlung an der Art: die Seite soll ihre Spalten behalten.
    expect(array_keys($zeile['channels']))->toBe(['in_app', 'mail']);
});

it('verschickt auch nicht ueber einen Kanal, den die Art nicht unterstuetzt', function (): void {
    // Sonst kaeme Post ueber einen Weg, den auf der Seite niemand waehlen
    // konnte — die Anzeige allein zu filtern waere Kosmetik.
    Notifications::registerType('crm.task_assigned', function ($type): void {
        $type->label('Aufgabe zugewiesen')
            ->supportedChannels(['mail', 'in_app'])
            ->defaultChannels(['mail', 'in_app', 'digest']);
    });

    expect($this->preferences->allows(Identity::user(1), 'crm.task_assigned', 'digest'))->toBeFalse()
        ->and($this->preferences->allows(Identity::user(1), 'crm.task_assigned', 'mail'))->toBeTrue();
});

it('schliesst einen nicht unterstuetzten Kanal auch bei Pflicht-Arten', function (): void {
    // Vor der Pflicht-Ausnahme geprueft: sonst liessen genau die Arten, die
    // niemand abschalten darf, den einzigen Weg offen, der nicht gemeint war.
    Notifications::registerType('account.security', function ($type): void {
        $type->label('Sicherheit')
            ->required()
            ->supportedChannels(['mail']);
    });

    expect($this->preferences->allows(Identity::user(1), 'account.security', 'digest'))->toBeFalse()
        ->and($this->preferences->allows(Identity::user(1), 'account.security', 'mail'))->toBeTrue();
});
