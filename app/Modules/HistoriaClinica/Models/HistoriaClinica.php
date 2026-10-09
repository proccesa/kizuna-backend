<?php

namespace App\Modules\HistoriaClinica\Models;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Citas\Models\Cita;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Historia clínica diligenciada con una versión de plantilla.
 * Una vez finalizada no se modifica: solo se anula con justificación (Res. 1995 de 1999).
 */
class HistoriaClinica extends Model
{
    protected $table = 'historias_clinicas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'paciente_id',
        'plantilla_version_id',
        'especialista_id',
        'cita_id',
        'orden_id',
        'origen',
        'sistema_origen',
        'referencia_externa',
        'profesional_externo',
        'estado',
        'respuestas',
        'resultado',
        'finalizada_en',
        'finalizada_por',
        'creada_por',
        'motivo_anulacion',
        'anulada_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'paciente_id' => 'integer',
        'plantilla_version_id' => 'integer',
        'especialista_id' => 'integer',
        'cita_id' => 'integer',
        'orden_id' => 'integer',
        'respuestas' => 'array',
        'resultado' => 'array',
        'finalizada_en' => 'datetime',
        'anulada_en' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlantillaHcVersion::class, 'plantilla_version_id');
    }

    public function especialista(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'especialista_id');
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenQuirurgica::class, 'orden_id');
    }

    public function finalizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizada_por');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(HistoriaEvento::class, 'historia_id');
    }
}
