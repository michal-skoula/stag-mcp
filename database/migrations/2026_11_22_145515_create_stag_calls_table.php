<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage telemetry: one row per call to a STAG web service, for latency and
     * error rate per endpoint. The path only, never the query string.
     */
    public function up(): void
    {
        Schema::create('stag_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool')->nullable();
            $table->string('method', 8);
            $table->string('endpoint');
            $table->unsignedSmallInteger('status')->nullable();
            $table->float('duration_ms');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['endpoint', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stag_calls');
    }
};
