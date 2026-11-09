<?php

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('intent', LeadIntent::leadValues());
            $table->enum('stage', LeadStage::values())->default(LeadStage::Unknown->value);
            $table->string('language', 8);
            $table->string('summary_vi', 500);
            $table->date('arrival_date')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->enum('property_type', PropertyType::values())->default(PropertyType::Unknown->value);
            $table->unsignedTinyInteger('bedrooms_min')->nullable();
            $table->json('areas');
            $table->unsignedInteger('budget_min_usd')->nullable();
            $table->unsignedInteger('budget_max_usd')->nullable();
            $table->string('budget_raw', 100)->nullable();
            $table->string('household')->nullable();
            $table->json('requirements');
            $table->decimal('confidence', 3, 2);
            $table->unsignedTinyInteger('score')->default(0);
            $table->enum('status', LeadStatus::values())->default(LeadStatus::New->value);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index('status');
            $table->index('score');
            $table->index('stage');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
