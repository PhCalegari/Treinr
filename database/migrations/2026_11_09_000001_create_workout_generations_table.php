<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treinos montados pela IA: o pedido da pessoa, a resposta (só o resultado e o consumo
 * de tokens) e, depois de salvo, o plano criado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'completed', 'failed', 'saved']);
            $table->json('request');
            $table->string('provider', 40);
            $table->string('model', 80)->nullable();
            $table->json('result')->nullable();
            $table->json('usage')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->uuid('plan_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_generations');
    }
};
