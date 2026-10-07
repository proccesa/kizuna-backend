<?php

namespace App\Providers;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Nombre del rol con acceso total al sistema.
     */
    public const ROL_SUPER_ADMIN = 'super-admin';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El super administrador pasa cualquier verificación de permisos,
        // incluso de permisos creados después de ejecutar los seeders.
        Gate::before(function ($usuario) {
            return $usuario instanceof User && $usuario->hasRole(self::ROL_SUPER_ADMIN) ? true : null;
        });
    }
}
