<?php

use App\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('area_slug', 64)->index();
            $table->enum('property_type', PropertyType::values());
            $table->unsignedTinyInteger('bedrooms')->default(0);
            $table->unsignedInteger('price_usd');
            $table->unsignedSmallInteger('min_term_months')->default(1);
            $table->boolean('pet_friendly')->default(false);
            $table->boolean('furnished')->default(true);
            $table->json('features')->nullable();
            $table->date('available_from')->nullable();
            $table->string('url', 1024)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
