<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            // Signed nanodollars: positive adds credit, negative takes it away.
            $table->bigInteger('amount_nanos');
            $table->string('description')->nullable();
            $table->timestamps();

            // A generation can have at most one hold, one release and one charge.
            // This is the last line of defence against recording a charge twice.
            // (NULL generation_ids, e.g. purchases, never collide.)
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
