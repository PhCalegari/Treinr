<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Cada usuário só acessa os próprios dados. Registro de outra pessoa responde
 * 404 (não revela que o id existe).
 */
trait ChecksOwnership
{
    protected function own(Model $model): void
    {
        abort_unless((int) $model->getAttribute('user_id') === (int) auth()->id(), 404);
    }
}
