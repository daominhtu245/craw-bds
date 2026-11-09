<?php

use App\Enums\ActivityType;
use App\Enums\KeywordKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables from SPEC 5.4, plus collector_heartbeats (not in SPEC 5;
 * persists collector heartbeats from SPEC 6.2 / 13 across restarts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ActivityType::values());
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('reply_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->enum('variant', ['public_reply', 'dm']);
            $table->string('language', 8);
            $table->text('body');
            $table->json('listing_ids')->nullable();
            $table->string('model', 64)->nullable();
            $table->string('created_by', 32)->default('system');
            $table->timestamps();
        });

        Schema::create('lead_listing_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('match_score');
            $table->timestamps();

            $table->unique(['lead_id', 'listing_id']);
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->string('language', 8);
            $table->enum('kind', KeywordKind::values());
            $table->string('term');
            $table->unsignedTinyInteger('weight')->default(1);
            $table->boolean('is_regex')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['language', 'kind', 'term']);
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name_en');
            $table->string('name_vi');
            $table->json('aliases');
            $table->timestamps();
        });

        Schema::create('known_agents', function (Blueprint $table) {
            $table->id();
            $table->char('author_key', 64)->unique();
            $table->string('reason')->nullable();
            $table->string('created_by', 32)->default('system');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('collector_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('collector', 32)->unique();
            $table->boolean('ok')->default(true);
            $table->dateTime('last_seen_at');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['collector_heartbeats', 'settings', 'known_agents', 'areas', 'keywords',
            'lead_listing_matches', 'reply_drafts', 'lead_activities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
