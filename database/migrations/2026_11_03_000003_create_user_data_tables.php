<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dados do usuário que funcionam OFFLINE e sincronizam (treinos, sessões, diário,
 * metas, evolução), mais o catálogo de alimentos e a análise de fotos (só online).
 *
 * Toda tabela sincronizável tem:
 *   id            UUID gerado no aparelho (permite criar offline sem colisão)
 *   user_id       dono do registro (filtra e autoriza a sincronização)
 *   modified_at   quando o usuário alterou, pelo relógio do aparelho (em conflito, vale o mais recente)
 *   deleted_at    exclusão lógica, para a exclusão chegar a todos os aparelhos
 *   sync_version  contador do servidor: o app pede "mudanças desde a versão X"
 * Essas tabelas evitam UNIQUE de negócio (ex.: posição), que podem colidir
 * temporariamente com edições offline em mais de um aparelho.
 */
return new class extends Migration
{
    /** Colunas comuns das tabelas sincronizáveis. */
    private function syncColumns(Blueprint $table): void
    {
        $table->timestamp('created_at', 6)->useCurrent();
        $table->timestamp('modified_at', 6);
        $table->timestamp('deleted_at', 6)->nullable();
        $table->unsignedBigInteger('sync_version');
        $table->index(['user_id', 'sync_version']);
    }

    public function up(): void
    {
        // Contador global de versões da sincronização (uma linha só).
        Schema::create('sync_counter', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('value');
        });
        DB::table('sync_counter')->insert(['id' => 1, 'value' => 0]);

        // ---------------- Alimentos (catálogo/cache, só online) ----------------
        // Valores por 100 g (ou 100 ml).
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['open_food_facts', 'usda', 'custom']);
            $table->string('external_code', 64)->nullable();   // código de barras (OFF) ou fdcId (USDA)
            $table->string('name')->index();
            $table->string('brand')->nullable();
            $table->enum('unit', ['g', 'ml'])->default('g');
            $table->decimal('kcal_per_100', 7, 2);
            $table->decimal('protein_per_100', 6, 2);
            $table->decimal('carbs_per_100', 6, 2);
            $table->decimal('fat_per_100', 6, 2);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_code']);
        });

        // Análise de foto por IA: o que a IA estimou, separado do que o usuário confirmou.
        Schema::create('meal_photo_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'completed', 'failed', 'confirmed', 'discarded']);
            $table->string('provider', 40);
            $table->string('model', 80)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->json('raw_response')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('meal_photo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('meal_photo_analyses')->cascadeOnDelete();
            $table->string('detected_name');
            $table->decimal('estimated_grams', 7, 1);
            $table->decimal('confidence', 3, 2)->nullable();
            $table->decimal('est_kcal', 7, 2)->nullable();
            $table->decimal('est_protein_g', 6, 2)->nullable();
            $table->decimal('est_carbs_g', 6, 2)->nullable();
            $table->decimal('est_fat_g', 6, 2)->nullable();
            $table->foreignId('matched_food_id')->nullable()->constrained('foods')->nullOnDelete();
        });

        // ---------------- Treinos (planejamento) ----------------
        Schema::create('workout_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('split_type', ['a', 'ab', 'abc', 'abcd', 'abcde', 'custom']);
            $table->boolean('active')->default(true);
            $table->string('notes', 1000)->nullable();
            $this->syncColumns($table);
        });

        Schema::create('workout_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('workout_plans')->cascadeOnDelete();
            $table->string('label', 10);          // "A", "B"...
            $table->string('name', 120)->nullable(); // "Peito e tríceps"
            $table->unsignedSmallInteger('position');
            $this->syncColumns($table);
            $table->index(['plan_id', 'position']);
        });

        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_day_id')->constrained('workout_days')->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained('exercises');
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('sets');
            $table->unsignedTinyInteger('reps_min')->nullable();
            $table->unsignedTinyInteger('reps_max')->nullable();
            $table->decimal('target_load_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->string('notes', 500)->nullable();
            $this->syncColumns($table);
            $table->index(['workout_day_id', 'position']);
        });

        // ---------------- Sessões de treino (execução real) ----------------
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workout_day_id')->nullable()->constrained('workout_days')->nullOnDelete();
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->string('notes', 1000)->nullable();
            $this->syncColumns($table);
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('session_exercises', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained('exercises');
            $table->foreignUuid('workout_exercise_id')->nullable()->constrained('workout_exercises')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->boolean('completed')->default(false);
            $this->syncColumns($table);
            $table->index(['session_id', 'position']);
            $table->index(['user_id', 'exercise_id']);
        });

        Schema::create('session_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_exercise_id')->constrained('session_exercises')->cascadeOnDelete();
            $table->unsignedTinyInteger('set_number');
            $table->unsignedSmallInteger('reps')->nullable();
            $table->decimal('load_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('duration_sec')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at', 6)->nullable();
            $this->syncColumns($table);
            $table->index(['session_exercise_id', 'set_number']);
        });

        // ---------------- Diário alimentar ----------------
        // Uma refeição por usuário/dia/tipo, com id DETERMINÍSTICO (UUID v5 de
        // usuário+data+tipo) calculado igual no app e no servidor: dois aparelhos
        // offline criando o "almoço de hoje" chegam ao mesmo registro.
        Schema::create('meals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('meal_date');
            $table->enum('meal_type', ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner', 'supper']);
            $this->syncColumns($table);
            $table->unique(['user_id', 'meal_date', 'meal_type']);
        });

        // Valores nutricionais copiados no momento do registro: mudar o catálogo não altera o passado.
        Schema::create('meal_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('meal_id')->constrained('meals')->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->string('food_name');
            $table->decimal('quantity', 7, 1);
            $table->enum('unit', ['g', 'ml'])->default('g');
            $table->decimal('kcal', 7, 2);
            $table->decimal('protein_g', 6, 2);
            $table->decimal('carbs_g', 6, 2);
            $table->decimal('fat_g', 6, 2);
            $table->enum('origin', ['manual', 'search', 'barcode', 'photo']);
            $table->foreignId('photo_analysis_id')->nullable()->constrained('meal_photo_analyses')->nullOnDelete();
            $this->syncColumns($table);
            $table->index('meal_id');
        });

        // ---------------- Metas nutricionais ----------------
        // Histórico: a vigente é a de maior valid_from <= data (empate: modified_at mais recente).
        Schema::create('nutrition_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->unsignedSmallInteger('kcal');
            $table->unsignedSmallInteger('protein_g');
            $table->unsignedSmallInteger('carbs_g');
            $table->unsignedSmallInteger('fat_g');
            $table->unsignedSmallInteger('bmr_estimate')->nullable();
            $table->unsignedSmallInteger('tdee_estimate')->nullable();
            $table->decimal('activity_factor', 3, 2)->nullable();
            $table->enum('formula', ['mifflin_st_jeor', 'katch_mcardle'])->nullable();
            $table->boolean('manual_override')->default(false);
            $this->syncColumns($table);
            $table->index(['user_id', 'valid_from']);
        });

        // ---------------- Evolução física ----------------
        Schema::create('progress_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('body_fat_pct', 4, 1)->nullable();
            $table->string('notes', 500)->nullable();
            $this->syncColumns($table);
            $table->index(['user_id', 'entry_date']);
        });

        Schema::create('progress_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entry_id')->constrained('progress_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('measurement_type_id');
            $table->decimal('value_cm', 5, 1);
            $this->syncColumns($table);
            $table->foreign('measurement_type_id')->references('id')->on('measurement_types');
        });

        // A foto fica primeiro no aparelho; file_path só é preenchido depois do upload.
        Schema::create('progress_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entry_id')->constrained('progress_entries')->cascadeOnDelete();
            $table->enum('angle', ['front', 'side', 'back', 'other'])->default('front');
            $table->string('file_path', 500)->nullable();
            $this->syncColumns($table);
        });
    }

    public function down(): void
    {
        foreach ([
            'progress_photos', 'progress_measurements', 'progress_entries', 'nutrition_goals',
            'meal_items', 'meals', 'session_sets', 'session_exercises', 'training_sessions',
            'workout_exercises', 'workout_days', 'workout_plans', 'meal_photo_items',
            'meal_photo_analyses', 'foods', 'sync_counter',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
