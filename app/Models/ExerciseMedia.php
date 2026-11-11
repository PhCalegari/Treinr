<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $exercise_id
 * @property string $media_type
 * @property string $url
 * @property int $position
 * @property string|null $attribution
 */
class ExerciseMedia extends Model
{
    protected $table = 'exercise_media';

    public $timestamps = false;

    protected $guarded = [];
}
