<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name_pt
 * @property int $position
 */
class MuscleGroup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function muscles(): HasMany
    {
        return $this->hasMany(Muscle::class);
    }
}
