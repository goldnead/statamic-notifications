<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per notification item × channel that owes a deferred send.
 *
 * `notifyDeferred()` writes the item and these rows in one transaction; the
 * sends happen later, when a bounded worker (`notifications:dispatch-pending`)
 * claims rows. The row is the unit of retry, backoff and audit: it records what
 * was attempted, how often it failed, and how it ended — never *why* in the
 * caller's words. `outcome_code` carries a fixed vocabulary only; no exception
 * text and no address lives here, because this table is meant to be read long
 * after the failure it describes.
 *
 * No `brand_id` of its own: a delivery has exactly one brand, the one on its
 * item, and duplicating the column would give two places that can disagree.
 * The worker derives the brand from the row's item and scopes every send to
 * it, rather than to whatever brand happens to be ambient in its process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('notification_item_id');

            $table->string('channel');

            // pending | retry | accepted | skipped | cancelled | failed. A
            // string rather than an enum: three engines, one portable schema.
            // pending/retry are the only claimable states; the other four are
            // terminal and are never reset, by retries or by repeated facts.
            $table->string('status')->default('pending');

            // Failed attempts only. A row accepted on its first try keeps 0;
            // every failed attempt raises it by one, and the row goes failed
            // once it reaches the configured maximum.
            $table->unsignedTinyInteger('attempts')->default(0);

            // When a worker may claim this row again: now for a fresh row,
            // now + backoff after a failed attempt. Meaningless once terminal.
            $table->timestamp('available_at')->nullable();

            // Set when — and only when — the channel reported acceptance.
            $table->timestamp('accepted_at')->nullable();

            $table->string('outcome_code', 64)->nullable();

            $table->timestamps();

            $table->foreign('notification_item_id')
                ->references('id')
                ->on('notification_items')
                ->cascadeOnDelete();

            // Short names throughout: MySQL caps identifiers at 64 characters.
            $table->unique(['notification_item_id', 'channel'], 'notif_deliv_item_channel_uniq');
            $table->index(['status', 'available_at'], 'notif_deliv_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
