<?php

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('parent_external_id', 191)->nullable();
            $table->string('url', 1024);
            $table->string('author_handle', 191)->nullable();
            // Nullable (SPEC deviation): items without an identifiable author must not share one key.
            $table->char('author_key', 64)->nullable();
            $table->mediumText('text');
            $table->text('context_text')->nullable();
            $table->char('content_hash', 64);
            $table->string('language', 8)->nullable();
            $table->dateTime('posted_at');
            $table->enum('status', RawItemStatus::values())->default(RawItemStatus::New->value);
            $table->enum('filter_reason', FilterReason::values())->nullable();
            $table->json('classification')->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->unsignedInteger('ai_input_tokens')->nullable();
            $table->unsignedInteger('ai_output_tokens')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index('content_hash');
            $table->index('author_key');
            $table->index('status');
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_items');
    }
};
