<?php

namespace App\Modules\Cirugia\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Common\Models\Ajuste;
use App\Modules\Servicios\Models\Especialidad;

/**
 * Reglas de pre-anestesia que la IPS puede ajustar (tabla `ajustes`, clave `preanestesia`).
 */
class ReglasPreanestesia
{
    public const CLAVE = 'preanestesia';

    public const POR_DEFECTO = [
        // Consulta de primera vez por especialista en anestesiología
        'cups_consulta_codigo' => '890226',
        'especialidad_codigo' => 'anestesiologia',
        // Días de vigencia del aval según la clase ASA
        'vigencia_dias_por_asa' => ['1' => 180, '2' => 180, '3' => 90, '4' => 30, '5' => 30],
        // Días hacia adelante en que se busca cupo para la cita
        'horizonte_dias' => 60,
        // Días mínimos entre la orden y la cita (1 = desde mañana)
        'dias_anticipacion' => 1,
        // Duración de la consulta si la sede no la define en su portafolio
        'duracion_minutos' => 30,
    ];

    /**
     * @return array<string, mixed>
     */
    public function todas(): array
    {
        $guardadas = Ajuste::obtener(self::CLAVE, []);
        $reglas = array_replace(self::POR_DEFECTO, $guardadas);
        $reglas['vigencia_dias_por_asa'] = array_replace(self::POR_DEFECTO['vigencia_dias_por_asa'], $guardadas['vigencia_dias_por_asa'] ?? []);

        return $reglas;
    }

    public function guardar(array $cambios): array
    {
        Ajuste::guardar(self::CLAVE, array_replace(Ajuste::obtener(self::CLAVE, []), $cambios));

        return $this->conCatalogos();
    }

    /**
     * Reglas con el CUPS y la especialidad resueltos, para mostrarlas en pantalla.
     */
    public function conCatalogos(): array
    {
        $reglas = $this->todas();
        $reglas['cups_consulta'] = $this->cupsConsulta()?->only(['id', 'codigo', 'nombre']);
        $reglas['especialidad'] = $this->especialidad()?->only(['id', 'codigo', 'nombre']);

        return $reglas;
    }

    public function cupsConsulta(): ?Cups
    {
        return Cups::where('codigo', $this->todas()['cups_consulta_codigo'])->first();
    }

    public function especialidad(): ?Especialidad
    {
        return Especialidad::where('codigo', $this->todas()['especialidad_codigo'])->first();
    }

    public function vigenciaDias(?int $asa): int
    {
        $tabla = $this->todas()['vigencia_dias_por_asa'];

        return (int) ($tabla[(string) $asa] ?? min($tabla));
    }
}
