<?php

namespace App\Modules\Integracion\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Sistema externo (HIS, ERP…) que envía órdenes y conceptos de pre-anestesia con su propio token.
 */
class ClienteIntegracion extends Model
{
    use HasApiTokens;

    /** Permisos (abilities) que se pueden dar a un token de integración. */
    public const PERMISOS = ['ordenes:escribir', 'ordenes:leer', 'historias:escribir', 'inventario:escribir'];

    protected $table = 'clientes_integracion';

    /**
     * @var list<string>
     */
    protected $fillable = ['nombre', 'descripcion', 'activo', 'ultimo_uso_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['activo' => 'boolean', 'ultimo_uso_en' => 'datetime'];
}
