<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Refeição de um dia (café da manhã, almoço...). Uma por usuário/dia/tipo.
 *
 * @property Carbon $meal_date
 * @property string $meal_type
 */
class Meal extends Model
{
    use Syncable;

    public const TYPES = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner', 'supper'];

    /** Namespace fixo do UUID v5 das refeições. O app usa o mesmo valor para calcular o id offline. */
    public const ID_NAMESPACE = '6f1c3c8e-3b0e-4d43-9d5b-2a9e1f7f0c11';

    protected $guarded = ['id', 'user_id', 'sync_version'];

    protected function casts(): array
    {
        return ['meal_date' => 'date:Y-m-d'];
    }

    /** Id determinístico: o mesmo usuário/dia/tipo sempre gera o mesmo id, em qualquer aparelho. */
    public static function idFor(int $userId, string $date, string $type): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, "{$userId}|{$date}|{$type}")->toString();
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)->orderBy('created_at');
    }
}
