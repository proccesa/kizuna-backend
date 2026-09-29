<?php

namespace App\Modules\Users\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'activo',
    ];

    /**
     * Atributos ocultos para la serialización.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casts de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Relación uno a uno con el perfil de Operador.
     */
    public function operador(): HasOne
    {
        return $this->hasOne(Operador::class, 'user_id');
    }

    /**
     * Verifica si el usuario se encuentra activo.
     */
    public function estaActivo(): bool
    {
        return (bool) $this->activo;
    }

    /**
     * Retorna el operador asociado si existe.
     */
    public function obtenerOperador(): ?Operador
    {
        return $this->operador;
    }

    /**
     * Retorna la lista de nombres de permisos del usuario (incluyendo los heredados por sus roles).
     */
    public function obtenerPermisos(): array
    {
        return $this->getAllPermissions()->pluck('name')->toArray();
    }

    /**
     * Retorna la lista de roles asignados al usuario.
     */
    public function obtenerRoles(): array
    {
        return $this->getRoleNames()->toArray();
    }
}
