<?php

namespace App\Modules\Programacion\Models;

use App\Modules\Red\Models\Sala;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anestesiólogo que cubre una sala durante una jornada (mañana o tarde).
 */
class AsignacionAnestesia extends Model
{
    protected $table = 'asignaciones_anestesia';

    /**
     * @var list<string>
     */
    protected $fillable = ['sala_id', 'especialista_id', 'corrida_id', 'fecha', 'jornada'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['sala_id' => 'integer', 'especialista_id' => 'integer', 'corrida_id' => 'integer', 'fecha' => 'date:Y-m-d'];

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class, 'sala_id');
    }

    public function especialista(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'especialista_id');
    }
}
