<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma análise de foto do prato pela IA. A imagem NÃO é guardada (image_path fica vazio);
 * fica só o resultado, para o usuário revisar e confirmar.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status pending|completed|failed|confirmed|discarded
 * @property string $provider
 * @property string|null $model
 * @property array|null $raw_response
 * @property string|null $error_message
 */
class MealPhotoAnalysis extends Model
{
    protected $table = 'meal_photo_analyses';

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['raw_response' => 'array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealPhotoItem::class, 'analysis_id')->orderBy('id');
    }
}
