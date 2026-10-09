<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Equipo biomédico (por placa) o caja de instrumental (por código).
 */
class Unidad extends Model
{
    use SoftDeletes;

    public const ESTADOS = ['OPERATIVO', 'MANTENIMIENTO', 'FUERA_SERVICIO', 'BAJA'];

    protected $table = 'inventario_unidades';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'item_id', 'sede_id', 'sala_id', 'codigo', 'serie', 'marca', 'modelo', 'registro_invima', 'estado',
        'ultimo_mantenimiento', 'proximo_mantenimiento', 'calibracion_vence', 'observaciones',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'item_id' => 'integer',
        'sede_id' => 'integer',
        'sala_id' => 'integer',
        'ultimo_mantenimiento' => 'date:Y-m-d',
        'proximo_mantenimiento' => 'date:Y-m-d',
        'calibracion_vence' => 'date:Y-m-d',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['alertas'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class, 'sala_id');
    }

    public function mantenimientos(): HasMany
    {
        return $this->hasMany(Mantenimiento::class, 'unidad_id');
    }

    /**
     * Mantenimiento y calibración: vencidos o próximos (30 días).
     * La calibración vencida bloquea el equipo; el mantenimiento preventivo vencido solo avisa.
     *
     * @return list<array{tipo: string, nivel: string, mensaje: string}>
     */
    public function getAlertasAttribute(): array
    {
        $hoy = now()->startOfDay();
        $alertas = [];
        foreach (['proximo_mantenimiento' => 'Mantenimiento preventivo', 'calibracion_vence' => 'Calibración'] as $campo => $nombre) {
            $fecha = $this->{$campo};
            if (! $fecha) {
                continue;
            }
            if ($fecha->lt($hoy)) {
                $nivel = $campo === 'calibracion_vence' ? 'bloqueo' : 'aviso';
                $alertas[] = ['tipo' => $campo, 'nivel' => $nivel, 'mensaje' => "{$nombre} vencido desde el {$fecha->format('d/m/Y')}"];
            } elseif ($fecha->lte($hoy->copy()->addDays(30))) {
                $alertas[] = ['tipo' => $campo, 'nivel' => 'aviso', 'mensaje' => "{$nombre} vence el {$fecha->format('d/m/Y')}"];
            }
        }

        return $alertas;
    }
}
