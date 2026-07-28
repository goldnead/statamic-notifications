<?php

/**
 * 1.0.3's preferences table, with `type` and `channel` narrowed to 64
 * characters. Not a release that ever existed.
 *
 * The published 1.0.3 file (in ../v1.0.3/) declares those two columns as
 * varchar(255) and puts a five-column unique across them, which comes to 3212
 * utf8mb4 bytes. InnoDB refuses anything past 3072, so on MySQL this table
 * could not be created at all — that failure *is* the incident 1.0.4 was
 * released to fix, and it has a consequence worth stating plainly: no MySQL
 * host can ever have held pre-1.0.4 preference rows, so no MySQL host can have
 * been affected by the follow-up migration deleting them.
 *
 * The guard that replaced that deletion is not engine-specific, and MySQL is
 * what hosts actually run. This variant exists so the guard can be exercised
 * there against the same data shape: identical in every respect the tests care
 * about, and different only in the one dimension that made the original
 * unbuildable.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recipient × type × channel, written only when someone deviates
 * from the type's default. Absence therefore means "use the default", which
 * keeps the table small and makes changing a default actually take effect for
 * everyone who never expressed an opinion.
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

            $table->string('type', 64);
            $table->string('channel', 64);
            $table->boolean('enabled');

            // Only meaningful for the digest channel: how often this recipient
            // wants to be collected up.
            $table->string('frequency')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'user_id', 'contact_uuid', 'type', 'channel'], 'notif_pref_unique');
            $table->index(['brand_id', 'user_id'], 'notif_pref_brand_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
