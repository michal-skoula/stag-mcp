<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Suggested answers read from STAG sit apart from what the user confirmed,
     * so a guess can never be mistaken for a preference. One row per user, with
     * a JSON object per side keyed by onboarding question.
     */
    public function up(): void
    {
        Schema::create('onboarding_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->json('suggestions')->nullable();
            $table->json('confirmed')->nullable();
            $table->string('failure')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_suggestions');
    }
};
