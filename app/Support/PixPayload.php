<?php

namespace App\Support;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * Código PIX "copia e cola" (BR Code estático, padrão EMV do Banco Central).
 * Sem valor fixo: quem paga escolhe quanto quer contribuir.
 *
 * Campos: 00 versão · 26 conta PIX (GUI + chave + descrição) · 52 categoria · 53 moeda (986 = R$)
 * · 54 valor (opcional) · 58 país · 59 nome · 60 cidade · 62 txid · 63 CRC16.
 */
final class PixPayload
{
    public static function make(string $key, string $name, string $city, ?string $description = null, ?float $amount = null): string
    {
        $account = self::field('00', 'br.gov.bcb.pix')
            .self::field('01', trim($key))
            .($description ? self::field('02', self::clean($description, 40)) : '');

        $payload = self::field('00', '01')
            .self::field('26', $account)
            .self::field('52', '0000')
            .self::field('53', '986')
            .($amount ? self::field('54', number_format($amount, 2, '.', '')) : '')
            .self::field('58', 'BR')
            .self::field('59', self::clean($name, 25))
            .self::field('60', self::clean($city, 15))
            .self::field('62', self::field('05', '***'))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /** QR code em SVG para exibir na tela. */
    public static function svg(string $payload, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($payload);
    }

    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** Nome e cidade sem acentos (alguns bancos recusam), no tamanho máximo do padrão. */
    private static function clean(string $value, int $max): string
    {
        return substr(trim(preg_replace('/[^A-Za-z0-9 .\-]/', '', Str::ascii($value))), 0, $max);
    }

    /** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em hexadecimal maiúsculo. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
