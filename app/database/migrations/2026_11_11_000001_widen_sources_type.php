<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sources.type becomes a plain string so new source types (threads) can be
 * added without altering an enum each time. Allowed values are enforced by
 * App\Enums\SourceType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->enum('type', ['telegram', 'facebook', 'forum', 'reddit'])->change();
        });
    }
};
