<?php

namespace App\Services\Progress;

use App\Models\MeasurementType;
use App\Models\ProgressEntry;
use App\Models\ProgressMeasurement;
use App\Models\ProgressPhoto;
use App\Models\User;
use App\Support\JpegMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProgressService
{
    public const PHOTO_DISK = 'local'; // disco privado (storage/app/private)

    private const PHOTO_MAX_SIDE = 1600;

    /**
     * Grava o registro do dia. Se já existe um registro na data, completa/atualiza esse.
     * Medidas: valor vazio remove a medida.
     *
     * @param  array{entry_date: string, weight_kg?: ?float, body_fat_pct?: ?float, notes?: ?string, measurements?: array<string, ?float>}  $data
     */
    public function save(User $user, array $data, ?ProgressEntry $entry = null): ProgressEntry
    {
        return DB::transaction(function () use ($user, $data, $entry) {
            $entry ??= ProgressEntry::ownedBy($user->id)->whereDate('entry_date', $data['entry_date'])->first()
                ?? new ProgressEntry;
            $entry->user_id = $user->id;
            $entry->entry_date = $data['entry_date'];
            foreach (['weight_kg', 'body_fat_pct', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $entry->{$field} = $data[$field] === '' ? null : $data[$field];
                }
            }
            $entry->save();

            if (isset($data['measurements'])) {
                $types = MeasurementType::pluck('id', 'code');
                $existing = $entry->measurements()->get()->keyBy('measurement_type_id');
                foreach ($data['measurements'] as $code => $value) {
                    $typeId = $types[$code] ?? null;
                    if (! $typeId) {
                        continue;
                    }
                    $current = $existing->get($typeId);
                    if ($value === null || $value === '') {
                        $current?->markDeleted();

                        continue;
                    }
                    $m = $current ?? new ProgressMeasurement(['entry_id' => $entry->id, 'measurement_type_id' => $typeId]);
                    $m->user_id = $user->id;
                    $m->value_cm = (float) $value;
                    $m->save();
                }
            }

            $entry->load('measurements', 'photos');
            if ($entry->weight_kg === null && $entry->body_fat_pct === null && $entry->measurements->isEmpty()
                && $entry->photos->isEmpty() && blank($entry->notes)) {
                throw ValidationException::withMessages(['weight_kg' => 'Informe ao menos o peso, uma medida ou uma observação.']);
            }

            return $entry;
        });
    }

    public function delete(ProgressEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry->measurements->each->markDeleted();
            $entry->photos->each(fn (ProgressPhoto $p) => $this->deletePhoto($p));
            $entry->markDeleted();
        });
    }

    /**
     * Guarda a foto sem metadados (EXIF), que podem conter a localização de onde foi tirada.
     * O navegador já envia a foto reduzida em JPEG; aqui o servidor garante o mesmo
     * resultado para qualquer envio:
     *   - com suporte a JPEG no GD: reduz e regrava (corrige a rotação antes);
     *   - PNG: reduz e regrava com GD;
     *   - JPEG sem suporte no GD: remove os blocos de metadados do arquivo.
     */
    public function addPhoto(ProgressEntry $entry, UploadedFile $file, string $angle): ProgressPhoto
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > 12000) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível ler a imagem. Envie um JPG ou PNG.']);
        }

        $gdJpeg = (imagetypes() & IMG_JPG) !== 0;
        if ($type === IMAGETYPE_JPEG && ! $gdJpeg) {
            $content = JpegMetadata::strip($bytes);
            $ext = 'jpg';
        } else {
            $content = $this->reencode($bytes, $file, $gdJpeg);
            $ext = $gdJpeg ? 'jpg' : 'png';
        }
        if (! $content) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível ler a imagem. Envie um JPG ou PNG.']);
        }

        $path = "progress-photos/{$entry->user_id}/".Str::uuid7().'.'.$ext;
        Storage::disk(self::PHOTO_DISK)->put($path, $content);

        $photo = new ProgressPhoto(['entry_id' => $entry->id, 'angle' => $angle, 'file_path' => $path]);
        $photo->user_id = $entry->user_id;
        $photo->save();

        return $photo;
    }

    public function deletePhoto(ProgressPhoto $photo): void
    {
        if ($photo->file_path) {
            Storage::disk(self::PHOTO_DISK)->delete($photo->file_path);
        }
        $photo->markDeleted();
    }

    /** Reduz para no máximo PHOTO_MAX_SIDE e regrava (JPEG quando o GD suporta; senão PNG). */
    private function reencode(string $bytes, UploadedFile $file, bool $asJpeg): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }
        $source = $this->applyOrientation($source, $file);

        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::PHOTO_MAX_SIDE / max($w, $h));
        $image = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255)); // fundo branco para PNG transparente
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $w, $h);

        ob_start();
        $asJpeg ? imagejpeg($image, null, 85) : imagepng($image, null, 6);

        return (string) ob_get_clean() ?: null;
    }

    /** Fotos de celular vêm "deitadas" com a rotação só no EXIF; aplica antes de descartar o EXIF. */
    private function applyOrientation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * Dados da tela de evolução: registros, séries para gráficos e resumo.
     */
    public function overview(User $user): array
    {
        $entries = ProgressEntry::ownedBy($user->id)
            ->with(['measurements', 'photos'])
            ->orderByDesc('entry_date')
            ->orderByDesc('modified_at')
            ->get()
            ->unique(fn (ProgressEntry $e) => $e->entry_date->toDateString()) // um por dia (pode haver duplicado vindo de 2 aparelhos)
            ->values();

        $types = MeasurementType::orderBy('position')->get();
        $codeById = $types->pluck('code', 'id');
        $chronological = $entries->reverse()->values();

        $weight = $chronological->filter(fn ($e) => $e->weight_kg !== null)
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'value' => $e->weight_kg])->values();

        $measurements = [];
        foreach ($chronological as $e) {
            foreach ($e->measurements as $m) {
                $measurements[$codeById[$m->measurement_type_id]][] = ['date' => $e->entry_date->toDateString(), 'value' => $m->value_cm];
            }
        }
        $bodyFat = $chronological->filter(fn ($e) => $e->body_fat_pct !== null)
            ->map(fn ($e) => ['date' => $e->entry_date->toDateString(), 'value' => $e->body_fat_pct])->values();

        return [
            'entries' => $entries->map(fn (ProgressEntry $e) => [
                'id' => $e->id,
                'entry_date' => $e->entry_date->toDateString(),
                'weight_kg' => $e->weight_kg,
                'body_fat_pct' => $e->body_fat_pct,
                'notes' => $e->notes,
                'measurements' => $e->measurements->mapWithKeys(fn ($m) => [$codeById[$m->measurement_type_id] => $m->value_cm]),
                'photos' => $e->photos->map(fn (ProgressPhoto $p) => [
                    'id' => $p->id,
                    'angle' => $p->angle,
                    'url' => route('progress-photos.show', $p),
                ])->values(),
            ])->values(),
            'types' => $types->map(fn ($t) => ['code' => $t->code, 'name' => $t->name_pt])->values(),
            'series' => ['weight' => $weight, 'body_fat' => $bodyFat, 'measurements' => (object) $measurements],
            'summary' => $this->summary($user, $weight->all()),
        ];
    }

    /** Peso atual, variação em ~30 dias e IMC (o IMC é só uma referência populacional). */
    private function summary(User $user, array $weight): ?array
    {
        if (! $weight) {
            return null;
        }
        $last = end($weight);
        $cutoff = \Illuminate\Support\Carbon::parse($last['date'])->subDays(30)->toDateString();
        // Registro mais próximo de 30 dias atrás (o último até a data de corte).
        $before = collect($weight)->filter(fn ($p) => $p['date'] <= $cutoff)->last();
        $first = $weight[0];
        $height = $user->height_cm ? (float) $user->height_cm / 100 : null;

        return [
            'weight_kg' => $last['value'],
            'date' => $last['date'],
            'change_30d' => $before ? round($last['value'] - $before['value'], 1) : null,
            'change_30d_from' => $before['date'] ?? null,
            'change_total' => count($weight) > 1 ? round($last['value'] - $first['value'], 1) : null,
            'first_date' => $first['date'],
            'bmi' => $height ? round($last['value'] / ($height ** 2), 1) : null,
        ];
    }
}
