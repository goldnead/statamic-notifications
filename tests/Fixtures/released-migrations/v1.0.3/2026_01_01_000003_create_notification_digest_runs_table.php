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

            $table->timestamps();

            // One send per recipient per frequency per window — the whole point.
            $table->unique(['brand_id', 'user_id', 'contact_uuid', 'frequency', 'window_start'], 'notif_digest_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_runs');
    }
};
