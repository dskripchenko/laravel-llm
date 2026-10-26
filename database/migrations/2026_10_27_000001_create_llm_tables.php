<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_providers', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('base_url');
            $t->text('api_key')->nullable();
            $t->json('models')->nullable();
            $t->json('limits')->nullable();
            $t->boolean('is_cloud')->default(false);
            $t->boolean('cloud_enabled')->default(false);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        Schema::create('llm_routes', function (Blueprint $t): void {
            $t->id();
            $t->string('purpose', 32);
            $t->string('scenario')->nullable();
            $t->foreignId('provider_id')->constrained('llm_providers')->cascadeOnDelete();
            $t->string('model');
            $t->foreignId('fallback_route_id')->nullable()->constrained('llm_routes')->nullOnDelete();
            $t->json('options')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['purpose', 'scenario', 'is_active']);
        });

        Schema::create('llm_prompt_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('key');
            $t->unsignedInteger('version');
            $t->text('system')->nullable();
            $t->text('user');
            $t->json('schema')->nullable();
            $t->boolean('is_active')->default(false);
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['key', 'version']);
        });

        Schema::create('llm_calls', function (Blueprint $t): void {
            $t->id();
            $t->string('scenario');
            $t->string('purpose', 32);
            $t->string('prompt_key')->nullable();
            $t->unsignedInteger('prompt_version')->nullable();
            $t->foreignId('provider_id')->nullable()->constrained('llm_providers')->nullOnDelete();
            $t->string('provider');
            $t->string('model');
            $t->unsignedInteger('attempt')->default(1);
            $t->unsignedInteger('prompt_tokens')->default(0);
            $t->unsignedInteger('completion_tokens')->default(0);
            $t->unsignedInteger('total_tokens')->default(0);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->string('status', 32);
            $t->text('error')->nullable();
            // Strings, so that integer, UUID and ULID keys all fit.
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->index(['subject_type', 'subject_id']);
            $t->string('tenant_key')->nullable();
            $t->string('project_key')->nullable();
            $t->string('idempotency_key')->nullable()->index();
            $t->longText('request')->nullable();
            $t->longText('response')->nullable();
            $t->json('result')->nullable();
            $t->timestamp('bodies_pruned_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_key', 'created_at']);
            $t->index(['project_key', 'created_at']);
            $t->index(['bodies_pruned_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
        Schema::dropIfExists('llm_prompt_templates');
        Schema::dropIfExists('llm_routes');
        Schema::dropIfExists('llm_providers');
    }
};
