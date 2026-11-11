<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cardio: além do tempo (duration_sec, já existente), a distância percorrida. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_sets', function (Blueprint $table) {
            $table->decimal('distance_km', 6, 2)->nullable()->after('duration_sec');
        });
    }

    public function down(): void
    {
        Schema::table('session_sets', fn (Blueprint $table) => $table->dropColumn('distance_km'));
    }
};
