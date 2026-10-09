<?php

namespace App\Modules\Contratacion\Models;

use App\Modules\Catalogos\Models\Regimen;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pagador con el que la IPS tiene contratos: EPS, prepagada, ARL, aseguradora…
 */
class Entidad extends Model
{
    use SoftDeletes;

    public const TIPOS = ['EPS', 'MEDICINA_PREPAGADA', 'ARL', 'ASEGURADORA', 'ENTIDAD_TERRITORIAL', 'REGIMEN_ESPECIAL', 'OTRO'];

    protected $table = 'entidades';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nit',
        'digito_verificacion',
        'razon_social',
        'sigla',
        'codigo_minsalud',
        'tipo',
        'telefono',
        'correo',
        'activo',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'pivot',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'nit_completo',
    ];

    protected function nitCompleto(): Attribute
    {
        return Attribute::make(get: fn () => "{$this->nit}-{$this->digito_verificacion}");
    }

    public function regimenes(): BelongsToMany
    {
        return $this->belongsToMany(Regimen::class, 'entidad_regimen', 'entidad_id', 'regimen_id');
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'entidad_id');
    }
}
