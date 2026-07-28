<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recipient × type × channel, written only when someone deviates
 * from the type's default. Absence therefore means "use the default", which
 * keeps the table small and makes changing a default actually take effect for
 * everyone who never expressed an opinion.
 *
 * The "one row per" above is enforced through `uniqueness_key`, not through a
 * unique across the four natural columns. That unique was the original
 * definition and it never survived a MySQL install: four utf8mb4 `varchar`
 * columns come to 3212 bytes and InnoDB refuses anything past 3072
 * (SQLSTATE 1071). It is replaced rather than shortened, because every
 * shortening on offer was worse than the failure:
 *
 * - A prefix index (`type(64)`) fits, but two types sharing 64 characters
 *   would count as one preference. The migration would stop failing and the
 *   data would start lying.
 * - Narrowing the columns themselves is honest for `type` and `channel`,
 *   which come from a registry we own, but not for `user_id`: that is the
 *   host application's identifier, ours to store and not ours to cap.
 *
 * Hashing indexes all four columns whole, at 64 characters. It also closes a
 * hole the original unique had on every driver: SQL uniques ignore NULL, and
 * `user_id` is NULL for every contact recipient — so the constraint that was
 * supposed to keep one preference per contact permitted unlimited duplicates.
 * See Goldnead\Notifications\Support\UniquenessKey.
 *
 * `brand_id` stays a real column in the index instead of joining the hash:
 * the tenant boundary must be legible in the schema and usable as a range,
 * not dissolved into a digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brand_id')->index();

            $table->string('user_id')->nullable();
            $table->uuid('contact_uuid')->nullable();

            $table->string('type');
            $table->string('channel');
            $table->boolean('enabled');

            // Only meaningful for the digest channel: how often this recipient
            // wants to be collected up.
            $table->string('frequency')->nullable();

            // SHA-256 of (user_id, contact_uuid, type, channel), maintained by
            // the model on every save. Defaulted rather than nullable so a row
            // written around the model still collides loudly instead of
            // slipping past the constraint the way a NULL would.
            $table->char('uniqueness_key', 64)->default('');

            $table->timestamps();

            // Short names throughout: MySQL caps identifiers at 64 characters.
            $table->unique(['brand_id', 'uniqueness_key'], 'notif_pref_unique');
            $table->index(['brand_id', 'user_id'], 'notif_pref_brand_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
