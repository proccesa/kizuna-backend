<?php

namespace App\Modules\Contratacion\Models;

use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Paciente extends Model
{
    protected $table = 'pacientes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tipo_documento_id',
        'numero_documento',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'fecha_nacimiento',
        'sexo',
        'telefono',
        'correo',
        'direccion',
        'municipio_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo_documento_id' => 'integer',
        'municipio_id' => 'integer',
        'fecha_nacimiento' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'nombre_completo',
        'edad',
    ];

    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(get: fn () => preg_replace('/\s+/', ' ', trim("{$this->primer_nombre} {$this->segundo_nombre} {$this->primer_apellido} {$this->segundo_apellido}")));
    }

    protected function edad(): Attribute
    {
        return Attribute::make(get: fn () => $this->fecha_nacimiento?->age);
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_id');
    }

    public function poblaciones(): BelongsToMany
    {
        return $this->belongsToMany(Poblacion::class, 'poblacion_paciente', 'paciente_id', 'poblacion_id')->withPivot(['cohortes', 'activo']);
    }
}
