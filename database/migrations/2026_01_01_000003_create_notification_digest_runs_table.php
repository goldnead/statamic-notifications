<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of what was actually sent to whom, and for which window.
 *
 * This is the piece the existing community digest is missing: without it there
 * is no way to answer "did this person already get these items?", so the same
 * unread items go out again every week. With it, a digest run is idempotent —
 * re-running the command for a window that was already sent does nothing.
 *
 * That guarantee rests on `uniqueness_key` rather than on a unique across the
 * natural columns, for the reasons set out in the preferences migration. This
 * table's original unique was the narrower of the two — 2196 bytes against
 * InnoDB's 3072 — so MySQL would have accepted it, and it appeared in the
 * failure report only because the run stopped at the preferences table before
 * reaching it. That is the more instructive half of the incident: it was one
 * `varchar` column away from the same wall, and nothing in the schema said so.
 *
 * It also had the NULL hole in full: `user_id` is NULL for every contact
 * recipient, a unique does not constrain NULL, and so the one guarantee this
 * table exists to provide never held for contacts. The same window could be
 * recorded as sent twice — precisely the repetition the table was added to end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digest_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brand_id')->index();

            $table->string('user_id')->nullable();
            $table->uuid('contact_uuid')->nullable();
            $table->string('email')->nullable();

            $table->string('frequency');

            $table->timestamp('window_start');
            $table->timestamp('window_end');

            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('sent_at')->nullable();

            // SHA-256 of (user_id, contact_uuid, frequency, window_start),
            // maintained by the model on every save.
            $table->char('uniqueness_key', 64)->default('');

            $table->timestamps();

            // One send per recipient per frequency per window — the whole point.
            $table->unique(['brand_id', 'uniqueness_key'], 'notif_digest_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_runs');
    }
};
