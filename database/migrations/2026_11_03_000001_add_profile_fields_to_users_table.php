<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados físicos, objetivo, nível, papel (usuário/admin) e status (ativo/bloqueado).
 * O peso não fica aqui: vem do último registro de evolução física.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('password');
            $table->enum('sex', ['male', 'female'])->nullable()->after('birth_date');
            $table->decimal('height_cm', 5, 1)->nullable()->after('sex');
            $table->enum('goal', ['hypertrophy', 'weight_loss', 'maintenance'])->nullable()->after('height_cm');
            $table->enum('experience_level', ['beginner', 'intermediate', 'advanced'])->nullable()->after('goal');
            $table->enum('role', ['user', 'admin'])->default('user')->after('experience_level');
            $table->enum('status', ['active', 'blocked'])->default('active')->index()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'sex', 'height_cm', 'goal', 'experience_level', 'role', 'status']);
        });
    }
};
