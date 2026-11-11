<?php

namespace App\Http\Controllers;

use App\Support\PixPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** "Apoie o desenvolvedor": código PIX copia e cola + QR code (SVG gerado aqui). */
class SupportController extends Controller
{
    public function pix(): JsonResponse
    {
        $cfg = config('services.pix');
        abort_unless(filled($cfg['key']), 404);

        $payload = PixPayload::make($cfg['key'], $cfg['name'], $cfg['city'], $cfg['description']);

        return response()->json([
            'payload' => $payload,
            'name' => $cfg['name'],
            // O QR muda só se a configuração mudar.
            'qr_svg' => Cache::rememberForever('pix-qr:'.md5($payload), fn () => PixPayload::svg($payload)),
        ])->header('Cache-Control', 'private, max-age=3600');
    }
}
