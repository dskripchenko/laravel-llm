<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_providers', function (Blueprint $t): void {
            // Send the call's attribution as headers (a gateway with accounting of its own).
            $t->boolean('attribution')->default(false);
            // Per model (or `*`), per million tokens: input, output.
            $t->json('prices')->nullable();
        });

        Schema::table('llm_calls', function (Blueprint $t): void {
            $t->string('on_behalf_of', 128)->nullable();
            $t->json('attribution')->nullable();
            $t->decimal('cost', 16, 8)->nullable();
            $t->index(['on_behalf_of', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('llm_calls', function (Blueprint $t): void {
            $t->dropIndex(['on_behalf_of', 'created_at']);
            $t->dropColumn(['on_behalf_of', 'attribution', 'cost']);
        });

        Schema::table('llm_providers', function (Blueprint $t): void {
            $t->dropColumn(['attribution', 'prices']);
        });
    }
};
