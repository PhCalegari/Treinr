<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $entry_id
 * @property int $measurement_type_id
 * @property float $value_cm
 */
class ProgressMeasurement extends Model
{
    use Syncable;

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['value_cm' => 'float'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MeasurementType::class, 'measurement_type_id');
    }
}
