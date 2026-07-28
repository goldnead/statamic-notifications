<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_items`, not `notifications`: the latter is Laravel's own table
 * for the database notification channel. Any host application that later enables
 * that channel would collide with us, and a foundation addon must never be the
 * reason a framework feature stops working.
 *
 * Nor do we build ON that table. It has no brand column (isolation would have to
 * hide inside the JSON payload — exactly what brand-context exists to prevent),
 * no dedupe key, and identifies people by notifiable_type/id rather than by the
 * identity the rest of the platform shares.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brand_id')->index();

            $table->string('type')->index();

            // Who it is for. Identity fields rather than a polymorphic relation:
            // the recipient may be a user today and a contact tomorrow, and a
            // notification must survive the record it was addressed to.
            $table->string('recipient_type')->nullable();
            $table->string('recipient_id')->nullable();
            $table->string('user_id')->nullable();
            $table->uuid('contact_uuid')->nullable();
            $table->string('email')->nullable();

            // Who caused it, when there is a person behind it at all.
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->text('message')->nullable();
            $table->string('link')->nullable();
            $table->json('data')->nullable();

            // The same fact must not notify twice, however many producers see it.
            $table->string('dedupe_key')->nullable();

            $table->timestamp('read_at')->nullable();

            // Set once this item has been included in a digest, so the next run
            // cannot pick it up again. The existing community digest lacks this
            // and therefore repeats itself every week.
            $table->timestamp('digested_at')->nullable();

            $table->timestamps();

            // Short names throughout: MySQL caps identifiers at 64 characters.
            $table->unique(['brand_id', 'dedupe_key'], 'notif_brand_dedupe_unique');
            $table->index(['brand_id', 'user_id', 'read_at'], 'notif_brand_user_read_idx');
            $table->index(['brand_id', 'contact_uuid', 'read_at'], 'notif_brand_contact_read_idx');
            $table->index(['brand_id', 'type', 'created_at'], 'notif_brand_type_time_idx');
            $table->index(['brand_id', 'digested_at'], 'notif_brand_digested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_items');
    }
};
