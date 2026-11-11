<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App #2 (public apartment site, owner decision 2026-11-11): projects (e.g. Masteri)
 * and the public fields of a listing: slug, photos, VI/EN/RU texts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('area_slug', 64)->nullable()->index();
            // {"vi": "...", "en": "...", "ru": "..."}
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('slug', 150)->nullable()->unique()->after('title');
            $table->boolean('is_public')->default(false)->index()->after('is_active');
            $table->unsignedSmallInteger('size_m2')->nullable()->after('bedrooms');
            // Paths on the "public" disk, in display order.
            $table->json('photos')->nullable();
            // {"vi": {"title", "description", "policy"}, "en": {...}, "ru": {...}}
            $table->json('content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_public', 'size_m2', 'photos', 'content']);
        });

        Schema::dropIfExists('projects');
    }
};
