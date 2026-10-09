<?php

namespace App\Modules\HistoriaClinica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantillaHcVersion extends Model
{
    protected $table = 'plantilla_hc_versiones';

    /**
     * @var list<string>
     */
    protected $fillable = ['plantilla_id', 'version', 'esquema', 'publicada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['plantilla_id' => 'integer', 'version' => 'integer', 'esquema' => 'array', 'publicada_en' => 'datetime'];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaHc::class, 'plantilla_id');
    }
}
