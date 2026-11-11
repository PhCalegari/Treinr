<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name_pt
 */
class Equipment extends Model
{
    protected $table = 'equipment';

    public $timestamps = false;

    protected $guarded = [];
}
