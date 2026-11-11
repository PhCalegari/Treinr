<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Músculo detalhado (como na base externa), ligado a um dos 11 grupos do app.
 *
 * @property int $id
 * @property string $code
 * @property string $name_pt
 * @property int|null $muscle_group_id
 */
class Muscle extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MuscleGroup::class, 'muscle_group_id');
    }
}
