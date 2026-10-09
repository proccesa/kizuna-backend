<?php

namespace App\Modules\Talento\Models;

use App\Modules\Programacion\Models\AsignacionAnestesia;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Especialista extends Model
{
    use SoftDeletes;

    protected $table = 'especialistas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tipo_documento_id',
        'numero_documento',
        'nombres',
        'apellidos',
        'registro_profesional',
        'correo',
        'telefono',
        'activo',
    ];

    /**
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
     * @var list<string>
     */
    protected $appends = [
        'nombre_completo',
    ];

    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(get: fn () => trim("{$this->nombres} {$this->apellidos}"));
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    /**
     * Especialidades que el profesional está habilitado para atender.
     */
    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'especialidad_especialista', 'especialista_id', 'especialidad_id')->withTimestamps();
    }

    public function agendas(): HasMany
    {
        return $this->hasMany(Agenda::class, 'especialista_id');
    }

    /**
     * Salas y jornadas en que cubre la anestesia de las cirugías.
     */
    public function asignacionesAnestesia(): HasMany
    {
        return $this->hasMany(AsignacionAnestesia::class, 'especialista_id');
    }

    public function ausencias(): HasMany
    {
        return $this->hasMany(Ausencia::class, 'especialista_id');
    }
}
