<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medidas caseiras dos alimentos ("1 concha = 140 g").
 * - ibge: Tabela de Medidas Referidas do IBGE (POF 2008-2009), ligada à TACO
 * - label: porção informada no rótulo (Open Food Facts)
 * - custom: medida criada pelo usuário (para uso futuro)
 *
 * O item do diário guarda a medida usada, só para exibição ("2 × concha");
 * os cálculos sempre usam a quantidade em gramas/ml.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_portions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('label', 60);
            $table->decimal('grams', 7, 1);
            $table->enum('source', ['ibge', 'label', 'custom']);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['food_id', 'label']);
        });

        Schema::table('meal_items', function (Blueprint $table) {
            $table->string('portion_label', 60)->nullable()->after('unit');
            $table->decimal('portion_count', 5, 2)->nullable()->after('portion_label');
        });
    }

    public function down(): void
    {
        Schema::table('meal_items', function (Blueprint $table) {
            $table->dropColumn(['portion_label', 'portion_count']);
        });
        Schema::dropIfExists('food_portions');
    }
};
