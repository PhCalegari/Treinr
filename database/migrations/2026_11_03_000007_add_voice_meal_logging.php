<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de refeição por voz/texto ("comi arroz e feijão, uma colher de cada"):
 * a análise guarda a origem (foto ou texto) e o texto; os itens guardam a medida caseira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_photo_analyses', function (Blueprint $table) {
            $table->enum('input_type', ['photo', 'text'])->default('photo')->after('status');
            $table->string('input_text', 500)->nullable()->after('input_type');
        });
        Schema::table('meal_photo_items', function (Blueprint $table) {
            $table->string('portion_label', 60)->nullable()->after('estimated_grams');
            $table->decimal('portion_count', 5, 2)->nullable()->after('portion_label');
        });
        Schema::table('meal_items', function (Blueprint $table) {
            $table->enum('origin', ['manual', 'search', 'barcode', 'photo', 'voice'])->change();
        });
    }

    public function down(): void
    {
        DB::table('meal_items')->where('origin', 'voice')->update(['origin' => 'manual']);
        Schema::table('meal_items', function (Blueprint $table) {
            $table->enum('origin', ['manual', 'search', 'barcode', 'photo'])->change();
        });
        Schema::table('meal_photo_items', fn (Blueprint $t) => $t->dropColumn(['portion_label', 'portion_count']));
        Schema::table('meal_photo_analyses', fn (Blueprint $t) => $t->dropColumn(['input_type', 'input_text']));
    }
};
