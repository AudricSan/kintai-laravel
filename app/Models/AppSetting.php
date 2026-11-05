<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une ligne de la table `app_settings`. Ne se lit ni ne s'écrit directement : passer par `App\Settings\AppSettings`,
 * qui type, borne, chiffre et met en cache les valeurs.
 *
 * @property string $key
 * @property mixed $value Valeur JSON (pour un secret : la chaîne chiffrée).
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
