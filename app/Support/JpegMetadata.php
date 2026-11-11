<?php

namespace App\Support;

/**
 * Remove metadados de um JPEG sem recodificar a imagem.
 *
 * Um JPEG é uma sequência de segmentos (marcador 0xFF + tipo + tamanho). Os segmentos
 * APP1 (EXIF/XMP, onde fica a localização GPS e o modelo do aparelho), APP13 (IPTC)
 * e COM (comentários) são descartados. JFIF (APP0), perfil de cor (APP2) e Adobe
 * (APP14, necessário para decodificar alguns arquivos) são mantidos.
 */
final class JpegMetadata
{
    private const DROP = [0xE1, 0xED, 0xFE]; // APP1, APP13, COM

    /** @return string|null null se não for um JPEG válido */
    public static function strip(string $bytes): ?string
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($bytes);

        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                return null; // estrutura inesperada
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) { // preenchimento
                $pos++;

                continue;
            }
            // Início dos dados da imagem (SOS): o restante vai inteiro.
            if ($marker === 0xDA) {
                return $out.substr($bytes, $pos);
            }
            // Marcadores sem tamanho (RSTn, TEM).
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= substr($bytes, $pos, 2);
                $pos += 2;

                continue;
            }
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($size < 2 || $pos + 2 + $size > $len) {
                return null;
            }
            if (! in_array($marker, self::DROP, true)) {
                $out .= substr($bytes, $pos, 2 + $size);
            }
            $pos += 2 + $size;
        }

        return null; // terminou sem dados de imagem
    }
}
