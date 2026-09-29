<?php

namespace App\Models;

use App\Modules\Users\Models\User as BaseUser;

/**
 * Modelo de Usuario que hereda de la arquitectura modular de Usuarios.
 */
class User extends BaseUser
{
    // Hereda todas las relaciones, traits (Sanctum, Spatie, SoftDeletes) y métodos
}
