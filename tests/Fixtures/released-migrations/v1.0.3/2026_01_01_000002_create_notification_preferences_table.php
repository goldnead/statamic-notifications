<?php

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

            $table->string('type');
            $table->string('channel');
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
