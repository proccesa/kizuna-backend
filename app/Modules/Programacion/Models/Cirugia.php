<?php

namespace App\Modules\Programacion\Models;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cirugia extends Model
{
    public const ESTADOS = ['PROPUESTA', 'APROBADA', 'REALIZADA', 'CANCELADA'];

    protected $table = 'cirugias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'corrida_id', 'orden_id', 'paciente_id', 'cups_id', 'sede_id', 'sala_id', 'cirujano_id', 'anestesiologo_id',
        'fecha', 'hora_inicio', 'hora_fin', 'estado', 'puntaje', 'prioridad', 'avisos', 'motivo_cancelacion',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'corrida_id' => 'integer', 'orden_id' => 'integer', 'paciente_id' => 'integer', 'cups_id' => 'integer', 'sede_id' => 'integer',
        'sala_id' => 'integer', 'cirujano_id' => 'integer', 'anestesiologo_id' => 'integer',
        'fecha' => 'date:Y-m-d', 'puntaje' => 'float', 'prioridad' => 'array', 'avisos' => 'array',
    ];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenQuirurgica::class, 'orden_id');
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function cups(): BelongsTo
    {
        return $this->belongsTo(Cups::class, 'cups_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class, 'sala_id');
    }

    public function cirujano(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'cirujano_id');
    }

    public function anestesiologo(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'anestesiologo_id');
    }

    public function getHoraInicioAttribute(?string $v): ?string
    {
        return $v ? substr($v, 0, 5) : null;
    }

    public function getHoraFinAttribute(?string $v): ?string
    {
        return $v ? substr($v, 0, 5) : null;
    }
}
