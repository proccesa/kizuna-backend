<?php

namespace App\Modules\Catalogos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departamento extends Model
{
    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'departamentos';

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'codigo',
        'nombre',
    ];

    /**
     * Relación con los municipios del departamento.
     */
    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class, 'departamento_id');
    }
}
