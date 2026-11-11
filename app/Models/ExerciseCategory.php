<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name_pt
 */
class ExerciseCategory extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
