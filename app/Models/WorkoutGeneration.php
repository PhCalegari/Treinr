<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de treino feito à IA.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status pending|completed|failed|saved
 * @property array $request
 * @property array|null $result
 * @property array|null $usage
 * @property string|null $plan_id
 */
class WorkoutGeneration extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['request' => 'array', 'result' => 'array', 'usage' => 'array'];
    }
}
