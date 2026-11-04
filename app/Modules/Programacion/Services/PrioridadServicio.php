<?php

namespace App\Modules\Programacion\Services;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Contratacion\Services\CumplimientoContratos;
use Carbon\Carbon;

/**
 * Puntaje de prioridad de una orden apta. Cuanto más alto, antes se programa.
 * Cada factor queda registrado para poder explicar por qué un paciente va antes que otro.
 */
class PrioridadServicio
{
    public function __construct(
        private readonly ReglasProgramacion $reglas,
        private readonly CumplimientoContratos $cumplimiento
    ) {}

    /**
     * @return array{puntaje: float, factores: list<array{etiqueta: string, puntos: float}>, temprano: bool, motivo_temprano: ?string}
     */
    public function calcular(OrdenQuirurgica $orden, ?Carbon $hoy = null): array
    {
        $hoy ??= now()->startOfDay();
        $reglas = $this->reglas->todas();
        $p = $reglas['pesos'];
        $factores = [];

        if ($orden->prioridad === 'PRIORITARIA') {
            $factores[] = ['etiqueta' => 'Orden prioritaria', 'puntos' => (float) $p['prioritaria']];
        }

        if ($orden->aval_hasta) {
            $restantes = (int) $hoy->diffInDays($orden->aval_hasta, false);
            if ($restantes <= $p['dias_alerta_aval']) {
                $puntos = $p['aval_por_vencer'] + max(0, $p['dias_alerta_aval'] - $restantes) * 10;
                $factores[] = ['etiqueta' => $restantes >= 0 ? "Aval vence en {$restantes} días" : 'Aval vencido', 'puntos' => (float) $puntos];
            }
        }

        $espera = (int) $orden->fecha_orden?->diffInDays($hoy);
        if ($espera > 0) {
            $factores[] = ['etiqueta' => "{$espera} días de espera", 'puntos' => (float) min($p['espera_maxima'], $espera * $p['espera_por_dia'])];
        }

        if ($orden->contrato_id) {
            $orden->loadMissing('contrato.modalidad');
        }
        if ($contrato = $orden->contrato) {
            $modalidad = $contrato->modalidad?->codigo;
            $porModalidad = (float) ($reglas['pesos_modalidad'][$modalidad] ?? 0);
            if ($porModalidad > 0) {
                $factores[] = ['etiqueta' => 'Contrato '.($modalidad === 'CAPITA' ? 'por cápita' : ($modalidad === 'EVENTO' ? 'por evento' : $modalidad)), 'puntos' => $porModalidad];
            }

            // Cumplimiento: los contratos atrasados frente al tiempo transcurrido suben.
            $rezago = ($p['rezago_pgp'] ?? 0) > 0 ? $this->cumplimiento->rezagoPgp($contrato, $orden->cups_id, $hoy) : null;
            if ($rezago && $rezago['rezago'] > 0) {
                $factores[] = [
                    'etiqueta' => sprintf('Contrato PGP atrasado (%d %% de %d %% esperado)', round($rezago['actual'] * 100), round($rezago['esperado'] * 100)),
                    'puntos' => round($p['rezago_pgp'] * $rezago['rezago'], 2),
                ];
            }
            $evento = $this->cumplimiento->rezagoEvento($contrato, $hoy);
            if ($evento && $evento['actual'] >= 1 && ($p['evento_agotado'] ?? 0) > 0) {
                $factores[] = ['etiqueta' => sprintf('Valor del contrato agotado (%d %% comprometido)', round($evento['actual'] * 100)), 'puntos' => -(float) $p['evento_agotado']];
            } elseif ($evento && $evento['rezago'] > 0 && ($p['rezago_evento'] ?? 0) > 0) {
                $factores[] = [
                    'etiqueta' => sprintf('Contrato por evento atrasado (%d %% de %d %% esperado)', round($evento['actual'] * 100), round($evento['esperado'] * 100)),
                    'puntos' => round($p['rezago_evento'] * $evento['rezago'], 2),
                ];
            }

            // Vencimiento: si el contrato termina pronto hay que operar antes (después no se puede programar).
            if ($contrato->fecha_fin && ($p['contrato_por_vencer'] ?? 0) > 0) {
                $restantes = (int) $hoy->diffInDays($contrato->fecha_fin, false);
                if ($restantes >= 0 && $restantes <= $p['dias_alerta_contrato']) {
                    $factores[] = ['etiqueta' => "Contrato vence en {$restantes} días", 'puntos' => (float) ($p['contrato_por_vencer'] + ($p['dias_alerta_contrato'] - $restantes) * 5)];
                }
            }
        }

        $edad = $orden->paciente?->fecha_nacimiento?->age;
        $respuestas = $orden->historia?->respuestas ?? [];
        $especial = match (true) {
            $edad !== null && $edad < 18 => 'Menor de edad',
            $edad !== null && $edad >= 65 => 'Adulto mayor',
            ($respuestas['embarazo'] ?? null) === true => 'Gestante',
            default => null,
        };
        if ($especial) {
            $factores[] = ['etiqueta' => $especial, 'puntos' => (float) $p['proteccion_especial']];
        }

        // Primera hora del día: niños, diabéticos (por el ayuno) y ASA III–IV.
        $temprano = match (true) {
            $edad !== null && $edad < 18 => 'Paciente pediátrico',
            ($respuestas['diabetes'] ?? null) === true => 'Paciente diabético',
            ($orden->asa ?? 0) >= 3 => 'ASA '.['', 'I', 'II', 'III', 'IV', 'V'][$orden->asa],
            default => null,
        };

        return [
            'puntaje' => round(array_sum(array_column($factores, 'puntos')), 2),
            'factores' => $factores,
            'temprano' => $temprano !== null,
            'motivo_temprano' => $temprano,
        ];
    }
}
