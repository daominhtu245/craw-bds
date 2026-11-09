<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * keywords.kind becomes a plain string so the keyword classifier kinds
 * (intent_*, stage_*) can be added without altering an enum each time.
 * Allowed values are enforced by App\Enums\KeywordKind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->enum('kind', ['positive', 'negative', 'area'])->change();
        });
    }
};
