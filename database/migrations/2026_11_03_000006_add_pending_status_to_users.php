<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro com aprovação: novas contas entram como "pending" (aguardando)
 * e só conseguem entrar depois que um administrador aprova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'blocked'])->default('active')->change();
        });
    }

    public function down(): void
    {
        // Contas ainda pendentes voltam como bloqueadas (o valor deixa de existir).
        \Illuminate\Support\Facades\DB::table('users')->where('status', 'pending')->update(['status' => 'blocked']);
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['active', 'blocked'])->default('active')->change();
        });
    }
};
