<?php

namespace Database\Seeders;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Entidad;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Contratacion\Models\Poblacion;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Base mínima para arrancar una demostración desde cero: un prestador con una sede, una EPS,
 * un contrato y una población con pocos pacientes. Sin órdenes, historias ni inventario.
 * Requiere los catálogos (CatalogosSeeder). Se puede ejecutar varias veces sin duplicar:
 * php artisan db:seed --class=DatosBaseDemoSeeder
 *
 * Todos los nombres de personas y empresas son ficticios.
 */
class DatosBaseDemoSeeder extends Seeder
{
    /** CUPS => [duración en minutos, tipo de sala, cantidad contratada] */
    private const SERVICIOS = [
        '512104' => [90, 'QUIROFANO', 320],
        '471110' => [75, 'QUIROFANO', 120],
        '530002' => [90, 'QUIROFANO', 180],
        '534001' => [60, 'QUIROFANO', 150],
        '890226' => [30, null, null],
    ];

    private const PACIENTES = [
        ['31456789', 'Rosa', 'Elena', 'Quintero', 'Arboleda', '1968-03-14', 'F'],
        ['16789012', 'Luis', 'Eduardo', 'Cárdenas', 'Mora', '1955-07-02', 'M'],
        ['66123456', 'María', 'Fernanda', 'Ríos', 'Gómez', '1974-11-21', 'F'],
        ['94234567', 'Jorge', 'Iván', 'Murillo', 'Patiño', '1981-01-30', 'M'],
        ['29345678', 'Gloria', null, 'Salazar', 'Zapata', '1962-09-08', 'F'],
        ['1144056789', 'Andrés', 'Felipe', 'Ospina', 'Lenis', '1995-05-17', 'M'],
        ['31567890', 'Carmen', 'Patricia', 'Valencia', 'Castaño', '1970-12-03', 'F'],
        ['14678901', 'Hernán', null, 'Rincón', 'Caicedo', '1958-04-25', 'M'],
        ['1107045678', 'Natalia', 'Andrea', 'Muñoz', 'Hernández', '1990-08-11', 'F'],
        ['16890123', 'Fabio', 'Alberto', 'García', 'López', '1966-02-19', 'M'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $cups = Cups::whereIn('codigo', array_keys(self::SERVICIOS))->get()->keyBy('codigo');
            $cali = Municipio::where('codigo', '76001')->value('id');

            // Especialidad de cada servicio
            $cirugia = Especialidad::where('codigo', 'cirugia-general')->value('id');
            $anestesia = Especialidad::where('codigo', 'anestesiologia')->value('id');
            foreach ($cups as $codigo => $c) {
                DB::table('cups_especialidad')->insertOrIgnore([
                    'cups_id' => $c->id, 'especialidad_id' => $codigo === '890226' ? $anestesia : $cirugia, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Red de atención
            $prestador = Prestador::updateOrCreate(['nit' => '900654321'], [
                'digito_verificacion' => (string) $this->dv('900654321'), 'razon_social' => 'Clínica Vida Plena IPS S.A.S.', 'nombre_comercial' => 'Clínica Vida Plena',
                'codigo_habilitacion' => '7600101234', 'naturaleza' => 'PRIVADA', 'telefono' => '6023334455', 'correo' => 'contacto@vidaplena.co', 'representante_legal' => 'Luz Marina Cárdenas', 'activo' => true,
            ]);
            $sede = Sede::updateOrCreate(['prestador_id' => $prestador->id, 'numero_sede' => '01'], [
                'nombre' => 'Sede Principal', 'direccion' => 'Av. 6N # 28-40', 'municipio_id' => $cali, 'consultorios' => 8, 'es_principal' => true,
                'dias_atencion' => [1, 2, 3, 4, 5, 6], 'hora_apertura' => '06:00', 'hora_cierre' => '20:00', 'activo' => true,
            ]);
            Sala::updateOrCreate(['sede_id' => $sede->id, 'codigo' => 'Q1'], ['nombre' => 'Quirófano 1', 'tipo' => 'QUIROFANO', 'activo' => true]);
            foreach (self::SERVICIOS as $codigo => [$duracion, $sala]) {
                PortafolioItem::updateOrCreate(['sede_id' => $sede->id, 'cups_id' => $cups[$codigo]->id], ['duracion_minutos' => $duracion, 'tipo_sala' => $sala, 'activo' => true]);
            }

            // Contratación
            $contributivo = Regimen::where('codigo', 'CONTRIBUTIVO')->value('id');
            $entidad = Entidad::updateOrCreate(['nit' => '901234560'], [
                'digito_verificacion' => (string) $this->dv('901234560'), 'razon_social' => 'Salud Andina EPS S.A.', 'sigla' => 'SANDINA', 'codigo_minsalud' => 'EPSD01', 'tipo' => 'EPS', 'activo' => true,
            ]);
            $entidad->regimenes()->sync([$contributivo]);

            $contrato = Contrato::updateOrCreate(['entidad_id' => $entidad->id, 'numero' => 'CT-2026-101'], [
                'modalidad_contratacion_id' => ModalidadContratacion::where('codigo', 'PGP')->value('id'), 'regimen_id' => $contributivo,
                'fecha_inicio' => now()->startOfYear()->toDateString(), 'fecha_fin' => now()->endOfYear()->toDateString(), 'valor' => 4850000000,
                'objeto' => 'Cirugía general ambulatoria y hospitalaria', 'activo' => true,
            ]);
            $contrato->sedes()->sync([$sede->id]);
            $contrato->cups()->sync(collect(self::SERVICIOS)->filter(fn ($s) => $s[2] !== null)->mapWithKeys(fn ($s, $codigo) => [$cups[$codigo]->id => ['cantidad' => $s[2], 'tarifa' => null]])->all());

            // Población
            $poblacion = Poblacion::updateOrCreate(['contrato_id' => $contrato->id, 'nombre' => 'Afiliados contributivo Cali'], [
                'descripcion' => 'Base mensual enviada por Salud Andina', 'ultimo_cargue_en' => now(), 'activo' => true,
            ]);
            $cc = TipoDocumento::where('codigo', 'CC')->value('id');
            foreach (self::PACIENTES as [$documento, $nombre, $segundo, $apellido, $segundoApellido, $nacimiento, $sexo]) {
                $paciente = Paciente::updateOrCreate(['tipo_documento_id' => $cc, 'numero_documento' => $documento], [
                    'primer_nombre' => $nombre, 'segundo_nombre' => $segundo, 'primer_apellido' => $apellido, 'segundo_apellido' => $segundoApellido,
                    'fecha_nacimiento' => $nacimiento, 'sexo' => $sexo, 'telefono' => '310'.substr($documento, -7), 'municipio_id' => $cali,
                ]);
                $poblacion->pacientes()->syncWithoutDetaching([$paciente->id => ['activo' => true]]);
            }
        });

        $this->command?->info('Base de demostración: 1 prestador, 1 sede, 1 EPS, 1 contrato y 1 población con '.count(self::PACIENTES).' pacientes.');
    }

    /** Dígito de verificación DIAN de un NIT. */
    private function dv(string $nit): int
    {
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $suma = 0;
        foreach (array_reverse(str_split($nit)) as $i => $digito) {
            $suma += (int) $digito * $pesos[$i];
        }
        $residuo = $suma % 11;

        return $residuo > 1 ? 11 - $residuo : $residuo;
    }
}
