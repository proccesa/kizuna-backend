<?php

namespace App\Modules\Servicios\Models;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Red\Models\Sede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un procedimiento CUPS ofrecido en una sede, con su duración (tabla portafolio_servicios).
 */
class PortafolioItem extends Model
{
    protected $table = 'portafolio_servicios';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
        'cups_id',
        'duracion_minutos',
        'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sede_id' => 'integer',
        'cups_id' => 'integer',
        'duracion_minutos' => 'integer',
        'activo' => 'boolean',
    ];

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function cups(): BelongsTo
    {
        return $this->belongsTo(Cups::class, 'cups_id');
    }
}
