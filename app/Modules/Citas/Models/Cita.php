<?php

namespace App\Modules\Citas\Models;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cita extends Model
{
    public const ESTADOS = ['PROGRAMADA', 'ATENDIDA', 'CANCELADA', 'NO_ASISTIO'];

    protected $table = 'citas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'paciente_id',
        'especialista_id',
        'sede_id',
        'especialidad_id',
        'cups_id',
        'agenda_id',
        'orden_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'consultorio',
        'tipo',
        'estado',
        'origen',
        'motivo_cancelacion',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'paciente_id' => 'integer',
        'especialista_id' => 'integer',
        'sede_id' => 'integer',
        'especialidad_id' => 'integer',
        'cups_id' => 'integer',
        'agenda_id' => 'integer',
        'orden_id' => 'integer',
        'fecha' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function especialista(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'especialista_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function cups(): BelongsTo
    {
        return $this->belongsTo(Cups::class, 'cups_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenQuirurgica::class, 'orden_id');
    }

    public function getHoraInicioAttribute(?string $valor): ?string
    {
        return $valor ? substr($valor, 0, 5) : null;
    }

    public function getHoraFinAttribute(?string $valor): ?string
    {
        return $valor ? substr($valor, 0, 5) : null;
    }
}
