<?php

namespace App\Console\Commands;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Services\OrdenServicio;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Entidad;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Contratacion\Models\Poblacion;
use App\Modules\HistoriaClinica\Models\HistoriaClinica;
use App\Modules\HistoriaClinica\Models\PlantillaHc;
use App\Modules\HistoriaClinica\Services\CalculadoraPreanestesia;
use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga la demo base (kizuna:demo) y encima un volumen grande de datos para probar el motor
 * de programación: más EPS, contratos y poblaciones, dos sedes nuevas con quirófanos y equipos,
 * más cirujanos y anestesiólogos, y cientos de órdenes aptas con casos variados
 * (prioritarias, avales por vencer, pediátricos, diabéticos, ASA III–IV, gestantes, cirujano fijo,
 * contratos limitados a una sede, CUPS que ninguna sede del contrato ofrece...).
 *
 * Todos los nombres de personas y empresas son ficticios. No corre en producción.
 */
class CargarDemoMasiva extends Command
{
    protected $signature = 'kizuna:demo-masiva
        {--ordenes=200 : Órdenes aptas a crear para el motor}
        {--afiliados=600 : Pacientes afiliados repartidos entre las poblaciones nuevas}
        {--force : No pedir confirmación}';

    protected $description = 'Carga la demo base y un volumen grande de EPS, poblaciones, personal y órdenes aptas para probar el motor de programación.';

    private const CODIGOS = ['512104', '471110', '530002', '534001'];

    private const DIAGNOSTICOS = ['512104' => 'K802', '471110' => 'K359', '530002' => 'K409', '534001' => 'K429'];

    private Carbon $hoy;

    private int $documento = 52000000;

    /** @var array<string, Cups> */
    private array $cups = [];

    public function handle(OrdenServicio $ordenes, CalculadoraPreanestesia $calculadora): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no se puede ejecutar en producción.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Se borrarán los datos operativos y se cargará la demo masiva. ¿Continuar?')) {
            return self::SUCCESS;
        }

        $this->call('kizuna:demo', ['--force' => true]);

        $this->hoy = now()->startOfDay();
        mt_srand(4242);
        foreach (self::CODIGOS as $codigo) {
            $this->cups[$codigo] = Cups::where('codigo', $codigo)->firstOrFail();
        }

        DB::transaction(function () use ($ordenes, $calculadora) {
            $sedes = $this->sedes();
            $personal = $this->personal($sedes);
            $this->inventario($sedes);
            $contratos = $this->contratacion($sedes);
            $pacientes = $this->poblaciones($contratos);
            $this->ordenes($ordenes, $calculadora, $contratos, $pacientes, $personal);
            $this->agotarContrato($contratos, $pacientes, $personal, $sedes);
        });

        $this->newLine();
        $this->info('Demo masiva cargada.');
        $this->table(['Qué', 'Total'], [
            ['Sedes', Sede::count()],
            ['Quirófanos', Sala::where('tipo', 'QUIROFANO')->count()],
            ['Cirujanos', Especialista::whereHas('especialidades', fn ($q) => $q->where('codigo', 'cirugia-general'))->count()],
            ['Anestesiólogos', Especialista::whereHas('especialidades', fn ($q) => $q->where('codigo', 'anestesiologia'))->count()],
            ['EPS', Entidad::count()],
            ['Contratos', Contrato::count()],
            ['Poblaciones', Poblacion::count()],
            ['Pacientes', Paciente::count()],
            ['Órdenes aptas con aval vigente', OrdenQuirurgica::conAvalVigente()->count()],
        ]);

        return self::SUCCESS;
    }

    /**
     * Dos sedes nuevas fuera de Cali (para que la cercanía al paciente pese) y un tercer quirófano en la Sede Norte.
     *
     * @return array<string, Sede>
     */
    private function sedes(): array
    {
        $this->info('Sedes y quirófanos nuevos…');
        $norte = Sede::where('nombre', 'Sede Norte')->firstOrFail();
        $sur = Sede::where('nombre', 'Sede Sur')->firstOrFail();
        $prestador = Prestador::firstOrFail();
        $base = ['prestador_id' => $prestador->id, 'dias_atencion' => [1, 2, 3, 4, 5, 6], 'hora_apertura' => '06:00', 'hora_cierre' => '20:00', 'activo' => true, 'es_principal' => false];

        $palmira = Sede::create($base + ['numero_sede' => '03', 'nombre' => 'Sede Palmira', 'direccion' => 'Cll. 31 # 29-15', 'consultorios' => 8, 'municipio_id' => Municipio::where('codigo', '76520')->value('id')]);
        $jamundi = Sede::create($base + ['numero_sede' => '04', 'nombre' => 'Sede Jamundí', 'direccion' => 'Cra. 10 # 12-40', 'consultorios' => 5, 'municipio_id' => Municipio::where('codigo', '76364')->value('id')]);

        Sala::create(['sede_id' => $norte->id, 'codigo' => 'Q3', 'nombre' => 'Quirófano 3', 'tipo' => 'QUIROFANO', 'activo' => true]);
        foreach ([$palmira, $jamundi] as $sede) {
            foreach (['Q1' => 'Quirófano 1', 'Q2' => 'Quirófano 2'] as $codigo => $nombre) {
                Sala::create(['sede_id' => $sede->id, 'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'QUIROFANO', 'activo' => true]);
            }
        }

        // Jamundí no hace herniorrafia inguinal laparoscópica (530002): sirve para el caso "ninguna sede del contrato ofrece el CUPS".
        $portafolio = ['512104' => 90, '471110' => 75, '530002' => 90, '534001' => 60];
        foreach ([$palmira, $jamundi] as $sede) {
            foreach ($portafolio as $codigo => $duracion) {
                if ($sede === $jamundi && $codigo === '530002') {
                    continue;
                }
                PortafolioItem::create(['sede_id' => $sede->id, 'cups_id' => $this->cups[$codigo]->id, 'duracion_minutos' => $duracion, 'tipo_sala' => 'QUIROFANO', 'activo' => true]);
            }
            PortafolioItem::create(['sede_id' => $sede->id, 'cups_id' => Cups::where('codigo', '890226')->value('id'), 'duracion_minutos' => 30, 'tipo_sala' => null, 'activo' => true]);
        }

        return compact('norte', 'sur', 'palmira', 'jamundi');
    }

    /**
     * @param  array<string, Sede>  $s
     * @return array{cirujanos: list<Especialista>, anestesiologos: list<Especialista>}
     */
    private function personal(array $s): array
    {
        $this->info('Cirujanos y anestesiólogos nuevos…');
        $cg = Especialidad::where('codigo', 'cirugia-general')->value('id');
        $an = Especialidad::where('codigo', 'anestesiologia')->value('id');
        $lv = [1, 2, 3, 4, 5];
        $desde = $this->hoy->copy()->subMonth()->toDateString();

        // [nombres, apellidos, especialidad, franjas [sede, días, inicio, fin]]
        $definicion = [
            ['Camilo Andrés', 'Benítez Orozco', $cg, [['palmira', $lv, '07:00', '13:00']]],
            ['Mariana', 'Escobar Trujillo', $cg, [['palmira', $lv, '13:00', '19:00']]],
            ['Sebastián', 'Arango Peña', $cg, [['jamundi', [1, 2, 3], '07:00', '15:00'], ['sur', [4, 5], '07:00', '13:00']]],
            ['Daniela', 'Correa Montoya', $cg, [['jamundi', [3, 4, 5], '07:00', '15:00']]],
            ['Felipe', 'Gutiérrez Salcedo', $cg, [['norte', $lv, '07:00', '13:00']]],
            ['Laura Victoria', 'Henao Bedoya', $cg, [['norte', [1, 3, 5], '13:00', '19:00'], ['sur', [2, 4], '13:00', '18:00']]],
            ['Tomás', 'Villegas Ruiz', $cg, [['palmira', [6], '07:00', '13:00'], ['norte', [6], '07:00', '13:00']]],
            ['Juliana', 'Ramírez Sierra', $an, [['palmira', $lv, '06:30', '13:00'], ['palmira', $lv, '13:00', '19:00']]],
            ['Esteban', 'Molina Cuéllar', $an, [['palmira', $lv, '06:30', '13:00'], ['palmira', $lv, '13:00', '19:00']]],
            ['Carolina', 'Duque Agudelo', $an, [['jamundi', $lv, '06:30', '15:00']]],
            ['Mateo', 'Franco Lozano', $an, [['jamundi', $lv, '06:30', '15:00'], ['palmira', [6], '06:30', '13:00']]],
            ['Isabel', 'Naranjo Pérez', $an, [['norte', $lv, '06:30', '13:00'], ['norte', $lv, '13:00', '19:00'], ['norte', [6], '06:30', '13:00']]],
            ['Ricardo', 'Sanclemente Vivas', $an, [['sur', $lv, '13:00', '18:00'], ['sur', [6], '06:30', '13:00']]],
        ];

        $cc = TipoDocumento::where('codigo', 'CC')->value('id');
        $resultado = ['cirujanos' => [], 'anestesiologos' => []];
        foreach ($definicion as $n => [$nombres, $apellidos, $especialidad, $franjas]) {
            $e = Especialista::create([
                'tipo_documento_id' => $cc, 'numero_documento' => (string) (80100000 + $n * 7919), 'nombres' => $nombres, 'apellidos' => $apellidos,
                'registro_profesional' => 'RM-76-'.(20000 + $n), 'correo' => 'esp'.($n + 1).'@vidaplena.co', 'activo' => true,
            ]);
            $e->especialidades()->attach($especialidad);
            foreach ($franjas as [$sede, $dias, $inicio, $fin]) {
                $e->agendas()->create(['sede_id' => $s[$sede]->id, 'especialidad_id' => $especialidad, 'dias' => $dias, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'vigente_desde' => $desde, 'activo' => true]);
            }
            $resultado[$especialidad === $cg ? 'cirujanos' : 'anestesiologos'][] = $e;
        }
        // Una vacaciones larga para ver cómo el motor reparte la carga.
        $resultado['cirujanos'][4]->ausencias()->create(['tipo' => 'VACACIONES', 'fecha_inicio' => $this->hoy->copy()->addDays(10)->toDateString(), 'fecha_fin' => $this->hoy->copy()->addDays(24)->toDateString(), 'observacion' => 'Vacaciones']);

        return $resultado;
    }

    /**
     * Equipos, cajas e insumos para las sedes nuevas y el Q3 de Norte. En Jamundí Q2 la máquina de anestesia
     * tiene la calibración vencida: ese quirófano no se puede usar hasta que se calibre.
     *
     * @param  array<string, Sede>  $s
     */
    private function inventario(array $s): void
    {
        $this->info('Inventario de las sedes nuevas…');
        $i = Item::all()->keyBy('codigo');
        $en = fn (int $dias) => $this->hoy->copy()->addDays($dias)->toDateString();
        $salas = Sala::all()->keyBy(fn ($x) => $x->sede_id.'-'.$x->codigo);
        $n = 500;
        $unidad = function (string $item, Sede $sede, ?string $sala, ?string $calibracion = 'ok') use ($i, $en, $salas, &$n) {
            $n++;
            Unidad::create([
                'item_id' => $i[$item]->id, 'sede_id' => $sede->id, 'sala_id' => $sala ? $salas[$sede->id.'-'.$sala]->id : null,
                'codigo' => ($i[$item]->tipo === 'INSTRUMENTAL' ? 'CJ-' : 'BIO-').str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'marca' => 'Genérica', 'modelo' => 'Demo',
                'estado' => 'OPERATIVO', 'proximo_mantenimiento' => $i[$item]->tipo === 'EQUIPO' ? $en(mt_rand(30, 180)) : null,
                'calibracion_vence' => $i[$item]->requiere_calibracion ? ($calibracion === 'ok' ? $en(mt_rand(120, 300)) : $calibracion) : null,
            ]);
        };

        $unidad('EQ-MAQ-ANES', $s['norte'], 'Q3');
        $unidad('EQ-MONITOR', $s['norte'], 'Q3');
        $unidad('EQ-TORRE-LAP', $s['norte'], null);
        $unidad('IN-CAJA-LAP', $s['norte'], null);

        foreach (['palmira', 'jamundi'] as $clave) {
            $sede = $s[$clave];
            foreach (['Q1', 'Q2'] as $sala) {
                $calibracion = $clave === 'jamundi' && $sala === 'Q2' ? $en(-20) : 'ok';
                $unidad('EQ-MAQ-ANES', $sede, $sala, $calibracion);
                $unidad('EQ-MONITOR', $sede, $sala);
            }
            foreach (['EQ-TORRE-LAP', 'EQ-TORRE-LAP', 'EQ-ELECTRO', 'EQ-ELECTRO', 'IN-CAJA-LAP', 'IN-CAJA-LAP', 'IN-CAJA-LAP', 'IN-CAJA-CG', 'IN-CAJA-CG'] as $item) {
                $unidad($item, $sede, null);
            }
            // Palmira bien surtida; Jamundí con pocas mallas para que se agoten.
            foreach (['IS-TROC-10' => 200, 'IS-TROC-5' => 200, 'IS-CLIP-TI' => 80, 'IS-BOLSA-EXT' => 80, 'IS-MALLA-PP' => $clave === 'jamundi' ? 4 : 60, 'IS-SUT-PRO' => 100] as $item => $cantidad) {
                Existencia::create(['item_id' => $i[$item]->id, 'sede_id' => $sede->id, 'lote' => 'DM'.mt_rand(1000, 9999), 'vence' => $en(mt_rand(200, 600)), 'cantidad' => $cantidad]);
            }
        }
        // Más insumos en Norte y Sur para que no se agoten de inmediato con tantas órdenes.
        foreach (['norte', 'sur'] as $clave) {
            foreach (['IS-TROC-10' => 120, 'IS-TROC-5' => 120, 'IS-CLIP-TI' => 50, 'IS-BOLSA-EXT' => 50, 'IS-MALLA-PP' => 30, 'IS-SUT-PRO' => 60] as $item => $cantidad) {
                Existencia::create(['item_id' => $i[$item]->id, 'sede_id' => $s[$clave]->id, 'lote' => 'DM'.mt_rand(1000, 9999), 'vence' => $en(mt_rand(200, 600)), 'cantidad' => $cantidad]);
            }
        }
    }

    /**
     * EPS nuevas con contratos de distintas modalidades y sedes. Los contratos de la demo base se mantienen.
     *
     * @param  array<string, Sede>  $s
     * @return list<Contrato>
     */
    private function contratacion(array $s): array
    {
        $this->info('EPS y contratos nuevos…');
        $contributivo = Regimen::where('codigo', 'CONTRIBUTIVO')->value('id');
        $subsidiado = Regimen::where('codigo', 'SUBSIDIADO')->value('id');
        $modalidad = ModalidadContratacion::pluck('id', 'codigo');
        $inicioAnio = $this->hoy->copy()->startOfYear()->toDateString();
        $finAnio = $this->hoy->copy()->endOfYear()->toDateString();

        // [razón social, sigla, régimen, modalidad, sedes, cantidad PGP base]
        $eps = [
            ['Vitalia EPS S.A.', 'VITALIA', $contributivo, 'PGP', ['norte', 'sur', 'palmira'], 260],
            ['Salud del Pacífico EPS', 'SPACIFICO', $subsidiado, 'CAPITA', ['sur', 'jamundi'], null],
            ['Comunidad Sana EPS-S', 'CSANA', $subsidiado, 'PGP', ['palmira', 'jamundi'], 140],
            ['Integral Salud EPS S.A.S.', 'INTEGRAL', $contributivo, 'EVENTO', ['norte', 'palmira'], null],
            ['Bienestar Total EPS', 'BTOTAL', $contributivo, 'EVENTO', ['norte'], null],
            ['Familia Saludable EPS', 'FSALUDABLE', $subsidiado, 'CAPITA', ['palmira'], null],
            ['Horizonte EPS S.A.', 'HORIZONTE', $contributivo, 'PGP', ['norte', 'sur', 'palmira', 'jamundi'], 400],
            ['Valle Salud EPS-S', 'VSALUD', $subsidiado, 'EVENTO', ['jamundi'], null],
            ['Medisur EPS', 'MEDISUR', $contributivo, 'CAPITA', ['sur'], null],
        ];

        $contratos = Contrato::with('sedes:id')->get()->all();
        foreach ($eps as $n => [$razon, $sigla, $regimen, $mod, $sedes, $cantidad]) {
            $nit = (string) (901300000 + $n * 1117);
            $e = Entidad::create(['nit' => $nit, 'digito_verificacion' => (string) $this->dv($nit), 'razon_social' => $razon, 'sigla' => $sigla, 'codigo_minsalud' => 'EPSD'.str_pad((string) ($n + 10), 2, '0', STR_PAD_LEFT), 'tipo' => 'EPS', 'activo' => true]);
            $e->regimenes()->sync([$regimen]);
            // Casos de contrato: Valle Salud vence en 5 días; Bienestar Total tiene un valor pequeño que se agota (ver agotarContrato).
            $c = Contrato::create([
                'entidad_id' => $e->id, 'numero' => 'CT-2026-'.(200 + $n), 'modalidad_contratacion_id' => $modalidad[$mod], 'regimen_id' => $regimen,
                'fecha_inicio' => $inicioAnio, 'fecha_fin' => $sigla === 'VSALUD' ? $this->hoy->copy()->addDays(5)->toDateString() : $finAnio,
                'valor' => $sigla === 'BTOTAL' ? 25000000 : mt_rand(8, 60) * 100000000, 'objeto' => 'Cirugía general ambulatoria', 'activo' => true,
            ]);
            $c->sedes()->sync(collect($sedes)->map(fn ($x) => $s[$x]->id));
            foreach (self::CODIGOS as $codigo) {
                $c->cups()->attach($this->cups[$codigo]->id, [
                    'cantidad' => $mod === 'PGP' ? (int) round($cantidad * ['512104' => 1, '471110' => 0.4, '530002' => 0.5, '534001' => 0.45][$codigo]) : null,
                    'tarifa' => $mod === 'EVENTO' ? ['512104' => 3700000, '471110' => 3000000, '530002' => 2850000, '534001' => 1600000][$codigo] : null,
                ]);
            }
            $contratos[] = $c->load('sedes:id');
        }

        return $contratos;
    }

    /**
     * Varias poblaciones por contrato nuevo, con afiliados en distintos municipios.
     *
     * @param  list<Contrato>  $contratos
     * @return array<int, list<Paciente>> pacientes por contrato
     */
    private function poblaciones(array $contratos): array
    {
        $this->info('Poblaciones y afiliados…');
        $nombresF = ['María', 'Luz', 'Carmen', 'Gloria', 'Diana', 'Sandra', 'Paola', 'Natalia', 'Adriana', 'Claudia', 'Marcela', 'Liliana', 'Ángela', 'Yolanda', 'Beatriz', 'Valeria', 'Sofía', 'Camila', 'Lorena', 'Mónica'];
        $nombresM = ['Carlos', 'Jorge', 'Luis', 'Andrés', 'Diego', 'Fernando', 'Javier', 'Óscar', 'Ricardo', 'Hernán', 'Fabio', 'Wilson', 'Mauricio', 'Edwin', 'Álvaro', 'Samuel', 'Martín', 'Julián', 'Iván', 'Gustavo'];
        $apellidos = ['Gómez', 'Rodríguez', 'López', 'Martínez', 'García', 'Hernández', 'Valencia', 'Ospina', 'Cárdenas', 'Mosquera', 'Zapata', 'Lenis', 'Quintero', 'Arboleda', 'Castaño', 'Salazar', 'Patiño', 'Muñoz', 'Rincón', 'Caicedo', 'Bermúdez', 'Toro', 'Cifuentes', 'Rendón', 'Palacios'];
        $municipios = Municipio::whereIn('codigo', ['76001', '76520', '76364', '76892', '76130', '76834'])->pluck('id', 'codigo');
        $cohortes = ['Hipertensión', 'Diabetes', 'Riesgo cardiovascular', 'Enfermedad renal', 'Obesidad', 'Gestantes', ''];
        $cc = TipoDocumento::where('codigo', 'CC')->value('id');
        $ti = TipoDocumento::where('codigo', 'TI')->value('id');
        $sedeMunicipio = Sede::pluck('municipio_id', 'id');

        $nuevos = array_values(array_filter($contratos, fn (Contrato $c) => str_starts_with($c->numero, 'CT-2026-2')));
        $porContrato = [];
        $afiliados = (int) $this->option('afiliados');
        $porPoblacion = max(10, intdiv($afiliados, count($nuevos) * 2));

        foreach ($contratos as $contrato) {
            // Los contratos de la demo base ya tienen población; se toman sus pacientes.
            $porContrato[$contrato->id] = Paciente::whereHas('poblaciones', fn ($q) => $q->where('contrato_id', $contrato->id))->get()->all();
        }

        foreach ($nuevos as $contrato) {
            $locales = $contrato->sedes->map(fn ($s) => $sedeMunicipio[$s->id])->all();
            foreach (['Afiliados régimen base', 'Programa crónicos'] as $k => $nombre) {
                $poblacion = Poblacion::create([
                    'contrato_id' => $contrato->id, 'nombre' => $nombre.' '.$contrato->numero, 'descripcion' => 'Base de prueba para el motor', 'activo' => true,
                    'ultimo_cargue_en' => $this->hoy->copy()->subDays(mt_rand(1, 40)),
                ]);
                for ($n = 0; $n < $porPoblacion; $n++) {
                    $mujer = mt_rand(0, 1) === 1;
                    $edad = match (true) { mt_rand(1, 100) <= 8 => mt_rand(3, 17), mt_rand(1, 100) <= 25 => mt_rand(65, 88), default => mt_rand(18, 64) };
                    $municipio = mt_rand(1, 100) <= 75 ? $locales[array_rand($locales)] : $municipios->random();
                    $p = Paciente::create([
                        'tipo_documento_id' => $edad < 18 ? $ti : $cc,
                        'numero_documento' => (string) ($edad < 18 ? 1108000000 + ($this->documento += mt_rand(50, 900)) % 900000000 : ($this->documento += mt_rand(50, 900))),
                        'primer_nombre' => ($mujer ? $nombresF : $nombresM)[mt_rand(0, 19)], 'primer_apellido' => $apellidos[array_rand($apellidos)], 'segundo_apellido' => $apellidos[array_rand($apellidos)],
                        'fecha_nacimiento' => $this->hoy->copy()->subYears($edad)->subDays(mt_rand(0, 360))->toDateString(), 'sexo' => $mujer ? 'F' : 'M',
                        'telefono' => '3'.mt_rand(100000000, 209999999), 'municipio_id' => $municipio,
                    ]);
                    $c = $k === 1 ? $cohortes[mt_rand(0, 5)] : $cohortes[array_rand($cohortes)];
                    $poblacion->pacientes()->attach($p->id, ['cohortes' => $c ? json_encode([$c], JSON_UNESCAPED_UNICODE) : null, 'activo' => true]);
                    $porContrato[$contrato->id][] = $p;
                }
            }
        }

        return $porContrato;
    }

    /**
     * Órdenes aptas con historia de pre-anestesia finalizada y aval vigente, con casos variados.
     *
     * @param  list<Contrato>  $contratos
     * @param  array<int, list<Paciente>>  $pacientes
     * @param  array{cirujanos: list<Especialista>, anestesiologos: list<Especialista>}  $personal
     */
    private function ordenes(OrdenServicio $servicio, CalculadoraPreanestesia $calculadora, array $contratos, array $pacientes, array $personal): void
    {
        $total = (int) $this->option('ordenes');
        $this->info("{$total} órdenes aptas con aval…");
        $version = PlantillaHc::with('versionVigente')->where('codigo', 'preanestesia')->firstOrFail()->versionVigente;
        $cg = Especialidad::where('codigo', 'cirugia-general')->value('id');
        $usuario = User::where('email', 'paula.mejia@kizuna.com')->value('id');
        $vigencia = [1 => 180, 2 => 180, 3 => 90, 4 => 30];
        $usados = [];

        $base = [
            'riesgo_quirurgico' => 'INTERMEDIO', 'anestesia_propuesta' => 'GENERAL', 'opioides_postoperatorios' => true, 'hta' => false, 'diabetes' => false,
            'cardiopatia_isquemica' => false, 'insuficiencia_cardiaca' => false, 'acv' => false, 'epoc_asma' => false, 'fumador' => false, 'nvpo_previa' => false,
            'alergias' => false, 'peso' => 72, 'talla' => 165, 'pa_sistolica' => 122, 'pa_diastolica' => 78, 'frecuencia_cardiaca' => 74, 'spo2' => 97,
            'ronquido' => false, 'cansancio_diurno' => false, 'apneas_observadas' => false, 'mallampati' => '1', 'movilidad_cervical' => 'NORMAL',
            'paraclinicos' => [['examen' => 'HEMOGRAMA', 'fecha' => $this->hoy->copy()->subDays(20)->toDateString(), 'resultado' => 'Normal']],
            'concepto' => 'APTO', 'ayuno' => 'Sólidos 8 horas, líquidos claros 2 horas', 'consentimiento' => true,
        ];

        $bar = $this->output->createProgressBar($total);
        for ($n = 0; $n < $total; $n++) {
            $contrato = $contratos[array_rand($contratos)];
            $lista = $pacientes[$contrato->id] ?? [];
            $paciente = null;
            for ($intento = 0; $intento < 20 && $lista; $intento++) {
                $candidato = $lista[array_rand($lista)];
                if (! isset($usados[$candidato->id])) {
                    $paciente = $candidato;
                    break;
                }
            }
            if (! $paciente) {
                $n--;

                continue;
            }
            $usados[$paciente->id] = true;

            // Las de herniorrafia inguinal (530002) en el contrato solo de Jamundí no tendrán sede: caso "ninguna sede ofrece el CUPS".
            $codigo = self::CODIGOS[array_rand(self::CODIGOS)];
            $edad = $paciente->fecha_nacimiento->age;
            $asa = match (true) { mt_rand(1, 100) <= 40 => 1, mt_rand(1, 100) <= 70 => 2, mt_rand(1, 100) <= 85 => 3, default => 4 };
            if ($edad < 18) {
                $asa = min($asa, 2);
            }
            // Valoración entre hace 1 y casi todo el aval: así hay avales recién dados y otros a punto de vencer.
            $diasValoracion = mt_rand(1, $vigencia[$asa] - 2);
            if (mt_rand(1, 100) <= 12) {
                $diasValoracion = $vigencia[$asa] - mt_rand(3, 12);
            }
            $fechaValoracion = $this->hoy->copy()->subDays($diasValoracion)->setTime(mt_rand(7, 17), 0);
            $diasOrden = $diasValoracion + mt_rand(3, 60);

            $cambios = ['asa' => (string) $asa];
            if ($asa >= 2 && mt_rand(1, 100) <= 50) {
                $cambios += ['hta' => true, 'hta_controlada' => true];
            }
            if (mt_rand(1, 100) <= 18) {
                $cambios += ['diabetes' => true, 'diabetes_controlada' => true];
            }
            if ($paciente->sexo === 'F' && $edad >= 18 && $edad <= 42 && mt_rand(1, 100) <= 15) {
                $cambios['embarazo'] = true;
            }
            if ($edad < 18) {
                $cambios += ['peso' => mt_rand(15, 55), 'talla' => mt_rand(100, 165), 'riesgo_quirurgico' => 'BAJO'];
            }
            $diasSuspension = 0;
            if (mt_rand(1, 100) <= 10) {
                $diasSuspension = mt_rand(5, 7);
                $cambios += ['medicamentos' => [['nombre' => 'Warfarina 5 mg', 'dosis' => '1 diaria']], 'concepto' => 'APTO_CON_RECOMENDACIONES', 'recomendaciones' => "Suspender warfarina {$diasSuspension} días antes.", 'dias_suspension' => $diasSuspension];
            }
            $respuestas = array_merge($base, $cambios);

            $cirujano = mt_rand(1, 100) <= 10 ? $personal['cirujanos'][array_rand($personal['cirujanos'])]->id : null;
            $anestesiologo = $personal['anestesiologos'][array_rand($personal['anestesiologos'])];

            $orden = OrdenQuirurgica::create([
                'paciente_id' => $paciente->id, 'contrato_id' => $contrato->id, 'cups_id' => $this->cups[$codigo]->id, 'especialidad_id' => $cg,
                'cirujano_id' => $cirujano, 'diagnostico_cie10' => self::DIAGNOSTICOS[$codigo],
                'prioridad' => mt_rand(1, 100) <= 12 ? 'PRIORITARIA' : 'ELECTIVA', 'fecha_orden' => $this->hoy->copy()->subDays($diasOrden)->toDateString(),
                'medico_ordenante' => 'Dr(a). '.$personal['cirujanos'][array_rand($personal['cirujanos'])]->apellidos,
                'origen' => 'API', 'sistema_origen' => 'HIS Masivo', 'referencia_externa' => 'OQM-'.(50000 + $n), 'estado' => 'CITA_ASIGNADA',
            ]);

            $resultado = $calculadora->calcular($respuestas, ['paciente' => ['sexo' => $paciente->sexo, 'edad' => $edad]]);
            $historia = HistoriaClinica::create([
                'paciente_id' => $paciente->id, 'plantilla_version_id' => $version->id, 'especialista_id' => $anestesiologo->id, 'orden_id' => $orden->id,
                'origen' => 'KIZUNA', 'estado' => 'FINALIZADA', 'respuestas' => $respuestas, 'resultado' => $resultado,
                'finalizada_en' => $fechaValoracion->copy()->addMinutes(25), 'finalizada_por' => $usuario, 'creada_por' => $usuario,
            ]);
            $servicio->aplicarConcepto($orden, $historia->id, $respuestas['concepto'], $asa, $fechaValoracion, $diasSuspension);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /**
     * Cirugías ya realizadas en el contrato por evento de Bienestar Total, por más de su valor:
     * sus órdenes pendientes muestran "Valor del contrato agotado" y bajan de prioridad.
     *
     * @param  list<Contrato>  $contratos
     * @param  array<int, list<Paciente>>  $pacientes
     * @param  array{cirujanos: list<Especialista>, anestesiologos: list<Especialista>}  $personal
     * @param  array<string, Sede>  $sedes
     */
    private function agotarContrato(array $contratos, array $pacientes, array $personal, array $sedes): void
    {
        $contrato = collect($contratos)->first(fn (Contrato $c) => $c->entidad?->sigla === 'BTOTAL');
        if (! $contrato) {
            return;
        }
        $sala = Sala::where('sede_id', $sedes['norte']->id)->where('codigo', 'Q1')->value('id');
        $ahora = now();
        foreach (array_slice($pacientes[$contrato->id] ?? [], 0, 8) as $n => $paciente) {
            $fecha = $this->hoy->copy()->subDays(10 + $n * 7);
            while ($fecha->isSunday()) {
                $fecha->subDay();
            }
            $ordenId = DB::table('ordenes_quirurgicas')->insertGetId([
                'paciente_id' => $paciente->id, 'contrato_id' => $contrato->id, 'cups_id' => $this->cups['512104']->id, 'especialidad_id' => Especialidad::where('codigo', 'cirugia-general')->value('id'),
                'diagnostico_cie10' => 'K802', 'prioridad' => 'ELECTIVA', 'fecha_orden' => $fecha->copy()->subDays(30)->toDateString(), 'origen' => 'API', 'sistema_origen' => 'HIS Masivo',
                'referencia_externa' => 'OQM-H-'.$n, 'estado' => 'OPERADA', 'concepto' => 'APTO', 'asa' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('cirugias')->insert([
                'orden_id' => $ordenId, 'paciente_id' => $paciente->id, 'cups_id' => $this->cups['512104']->id, 'sede_id' => $sedes['norte']->id, 'sala_id' => $sala,
                'cirujano_id' => $personal['cirujanos'][4]->id, 'anestesiologo_id' => $personal['anestesiologos'][4]->id, 'fecha' => $fecha->toDateString(),
                'hora_inicio' => '07:00', 'hora_fin' => '08:30', 'estado' => 'REALIZADA', 'puntaje' => 0, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

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
