<?php

namespace App\Modules\Integracion\Middleware;

use App\Modules\Integracion\Models\ClienteIntegracion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los tokens de integración no pueden usar las rutas de la aplicación.
 */
class SoloUsuarios
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof ClienteIntegracion) {
            return response()->json(['exito' => false, 'mensaje' => 'Los tokens de integración solo pueden usar los endpoints /integracion.'], 403);
        }

        return $next($request);
    }
}
