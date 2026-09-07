<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Zeiger auf die Vorlage, die diese Benachrichtigung als E-Mail verlassen
 * hat.
 *
 * Die Vorlage selbst liegt in `email_template_snapshots`, einer Tabelle in
 * `statamic-email-templates`. Dieses Addon legt keine zweite an: eine
 * Kampagne, eine Benachrichtigungsart und ein Automations-Knoten sind drei
 * Absender derselben Sache, und drei Kopien derselben Tabelle waeren drei
 * Aufbewahrungsfragen statt einer.
 *
 * Keine Fremdschluessel-Beziehung, mit Absicht: die Zieltabelle gehoert einem
 * optionalen Geschwister-Addon und muss auf einer Installation ohne dieses
 * Addon gar nicht existieren. Eine Spalte ohne Constraint ist hier die
 * ehrliche Abbildung — sie ist leer, solange niemand Snapshots hat.
 *
 * `null` heisst: fuer diese Zeile ist keine E-Mail rausgegangen. Das ist der
 * Normalfall fuer den `in_app`-Kanal, wo die gespeicherte Zeile selbst die
 * Zustellung ist, und fuer jeden Kurznachrichten-Kanal, den ein Host
 * registriert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_items', 'email_template_snapshot_id')) {
            return;
        }

        Schema::table('notification_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('email_template_snapshot_id')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notification_items', 'email_template_snapshot_id')) {
            return;
        }

        Schema::table('notification_items', function (Blueprint $table): void {
            $table->dropColumn('email_template_snapshot_id');
        });
    }
};
