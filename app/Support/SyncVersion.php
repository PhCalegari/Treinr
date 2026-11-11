<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Contador global da sincronização. Cada alteração em um dado sincronizável
 * recebe o próximo número; o aparelho pede "tudo com versão > X".
 */
class SyncVersion
{
    public static function next(): int
    {
        return DB::transaction(function () {
            $current = (int) DB::table('sync_counter')->where('id', 1)->lockForUpdate()->value('value');
            $next = $current + 1;
            DB::table('sync_counter')->where('id', 1)->update(['value' => $next]);

            return $next;
        });
    }
}
