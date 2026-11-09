<?php

use App\Enums\SourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->enum('type', SourceType::values());
            $table->string('name');
            $table->string('identifier');
            $table->string('language_hint', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('rules_note')->nullable();
            $table->dateTime('last_item_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['type', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
