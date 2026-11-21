<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent to Pixelforge as the Idempotency-Key, so retries never create a second generation.
            $table->uuid('idempotency_key')->unique();
            // Pixelforge's "gen_..." id, known once the create request is accepted.
            $table->string('provider_id')->nullable()->unique();

            $table->string('status')->index();
            // While pending: why Pixelforge hasn't accepted it yet (e.g. concurrency_limit_exceeded).
            $table->string('last_submission_error')->nullable();

            // What the user asked for.
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // The estimate shown before submitting, in nanodollars.
            $table->unsignedBigInteger('estimated_min_cost_nanos');
            $table->unsignedBigInteger('estimated_max_cost_nanos');
            $table->unsignedBigInteger('estimated_min_charge_nanos');
            $table->unsignedBigInteger('estimated_max_charge_nanos');

            // What it actually cost, known once it finishes (0 when it failed).
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->json('rates')->nullable();
            $table->unsignedBigInteger('cost_nanos')->nullable();
            $table->unsignedBigInteger('charge_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            // Pixelforge bills on the UTC day a generation completed, so reports group by this.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
