<?php

namespace App\Modules\Talento\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Novedad: periodo en el que el especialista no atiende (vacaciones, incapacidad…).
 */
class Ausencia extends Model
{
    public const TIPOS = ['VACACIONES', 'INCAPACIDAD', 'LICENCIA', 'CAPACITACION', 'OTRO'];

    protected $table = 'ausencias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'especialista_id',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'observacion',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'especialista_id' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function especialista(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'especialista_id');
    }
}
