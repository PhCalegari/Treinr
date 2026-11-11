<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registro de evolução física de um dia: peso, % de gordura, medidas e fotos.
 *
 * @property Carbon $entry_date
 * @property float|null $weight_kg
 * @property float|null $height_cm
 * @property float|null $body_fat_pct
 * @property string|null $notes
 */
class ProgressEntry extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'weight_kg' => 'float',
            'height_cm' => 'float',
            'body_fat_pct' => 'float',
        ];
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(ProgressMeasurement::class, 'entry_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProgressPhoto::class, 'entry_id')->orderBy('created_at');
    }

    /** Último peso registrado até a data (para as estimativas). */
    public static function latestWith(int $userId, string $column, ?string $date = null): ?self
    {
        return self::ownedBy($userId)
            ->whereNotNull($column)
            ->when($date, fn ($q) => $q->whereDate('entry_date', '<=', $date))
            ->orderByDesc('entry_date')
            ->orderByDesc('modified_at')
            ->first();
    }
}
