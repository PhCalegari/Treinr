<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Erros recentes do servidor (lidos dos logs diários dos últimos 7 dias), agrupados por mensagem.
 * Só mostra a primeira linha de cada erro: nada de dados de pedido, senhas ou chaves.
 */
class ErrorLogController extends Controller
{
    public function __invoke(): Response
    {
        $groups = [];
        $files = collect(File::glob(storage_path('logs/laravel*.log')))
            ->filter(fn ($f) => filemtime($f) >= now()->subDays(7)->getTimestamp())
            ->sortDesc()
            ->take(8);

        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            if (! $handle) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                if (! preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:]{8})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): (.{0,400})/', $line, $m)) {
                    continue;
                }
                // Tira valores variáveis (ids, números) para juntar o mesmo erro.
                $message = $this->clean($m[3]);
                $key = $m[2].'|'.preg_replace('/\d+/', '#', $message);
                $at = $m[1];
                $groups[$key] ??= ['level' => strtolower($m[2]), 'message' => $message, 'count' => 0, 'first' => $at, 'last' => $at];
                $groups[$key]['count']++;
                $groups[$key]['first'] = min($groups[$key]['first'], $at);
                $groups[$key]['last'] = max($groups[$key]['last'], $at);
            }
            fclose($handle);
        }

        $list = collect($groups)->sortByDesc('last')->values()->take(100);

        return Inertia::render('admin/Errors', [
            'errors' => $list,
            'totals' => [
                'errors' => $list->where('level', '!=', 'warning')->sum('count'),
                'warnings' => $list->where('level', 'warning')->sum('count'),
                'last24h' => $list->filter(fn ($e) => $e['last'] >= now()->subDay()->format('Y-m-d H:i:s'))->sum('count'),
            ],
        ]);
    }

    /** Primeira parte da mensagem, sem o JSON de contexto e sem caminhos longos do servidor. */
    private function clean(string $message): string
    {
        $message = preg_replace('/ \{".*$/', '', $message);
        $message = str_replace(base_path().DIRECTORY_SEPARATOR, '', $message);

        return trim(mb_strimwidth($message, 0, 300, '…'));
    }
}
