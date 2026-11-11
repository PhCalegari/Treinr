<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca de exercícios (dados do servidor; o app guarda uma cópia local para uso offline).
 * Fonte inicial: Free Exercise DB (Unlicense). Ver docs/fontes-de-dados.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Os 11 grupos musculares exibidos no app.
        Schema::create('muscle_groups', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_pt', 60);
            $table->unsignedSmallInteger('position');
        });

        // Músculos detalhados (como vêm da base externa). Sem grupo = ex.: pescoço.
        Schema::create('muscles', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_pt', 60);
            $table->unsignedSmallInteger('muscle_group_id')->nullable();
            $table->foreign('muscle_group_id')->references('id')->on('muscle_groups');
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_pt', 60);
        });

        Schema::create('exercise_categories', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_pt', 60);
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('name_pt', 150)->nullable()->index();
            $table->unsignedSmallInteger('category_id');
            $table->enum('level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->enum('mechanic', ['compound', 'isolation'])->nullable();
            $table->enum('force_type', ['push', 'pull', 'static'])->nullable();
            $table->text('instructions')->nullable();
            $table->text('instructions_pt')->nullable();
            $table->unsignedTinyInteger('suggested_sets')->nullable();
            $table->unsignedTinyInteger('suggested_reps_min')->nullable();
            $table->unsignedTinyInteger('suggested_reps_max')->nullable();
            $table->unsignedSmallInteger('suggested_duration_sec')->nullable();
            $table->enum('source', ['free_exercise_db', 'wger', 'custom']);
            $table->string('external_id', 100)->nullable();
            $table->string('license', 40)->nullable();
            // NULL = biblioteca oficial; preenchido = exercício próprio do usuário.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('exercise_categories');
            $table->unique(['source', 'external_id']);
            $table->index('updated_at');
        });

        Schema::create('exercise_muscles', function (Blueprint $table) {
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('muscle_id');
            $table->enum('role', ['primary', 'secondary']);
            $table->primary(['exercise_id', 'muscle_id']);
            $table->foreign('muscle_id')->references('id')->on('muscles');
            $table->index(['muscle_id', 'role']);
        });

        Schema::create('exercise_equipment', function (Blueprint $table) {
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('equipment_id');
            $table->primary(['exercise_id', 'equipment_id']);
            $table->foreign('equipment_id')->references('id')->on('equipment');
        });

        Schema::create('exercise_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->enum('media_type', ['image', 'video']);
            $table->string('url', 500);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('attribution')->nullable();
            $table->index(['exercise_id', 'position']);
        });

        Schema::create('measurement_types', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_pt', 60);
            $table->unsignedSmallInteger('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_types');
        Schema::dropIfExists('exercise_media');
        Schema::dropIfExists('exercise_equipment');
        Schema::dropIfExists('exercise_muscles');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('exercise_categories');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('muscles');
        Schema::dropIfExists('muscle_groups');
    }
};
