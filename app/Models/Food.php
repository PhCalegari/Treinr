<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catálogo de alimentos. Valores por 100 g (ou 100 ml).
 * - open_food_facts: cache de produtos da Open Food Facts (compartilhado entre usuários)
 * - custom: alimento criado pelo usuário (privado)
 *
 * @property int $id
 * @property string $source
 * @property string|null $external_code
 * @property string $name
 * @property string|null $brand
 * @property string $unit
 * @property string $kcal_per_100
 * @property string $protein_per_100
 * @property string $carbs_per_100
 * @property string $fat_per_100
 * @property int|null $created_by_user_id
 * @property Carbon|null $fetched_at
 */
class Food extends Model
{
    // "food" é incontável em inglês; sem isto o Laravel procuraria a tabela "food".
    protected $table = 'foods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'kcal_per_100' => 'float',
            'protein_per_100' => 'float',
            'carbs_per_100' => 'float',
            'fat_per_100' => 'float',
        ];
    }

    /** Alimentos públicos (Open Food Facts/USDA) + os criados pelo próprio usuário. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('created_by_user_id')->orWhere('created_by_user_id', $user->id));
    }

    public function portions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FoodPortion::class)->orderBy('grams');
    }

    public function toOption(): array
    {
        $portions = $this->relationLoaded('portions') ? $this->portions : $this->portions()->get();

        return [
            'food_id' => $this->id,
            'source' => $this->source,
            'code' => $this->external_code,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'unit' => $this->unit,
            'per_100' => [
                'kcal' => $this->kcal_per_100,
                'protein' => $this->protein_per_100,
                'carbs' => $this->carbs_per_100,
                'fat' => $this->fat_per_100,
            ],
            // Medidas caseiras ("concha" = 140 g), da menor para a maior.
            'portions' => $portions->map(fn (FoodPortion $p) => ['label' => $p->label, 'grams' => $p->grams, 'source' => $p->source])->values()->all(),
        ];
    }
}
