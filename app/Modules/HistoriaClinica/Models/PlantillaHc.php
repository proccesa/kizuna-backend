<?php

namespace App\Modules\HistoriaClinica\Models;

use App\Modules\Servicios\Models\Especialidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlantillaHc extends Model
{
    protected $table = 'plantillas_hc';

    /**
     * @var list<string>
     */
    protected $fillable = ['codigo', 'nombre', 'descripcion', 'especialidad_id', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['especialidad_id' => 'integer', 'activo' => 'boolean'];

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(PlantillaHcVersion::class, 'plantilla_id');
    }

    /**
     * Última versión publicada: la que se usa para historias nuevas.
     */
    public function versionVigente(): HasOne
    {
        return $this->hasOne(PlantillaHcVersion::class, 'plantilla_id')->whereNotNull('publicada_en')->ofMany('version', 'max');
    }
}
