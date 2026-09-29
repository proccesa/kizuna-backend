<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Operador extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'operadores';

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'tipo_documento_id',
        'documento',
        'nombre',
        'apellido',
        'telefono',
        'activo',
    ];

    /**
     * Casts de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'tipo_documento_id' => 'integer',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Atributos adicionales que se agregan al JSON.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'nombre_completo',
    ];

    /**
     * Accesor para nombre completo del operador.
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(
            get: fn() => trim("{$this->nombre} {$this->apellido}")
        );
    }

    /**
     * Relación con el usuario del sistema.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relación con el tipo de documento.
     */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    /**
     * Scope para filtrar operadores activos.
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Retorna el nombre completo del operador.
     */
    public function obtenerNombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellido}");
    }
}
