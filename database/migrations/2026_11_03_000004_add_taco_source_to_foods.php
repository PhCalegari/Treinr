<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alimentos genéricos/caseiros da TACO (NEPA-UNICAMP): nova fonte e categoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->enum('source', ['open_food_facts', 'taco', 'usda', 'custom'])->change();
            $table->string('category', 80)->nullable()->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        // Remove os alimentos da TACO antes de voltar o enum.
        DB::table('foods')->where('source', 'taco')->delete();
        Schema::table('foods', function (Blueprint $table) {
            $table->enum('source', ['open_food_facts', 'usda', 'custom'])->change();
        });
    }
};
