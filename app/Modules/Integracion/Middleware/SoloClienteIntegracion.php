<?php

namespace App\Modules\Integracion\Middleware;

use App\Modules\Integracion\Models\ClienteIntegracion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas /integracion solo aceptan tokens de clientes de integración activos.
 */
class SoloClienteIntegracion
{
    public function handle(Request $request, Closure $next): Response
    {
        $cliente = $request->user();
        if (! $cliente instanceof ClienteIntegracion || ! $cliente->activo) {
            return response()->json(['exito' => false, 'mensaje' => 'Este endpoint requiere un token de integración activo.'], 403);
        }

        if (! $cliente->ultimo_uso_en || $cliente->ultimo_uso_en->lt(now()->subMinute())) {
            $cliente->forceFill(['ultimo_uso_en' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
