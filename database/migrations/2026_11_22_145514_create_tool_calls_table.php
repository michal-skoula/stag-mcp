<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage telemetry: one row per MCP tool call. Holds the user id and the tool
     * name only. No arguments, no results, no names or osCislo.
     */
    public function up(): void
    {
        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');
            $table->boolean('failed')->default(false);
            $table->float('duration_ms');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['tool', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_calls');
    }
};
