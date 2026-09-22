<?php

use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\RecipientDirectory;
use Goldnead\Notifications\Tests\MigrationPathTestCase;
use Goldnead\Notifications\Tests\SiblingsTestCase;
use Goldnead\Notifications\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(SiblingsTestCase::class)->in('Integration');

/**
 * Makes the digest command walk one fixed recipient.
 *
 * The default directory derives its list from pending notification items, so it
 * cannot reach anybody whose only content comes from a source — which is
 * exactly the install that reported the empty weekly digest. A host that wants
 * source-only digests binds its own directory; this is that host.
 */
function everyRunReaches(Identity $recipient): void
{
    app()->instance(RecipientDirectory::class, new class($recipient) implements RecipientDirectory
    {
        public function __construct(protected Identity $recipient) {}

        public function digestRecipients(string $frequency): iterable
        {
            return [$this->recipient];
        }
    });
}

// The migration path gets a bed of its own, on a connection of its own: these
// tests install an earlier release and migrate forward, which cannot happen
// inside the transaction RefreshDatabase holds open for everything else.
uses(MigrationPathTestCase::class)->in('Migrations');
