<?php

namespace App\Modules\Red\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestador extends Model
{
    use SoftDeletes;

    /** Naturalezas jurídicas admitidas. */
    public const NATURALEZAS = ['PRIVADA', 'PUBLICA', 'MIXTA'];

    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'prestadores';

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nit',
        'digito_verificacion',
        'razon_social',
        'nombre_comercial',
        'codigo_habilitacion',
        'naturaleza',
        'telefono',
        'correo',
        'representante_legal',
        'activo',
    ];

    /**
     * Casts de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Atributos adicionales que se agregan al JSON.
     *
     * @var list<string>
     */
    protected $appends = [
        'nit_completo',
    ];

    /**
     * NIT con dígito de verificación: 900481226-3.
     */
    protected function nitCompleto(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->nit}-{$this->digito_verificacion}"
        );
    }

    /**
     * Sedes del prestador.
     */
    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class, 'prestador_id');
    }
}
