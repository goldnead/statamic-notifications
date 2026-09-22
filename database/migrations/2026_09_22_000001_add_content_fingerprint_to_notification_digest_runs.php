<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the last digest that actually reached this person said.
 *
 * `uniqueness_key` next to it answers a different question — "was this window
 * already sent?" — and it cannot answer this one. Every window is new, so a
 * digest whose content has not changed since last week passes that check every
 * single time. That is how a weekly mail listing the same three open tasks kept
 * arriving: nothing about it was a repeat send, and everything about it was a
 * repeat.
 *
 * A SHA-256 over what the digest says, not over the rendered mail. Hashing the
 * HTML would tie the guarantee to the template: change a colour and every
 * recipient gets one more mail with nothing new in it.
 *
 * Nullable, and it stays null until a digest is really delivered. A run that was
 * recorded and then failed to send must not be able to swallow the next real
 * mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_digest_runs', 'content_fingerprint')) {
            return;
        }

        Schema::table('notification_digest_runs', function (Blueprint $table): void {
            $table->char('content_fingerprint', 64)->nullable()->after('uniqueness_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notification_digest_runs', 'content_fingerprint')) {
            return;
        }

        Schema::table('notification_digest_runs', function (Blueprint $table): void {
            $table->dropColumn('content_fingerprint');
        });
    }
};
