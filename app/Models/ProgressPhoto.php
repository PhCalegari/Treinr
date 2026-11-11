<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * Foto de evolução. O arquivo fica no disco privado; só o dono consegue abrir.
 *
 * @property string $entry_id
 * @property string $angle
 * @property string|null $file_path
 */
class ProgressPhoto extends Model
{
    use Syncable;

    public const ANGLES = ['front', 'side', 'back', 'other'];

    protected $guarded = ['id', 'user_id', 'sync_version'];
}
