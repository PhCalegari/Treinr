<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de medida corporal (cintura, braço direito...). Dado de referência.
 *
 * @property int $id
 * @property string $code
 * @property string $name_pt
 * @property int $position
 */
class MeasurementType extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
