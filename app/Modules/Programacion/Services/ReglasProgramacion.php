<?php

namespace App\Modules\Programacion\Services;

use App\Modules\Common\Models\Ajuste;

/**
 * Reglas del motor de programación quirúrgica (tabla `ajustes`, clave `programacion`).
 */
class ReglasProgramacion
{
    public const CLAVE = 'programacion';

    public const POR_DEFECTO = [
        // Días mínimos entre hoy y la cirugía, para avisar al paciente y que se prepare.
        'anticipacion_dias' => 3,
        // Limpieza y alistamiento de la sala entre cirugías.
        'rotacion_minutos' => 30,
        // Cada cuánto se prueba una hora de inicio dentro de la agenda del cirujano.
        'paso_minutos' => 30,
        // Antes de esta hora solo se programan niños, diabéticos y ASA III–IV.
        'hora_reservada_temprana' => '09:00',
        // Jornadas para asignar el anestesiólogo por sala.
        'jornadas' => ['MANANA' => ['06:00', '13:00'], 'TARDE' => ['13:00', '21:00']],
        // Pesos del puntaje de prioridad.
        'pesos' => [
            'prioritaria' => 1000,
            'aval_por_vencer' => 300,
            'dias_alerta_aval' => 15,
            'espera_por_dia' => 2,
            'espera_maxima' => 400,
            'proteccion_especial' => 100,
            // Contrato PGP atrasado: puntos × rezago (0 a 1) frente al tiempo transcurrido del contrato.
            'rezago_pgp' => 200,
        ],
    ];

    public function todas(): array
    {
        $guardadas = Ajuste::obtener(self::CLAVE, []);

        return array_replace_recursive(self::POR_DEFECTO, $guardadas);
    }

    public function guardar(array $cambios): array
    {
        Ajuste::guardar(self::CLAVE, array_replace_recursive(Ajuste::obtener(self::CLAVE, []), $cambios));

        return $this->todas();
    }
}
