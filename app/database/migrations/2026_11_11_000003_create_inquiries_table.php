<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests sent by customers through the public site / Telegram Mini App (App #2).
 *
 * name and contact are written by the customer, with consent, and stored
 * encrypted (Laravel "encrypted" cast, APP_KEY). They are erased after
 * leadradar.site.contact_retention_days (DataRetention). Owner decision 2026-11-11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);            // website | telegram_miniapp
            $table->string('locale', 5);
            $table->text('name')->nullable();         // encrypted
            $table->string('contact_channel', 20);    // phone | zalo | whatsapp | telegram | email
            $table->text('contact')->nullable();      // encrypted
            $table->date('arrival_date')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->unsignedInteger('budget_usd')->nullable();
            $table->text('message')->nullable();      // phone/email masked
            $table->string('ref', 32)->nullable();    // tracking code from a reply draft link
            $table->char('ip_hash', 64);
            $table->timestamp('consent_at');
            $table->timestamp('contact_purged_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
