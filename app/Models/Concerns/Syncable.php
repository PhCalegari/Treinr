<?php

namespace App\Models\Concerns;

use App\Support\SyncVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Comportamento comum dos dados que funcionam offline e sincronizam:
 * - id UUID (v7, ordenado no tempo); o aparelho pode gerar o próprio id.
 * - modified_at: hora da alteração. Online, o servidor preenche; na sync vem do aparelho.
 * - sync_version: novo número a cada gravação.
 * - exclusão lógica (deleted_at), para a exclusão chegar a todos os aparelhos.
 *   Registros excluídos ficam fora das consultas normais.
 *
 * @property string $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $modified_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int $sync_version
 */
trait Syncable
{
    use HasUuids;

    /** Quando true, mantém o modified_at informado (sincronização vinda do aparelho). */
    public bool $keepClientModifiedAt = false;

    public static function bootSyncable(): void
    {
        static::addGlobalScope('not_deleted', fn (Builder $q) => $q->whereNull($q->getModel()->getTable().'.deleted_at'));

        static::saving(function (self $model) {
            if (! $model->keepClientModifiedAt || ! $model->modified_at) {
                $model->modified_at = now();
            }
            $model->sync_version = SyncVersion::next();
        });
    }

    public function initializeSyncable(): void
    {
        $this->mergeCasts(['modified_at' => 'datetime', 'deleted_at' => 'datetime']);
    }

    /** Exclusão lógica (gera nova versão para propagar a exclusão). */
    public function markDeleted(): void
    {
        $this->deleted_at = now();
        $this->save();
    }

    public function getUpdatedAtColumn(): ?string
    {
        return null; // estas tabelas usam modified_at no lugar de updated_at
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where($this->getTable().'.user_id', $userId);
    }
}
