<?php

namespace App\Console\Commands;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Services\OrdenServicio;
use App\Modules\Citas\Models\Cita;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Entidad;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Contratacion\Models\Poblacion;
use App\Modules\HistoriaClinica\Models\HistoriaClinica;
use App\Modules\HistoriaClinica\Models\HistoriaEvento;
use App\Modules\HistoriaClinica\Models\PlantillaHc;
use App\Modules\HistoriaClinica\Services\CalculadoraPreanestesia;
use App\Modules\Integracion\Models\ClienteIntegracion;
use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\RequerimientoCups;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Deja la base lista para una demostración: borra los datos operativos (no los catálogos,
 * permisos ni plantillas) y carga una IPS ficticia completa, con órdenes en todos los
 * estados del flujo de pre-anestesia y pacientes aptos listos para el motor de programación.
 *
 * Todos los nombres de personas y empresas son ficticios.
 */
class CargarDemo extends Command
{
    protected $signature = 'kizuna:demo {--force : No pedir confirmación}';

    protected $description = 'Borra los datos operativos y carga una IPS de demostración completa (solo en entornos locales).';

    public const CLAVE_DEMO = 'Kizuna2026*';

    private Carbon $hoy;

    private int $cc;

    private int $ti;

    /** @var array<string, int> */
    private array $especialidades = [];

    /** @var array<string, Cups> */
    private array $cups = [];

    public function handle(OrdenServicio $ordenes, CalculadoraPreanestesia $calculadora): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no se puede ejecutar en producción.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Se borrarán prestadores, contratos, pacientes, especialistas, inventario, órdenes, historias y cirugías. ¿Continuar?')) {
            return self::SUCCESS;
        }

        $this->hoy = now()->startOfDay();
        $this->cc = TipoDocumento::where('codigo', 'CC')->value('id');
        $this->ti = TipoDocumento::where('codigo', 'TI')->value('id');
        mt_srand(2026);

        $this->limpiar();

        DB::transaction(function () use ($ordenes, $calculadora) {
            $this->catalogosClinicos();
            [$norte, $sur] = $this->red();
            $personal = $this->personal($norte, $sur);
            $contratos = $this->contratacion($norte, $sur);
            $this->inventario($norte, $sur);
            $pacientes = $this->poblaciones($contratos);
            $this->historico($contratos, $personal, $norte, $sur);
            $this->ordenes($ordenes, $calculadora, $contratos, $pacientes, $personal, $norte, $sur);
        });

        $cliente = ClienteIntegracion::create(['nombre' => 'HIS Vida Plena', 'descripcion' => 'Sistema de historia clínica de la IPS', 'activo' => true]);
        $token = $cliente->createToken('principal', ['ordenes:escribir', 'ordenes:leer', 'historias:escribir', 'inventario:escribir'])->plainTextToken;

        $this->newLine();
        $this->info('Datos de demostración cargados.');
        $this->table(['Cuenta', 'Correo', 'Contraseña'], [
            ['Jefe de cirugía (aprueba la programación)', 'jefe.cirugia@kizuna.com', self::CLAVE_DEMO],
            ['Anestesióloga (diligencia historias)', 'paula.mejia@kizuna.com', self::CLAVE_DEMO],
            ['Coordinadora de programación (operador)', 'coordinacion@kizuna.com', self::CLAVE_DEMO],
        ]);
        $this->line("Token de integración (HIS Vida Plena): {$token}");

        return self::SUCCESS;
    }

    private function limpiar(): void
    {
        $this->info('Limpiando datos operativos…');
        $tablas = [
            'cirugias', 'asignaciones_anestesia', 'programacion_corridas', 'inventario_reservas', 'inventario_movimientos', 'inventario_existencias',
            'inventario_mantenimientos', 'inventario_unidades', 'requerimientos_cups', 'inventario_items', 'historia_eventos',
        ];
        Schema::disableForeignKeyConstraints();
        foreach ($tablas as $t) {
            DB::table($t)->delete();
        }
        DB::table('ordenes_quirurgicas')->update(['historia_id' => null]);
        foreach ([
            'historias_clinicas', 'citas', 'ordenes_quirurgicas', 'poblacion_cargues', 'poblacion_paciente', 'pacientes', 'poblaciones',
            'contrato_cups', 'contrato_sede', 'contratos', 'entidad_regimen', 'entidades', 'ausencias', 'agendas', 'especialidad_especialista',
            'especialistas', 'portafolio_servicios', 'cups_especialidad', 'salas', 'sedes', 'prestadores', 'clientes_integracion', 'ajustes',
        ] as $t) {
            DB::table($t)->delete();
        }
        DB::table('personal_access_tokens')->where('tokenable_type', ClienteIntegracion::class)->delete();
        User::whereIn('email', ['jefe.cirugia@kizuna.com', 'paula.mejia@kizuna.com', 'coordinacion@kizuna.com'])->forceDelete();
        Schema::enableForeignKeyConstraints();
        Cache::flush();
    }

    private function catalogosClinicos(): void
    {
        foreach (['cirugia-general', 'anestesiologia', 'urologia'] as $codigo) {
            $this->especialidades[$codigo] = Especialidad::where('codigo', $codigo)->value('id');
        }
        foreach (['512104', '471110', '530002', '534001', '890226', '890235'] as $codigo) {
            $this->cups[$codigo] = Cups::where('codigo', $codigo)->firstOrFail();
        }
        $this->cups['urologia'] = Cups::where('habilitado', true)->where('nombre_normalizado', 'like', '%RESECCION TRANSURETRAL%PROSTATA%')->first()
            ?? Cups::where('habilitado', true)->where('nombre_normalizado', 'like', '%PROSTAT%')->firstOrFail();

        foreach (['512104', '471110', '530002', '534001', '890235'] as $codigo) {
            DB::table('cups_especialidad')->insert(['cups_id' => $this->cups[$codigo]->id, 'especialidad_id' => $this->especialidades['cirugia-general'], 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cups_especialidad')->insert(['cups_id' => $this->cups['890226']->id, 'especialidad_id' => $this->especialidades['anestesiologia'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cups_especialidad')->insert(['cups_id' => $this->cups['urologia']->id, 'especialidad_id' => $this->especialidades['urologia'], 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array{0: Sede, 1: Sede}
     */
    private function red(): array
    {
        $this->info('Red de atención…');
        $prestador = Prestador::create([
            'nit' => '900654321', 'digito_verificacion' => (string) $this->dv('900654321'), 'razon_social' => 'Clínica Vida Plena IPS S.A.S.', 'nombre_comercial' => 'Clínica Vida Plena',
            'codigo_habilitacion' => '7600101234', 'naturaleza' => 'PRIVADA', 'telefono' => '6023334455', 'correo' => 'contacto@vidaplena.co', 'representante_legal' => 'Luz Marina Cárdenas', 'activo' => true,
        ]);
        $cali = Municipio::where('codigo', '76001')->value('id');
        $base = ['prestador_id' => $prestador->id, 'municipio_id' => $cali, 'dias_atencion' => [1, 2, 3, 4, 5, 6], 'hora_apertura' => '06:00', 'hora_cierre' => '20:00', 'activo' => true];
        $norte = Sede::create($base + ['numero_sede' => '01', 'nombre' => 'Sede Norte', 'direccion' => 'Av. 6N # 28-40', 'consultorios' => 12, 'es_principal' => true]);
        $sur = Sede::create($base + ['numero_sede' => '02', 'nombre' => 'Sede Sur', 'direccion' => 'Cra. 100 # 16-20', 'consultorios' => 6, 'es_principal' => false]);

        foreach ([[$norte, 'Q1', 'Quirófano 1', 'QUIROFANO'], [$norte, 'Q2', 'Quirófano 2', 'QUIROFANO'], [$norte, 'P1', 'Sala de procedimientos', 'SALA_PROCEDIMIENTOS'], [$sur, 'Q1', 'Quirófano 1', 'QUIROFANO']] as [$sede, $codigo, $nombre, $tipo]) {
            Sala::create(['sede_id' => $sede->id, 'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo, 'activo' => true]);
        }

        $portafolio = ['512104' => [90, 'QUIROFANO'], '471110' => [75, 'QUIROFANO'], '530002' => [90, 'QUIROFANO'], '534001' => [60, 'QUIROFANO'], '890226' => [30, null], '890235' => [20, null]];
        foreach ([$norte, $sur] as $sede) {
            foreach ($portafolio as $codigo => [$duracion, $sala]) {
                PortafolioItem::create(['sede_id' => $sede->id, 'cups_id' => $this->cups[$codigo]->id, 'duracion_minutos' => $duracion, 'tipo_sala' => $sala, 'activo' => true]);
            }
        }
        PortafolioItem::create(['sede_id' => $norte->id, 'cups_id' => $this->cups['urologia']->id, 'duracion_minutos' => 90, 'tipo_sala' => 'QUIROFANO', 'activo' => true]);

        return [$norte, $sur];
    }

    /**
     * @return array<string, Especialista>
     */
    private function personal(Sede $norte, Sede $sur): array
    {
        $this->info('Especialistas, agendas y cuentas…');
        $cg = $this->especialidades['cirugia-general'];
        $an = $this->especialidades['anestesiologia'];
        $lv = [1, 2, 3, 4, 5];
        $desde = $this->hoy->copy()->subMonth()->toDateString();

        $definicion = [
            'morales' => ['Andrés Felipe', 'Morales Tascón', '16788123', 'RM-76-10234', $cg, [[$norte, $lv, '07:00', '13:00']]],
            'restrepo' => ['Catalina', 'Restrepo Vélez', '66912456', 'RM-76-11871', $cg, [[$sur, [1, 3, 5], '07:00', '13:00'], [$norte, [2, 4], '07:00', '13:00']]],
            'ocampo' => ['Julián David', 'Ocampo Ríos', '94456789', 'RM-76-12950', $cg, [[$norte, $lv, '13:00', '18:00']]],
            'mejia' => ['Paula Andrea', 'Mejía Londoño', '31298456', 'RM-76-09876', $an, [[$norte, $lv, '06:30', '13:00'], [$norte, $lv, '14:00', '18:00']]],
            'herrera' => ['Santiago', 'Herrera Duque', '14675321', 'RM-76-10567', $an, [[$norte, $lv, '06:30', '13:00'], [$norte, $lv, '13:00', '19:00']]],
            'rojas' => ['Valentina', 'Rojas Caicedo', '67023987', 'RM-76-12113', $an, [[$sur, $lv, '06:30', '13:00'], [$sur, $lv, '14:00', '17:00']]],
        ];

        $personal = [];
        foreach ($definicion as $clave => [$nombres, $apellidos, $documento, $registro, $especialidad, $franjas]) {
            $e = Especialista::create([
                'tipo_documento_id' => $this->cc, 'numero_documento' => $documento, 'nombres' => $nombres, 'apellidos' => $apellidos,
                'registro_profesional' => $registro, 'correo' => strtolower(explode(' ', $nombres)[0]).'.'.$clave.'@vidaplena.co', 'activo' => true,
            ]);
            $e->especialidades()->attach($especialidad);
            foreach ($franjas as [$sede, $dias, $inicio, $fin]) {
                $e->agendas()->create(['sede_id' => $sede->id, 'especialidad_id' => $especialidad, 'dias' => $dias, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'vigente_desde' => $desde, 'activo' => true]);
            }
            $personal[$clave] = $e;
        }
        // Una novedad para mostrar en la agenda
        $personal['ocampo']->ausencias()->create(['tipo' => 'CAPACITACION', 'fecha_inicio' => $this->hoy->copy()->addDays(8)->toDateString(), 'fecha_fin' => $this->hoy->copy()->addDays(9)->toDateString(), 'observacion' => 'Congreso de cirugía laparoscópica']);

        $cuentas = [
            ['Mónica Salazar', 'jefe.cirugia@kizuna.com', 'administrador', null],
            ['Paula Andrea Mejía', 'paula.mejia@kizuna.com', 'profesional', $personal['mejia']],
            ['Laura Giraldo', 'coordinacion@kizuna.com', 'operador', null],
        ];
        foreach ($cuentas as [$nombre, $correo, $rol, $especialista]) {
            $u = User::create(['name' => $nombre, 'email' => $correo, 'password' => Hash::make(self::CLAVE_DEMO), 'activo' => true, 'email_verified_at' => now()]);
            $u->assignRole($rol);
            $especialista?->update(['user_id' => $u->id]);
        }

        return $personal;
    }

    /**
     * @return array<string, Contrato>
     */
    private function contratacion(Sede $norte, Sede $sur): array
    {
        $this->info('Entidades y contratos…');
        $contributivo = Regimen::where('codigo', 'CONTRIBUTIVO')->value('id');
        $subsidiado = Regimen::where('codigo', 'SUBSIDIADO')->value('id');
        $modalidad = fn (string $c) => ModalidadContratacion::where('codigo', $c)->value('id');

        $entidades = [
            'andina' => ['901234560', 'Salud Andina EPS S.A.', 'SANDINA', 'EPSD01', [$contributivo, $subsidiado]],
            'previsalud' => ['900765432', 'Previsalud EPS S.A.S.', 'PREVISALUD', 'EPSD02', [$contributivo]],
            'esperanza' => ['901112223', 'Nueva Esperanza EPS', 'NESPERANZA', 'EPSD03', [$subsidiado]],
        ];
        $creadas = [];
        foreach ($entidades as $clave => [$nit, $razon, $sigla, $codigo, $regimenes]) {
            $e = Entidad::create(['nit' => $nit, 'digito_verificacion' => (string) $this->dv($nit), 'razon_social' => $razon, 'sigla' => $sigla, 'codigo_minsalud' => $codigo, 'tipo' => 'EPS', 'activo' => true]);
            $e->regimenes()->sync($regimenes);
            $creadas[$clave] = $e;
        }

        $inicioAnio = $this->hoy->copy()->startOfYear()->toDateString();
        $finAnio = $this->hoy->copy()->endOfYear()->toDateString();
        $contratos = [
            'pgp' => [$creadas['andina'], 'CT-2026-101', 'PGP', $contributivo, 4850000000, [$norte, $sur], $inicioAnio, $finAnio],
            'evento' => [$creadas['previsalud'], 'CT-2026-102', 'EVENTO', $contributivo, 1200000000, [$norte], $this->hoy->copy()->subMonths(4)->toDateString(), $this->hoy->copy()->addDays(20)->toDateString()],
            'capita' => [$creadas['esperanza'], 'CT-2026-103', 'CAPITA', $subsidiado, 2300000000, [$sur, $norte], $inicioAnio, $finAnio],
        ];
        $resultado = [];
        foreach ($contratos as $clave => [$entidad, $numero, $mod, $regimen, $valor, $sedes, $inicio, $fin]) {
            $c = Contrato::create(['entidad_id' => $entidad->id, 'numero' => $numero, 'modalidad_contratacion_id' => $modalidad($mod), 'regimen_id' => $regimen, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'valor' => $valor, 'objeto' => 'Cirugía general ambulatoria y hospitalaria', 'activo' => true]);
            $c->sedes()->sync(collect($sedes)->pluck('id'));
            foreach (['512104' => 320, '471110' => 120, '530002' => 180, '534001' => 150] as $codigo => $cantidad) {
                $c->cups()->attach($this->cups[$codigo]->id, ['cantidad' => $mod === 'PGP' ? $cantidad : null, 'tarifa' => $mod === 'EVENTO' ? [512104 => 3850000, 471110 => 3100000, 530002 => 2900000, 534001 => 1650000][(int) $codigo] : null]);
            }
            $resultado[$clave] = $c;
        }

        return $resultado;
    }

    private function inventario(Sede $norte, Sede $sur): void
    {
        $this->info('Inventario de biomédicos y central…');
        $item = fn (array $d) => Item::create($d + ['activo' => true]);
        $i = [
            'torre' => $item(['tipo' => 'EQUIPO', 'codigo' => 'EQ-TORRE-LAP', 'nombre' => 'Torre de laparoscopia', 'clasificacion_riesgo' => 'IIB', 'periodicidad_mantenimiento_meses' => 6]),
            'maquina' => $item(['tipo' => 'EQUIPO', 'codigo' => 'EQ-MAQ-ANES', 'nombre' => 'Máquina de anestesia', 'clasificacion_riesgo' => 'IIB', 'requiere_calibracion' => true, 'periodicidad_mantenimiento_meses' => 6, 'periodicidad_calibracion_meses' => 12]),
            'electro' => $item(['tipo' => 'EQUIPO', 'codigo' => 'EQ-ELECTRO', 'nombre' => 'Unidad electroquirúrgica', 'clasificacion_riesgo' => 'IIB', 'periodicidad_mantenimiento_meses' => 6]),
            'monitor' => $item(['tipo' => 'EQUIPO', 'codigo' => 'EQ-MONITOR', 'nombre' => 'Monitor multiparámetro', 'clasificacion_riesgo' => 'IIB', 'requiere_calibracion' => true, 'periodicidad_mantenimiento_meses' => 6, 'periodicidad_calibracion_meses' => 12]),
            'caja_lap' => $item(['tipo' => 'INSTRUMENTAL', 'codigo' => 'IN-CAJA-LAP', 'nombre' => 'Caja de laparoscopia', 'minutos_esterilizacion' => 240]),
            'caja_cg' => $item(['tipo' => 'INSTRUMENTAL', 'codigo' => 'IN-CAJA-CG', 'nombre' => 'Caja de cirugía general', 'minutos_esterilizacion' => 180]),
            'trocar10' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-TROC-10', 'nombre' => 'Trocar desechable 10 mm', 'unidad_medida' => 'unidad', 'stock_minimo' => 10]),
            'trocar5' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-TROC-5', 'nombre' => 'Trocar desechable 5 mm', 'unidad_medida' => 'unidad', 'stock_minimo' => 10]),
            'clips' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-CLIP-TI', 'nombre' => 'Clips de titanio (cartucho)', 'unidad_medida' => 'cartucho', 'stock_minimo' => 6]),
            'bolsa' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-BOLSA-EXT', 'nombre' => 'Bolsa extractora de especímenes', 'unidad_medida' => 'unidad', 'stock_minimo' => 6]),
            'malla' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-MALLA-PP', 'nombre' => 'Malla de polipropileno 15 × 15 cm', 'unidad_medida' => 'unidad', 'stock_minimo' => 4]),
            'prolene' => $item(['tipo' => 'INSUMO', 'codigo' => 'IS-SUT-PRO', 'nombre' => 'Sutura polipropileno 2-0', 'unidad_medida' => 'sobre', 'stock_minimo' => 20]),
        ];

        $en = fn (int $dias) => $this->hoy->copy()->addDays($dias)->toDateString();
        $salas = Sala::all()->keyBy(fn ($s) => $s->sede_id.'-'.$s->codigo);
        $unidades = [
            // Sede Norte: máquinas y monitores fijos en cada quirófano; torres y electrobisturís móviles.
            [$i['maquina'], $norte, 'Q1', 'BIO-00031', 'Dräger', 'Fabius Plus', $en(70), $en(200)],
            [$i['maquina'], $norte, 'Q2', 'BIO-00032', 'Mindray', 'WATO EX-65', $en(95), $en(240)],
            [$i['monitor'], $norte, 'Q1', 'BIO-00041', 'Philips', 'IntelliVue MX450', $en(60), $en(180)],
            [$i['monitor'], $norte, 'Q2', 'BIO-00042', 'Philips', 'IntelliVue MX450', $en(60), $en(180)],
            [$i['torre'], $norte, null, 'BIO-00125', 'Stryker', '1688 AIM', $en(-6), null],  // mantenimiento vencido: solo aviso
            [$i['torre'], $norte, null, 'BIO-00126', 'Karl Storz', 'Image1 S', $en(120), null],
            [$i['electro'], $norte, null, 'BIO-00210', 'Medtronic', 'Valleylab FT10', $en(45), null],
            [$i['electro'], $norte, null, 'BIO-00211', 'Medtronic', 'Valleylab FX8', $en(150), null],
            // Sede Sur: la máquina de anestesia tiene la calibración por vencer.
            [$i['maquina'], $sur, 'Q1', 'BIO-00301', 'Dräger', 'Fabius GS', $en(80), $en(12)],
            [$i['monitor'], $sur, 'Q1', 'BIO-00311', 'Mindray', 'BeneVision N12', $en(40), $en(160)],
            [$i['torre'], $sur, null, 'BIO-00320', 'Olympus', 'Visera Elite II', $en(25), null],
            [$i['electro'], $sur, null, 'BIO-00330', 'Erbe', 'VIO 300 D', $en(110), null],
        ];
        foreach ($unidades as [$it, $sede, $sala, $codigo, $marca, $modelo, $mantenimiento, $calibracion]) {
            Unidad::create([
                'item_id' => $it->id, 'sede_id' => $sede->id, 'sala_id' => $sala ? $salas[$sede->id.'-'.$sala]->id : null, 'codigo' => $codigo, 'marca' => $marca, 'modelo' => $modelo,
                'serie' => 'SN'.mt_rand(100000, 999999), 'registro_invima' => '20'.mt_rand(10, 23).'DM-00'.mt_rand(10000, 99999), 'estado' => 'OPERATIVO',
                'ultimo_mantenimiento' => Carbon::parse($mantenimiento)->subMonths(6)->toDateString(), 'proximo_mantenimiento' => $mantenimiento, 'calibracion_vence' => $calibracion,
            ]);
        }
        foreach ([[$i['caja_lap'], $norte, 'CJ-LAP-N01'], [$i['caja_lap'], $norte, 'CJ-LAP-N02'], [$i['caja_lap'], $norte, 'CJ-LAP-N03'], [$i['caja_cg'], $norte, 'CJ-CG-N01'], [$i['caja_cg'], $norte, 'CJ-CG-N02'], [$i['caja_lap'], $sur, 'CJ-LAP-S01'], [$i['caja_cg'], $sur, 'CJ-CG-S01']] as [$it, $sede, $codigo]) {
            Unidad::create(['item_id' => $it->id, 'sede_id' => $sede->id, 'codigo' => $codigo, 'estado' => 'OPERATIVO']);
        }

        $existencias = [
            [$i['trocar10'], $norte, 'L2408', 300, 48], [$i['trocar10'], $sur, 'L2408', 300, 8],
            [$i['trocar5'], $norte, 'L2411', 320, 60], [$i['trocar5'], $sur, 'L2411', 320, 14],
            [$i['clips'], $norte, 'C0915', 400, 24], [$i['clips'], $sur, 'C0915', 400, 9],
            [$i['bolsa'], $norte, 'B1102', 500, 30], [$i['bolsa'], $sur, 'B1102', 500, 10],
            [$i['malla'], $norte, 'M0721', 540, 12], [$i['malla'], $sur, 'M0721', 540, 2],
            [$i['prolene'], $norte, 'P0333', 25, 18], [$i['prolene'], $norte, 'P0412', 620, 40], [$i['prolene'], $sur, 'P0412', 620, 30],
        ];
        foreach ($existencias as [$it, $sede, $lote, $diasVence, $cantidad]) {
            Existencia::create(['item_id' => $it->id, 'sede_id' => $sede->id, 'lote' => $lote, 'vence' => $en($diasVence), 'cantidad' => $cantidad]);
        }

        $requerimientos = [
            '512104' => [['torre', 1], ['maquina', 1], ['monitor', 1], ['electro', 1], ['caja_lap', 1], ['trocar10', 2], ['trocar5', 2], ['clips', 1], ['bolsa', 1]],
            '471110' => [['torre', 1], ['maquina', 1], ['monitor', 1], ['electro', 1], ['caja_lap', 1], ['trocar10', 1], ['trocar5', 2], ['bolsa', 1]],
            '530002' => [['torre', 1], ['maquina', 1], ['monitor', 1], ['caja_lap', 1], ['trocar10', 1], ['trocar5', 2], ['malla', 1]],
            '534001' => [['maquina', 1], ['monitor', 1], ['electro', 1], ['caja_cg', 1], ['malla', 1], ['prolene', 2]],
        ];
        foreach ($requerimientos as $codigo => $lista) {
            foreach ($lista as [$clave, $cantidad]) {
                RequerimientoCups::create(['cups_id' => $this->cups[$codigo]->id, 'item_id' => $i[$clave]->id, 'cantidad' => $cantidad]);
            }
        }
        // Ajuste por sede: en la Sede Sur la herniorrafia umbilical se hace sin unidad electroquirúrgica dedicada.
        RequerimientoCups::create(['cups_id' => $this->cups['534001']->id, 'sede_id' => $sur->id, 'item_id' => $i['electro']->id, 'cantidad' => 0, 'notas' => 'Usa la unidad del quirófano']);
    }

    /**
     * @param  array<string, Contrato>  $contratos
     * @return array<string, Paciente>
     */
    private function poblaciones(array $contratos): array
    {
        $this->info('Poblaciones y pacientes…');
        $nombresF = ['María', 'Luz', 'Carmen', 'Gloria', 'Diana', 'Sandra', 'Paola', 'Natalia', 'Adriana', 'Claudia', 'Marcela', 'Liliana', 'Ángela', 'Yolanda', 'Beatriz'];
        $nombresM = ['Carlos', 'Jorge', 'Luis', 'Andrés', 'Diego', 'Fernando', 'Javier', 'Óscar', 'Ricardo', 'Hernán', 'Fabio', 'Wilson', 'Mauricio', 'Edwin', 'Álvaro'];
        $segundos = ['Elena', 'Patricia', 'Andrea', 'Eugenia', 'Alberto', 'Antonio', 'Eduardo', 'Fernando', '', '', ''];
        $apellidos = ['Gómez', 'Rodríguez', 'López', 'Martínez', 'García', 'Hernández', 'Valencia', 'Ospina', 'Cárdenas', 'Mosquera', 'Zapata', 'Lenis', 'Quintero', 'Arboleda', 'Castaño', 'Salazar', 'Patiño', 'Muñoz', 'Rincón', 'Caicedo'];
        $cohortes = ['Hipertensión', 'Diabetes', 'Riesgo cardiovascular', 'Enfermedad renal', 'Obesidad', ''];
        $cali = Municipio::where('codigo', '76001')->value('id');
        $jamundi = Municipio::where('codigo', '76364')->value('id');

        $poblaciones = [
            'pgp' => Poblacion::create(['contrato_id' => $contratos['pgp']->id, 'nombre' => 'Afiliados contributivo Cali', 'descripcion' => 'Base mensual enviada por Salud Andina', 'activo' => true, 'ultimo_cargue_en' => $this->hoy->copy()->startOfMonth()->addDay()]),
            'evento' => Poblacion::create(['contrato_id' => $contratos['evento']->id, 'nombre' => 'Programa quirúrgico Previsalud', 'activo' => true, 'ultimo_cargue_en' => $this->hoy->copy()->startOfMonth()->addDays(2)]),
            'capita' => Poblacion::create(['contrato_id' => $contratos['capita']->id, 'nombre' => 'Capitados subsidiado sur de Cali y Jamundí', 'activo' => true, 'ultimo_cargue_en' => $this->hoy->copy()->subMonth()->startOfMonth()->addDays(3)]),
        ];

        $documento = 31000000;
        foreach ($poblaciones as $clave => $poblacion) {
            $cantidad = ['pgp' => 48, 'evento' => 26, 'capita' => 37][$clave];
            for ($n = 0; $n < $cantidad; $n++) {
                $mujer = mt_rand(0, 1) === 1;
                $p = Paciente::create([
                    'tipo_documento_id' => $this->cc, 'numero_documento' => (string) ($documento += mt_rand(1000, 90000)),
                    'primer_nombre' => ($mujer ? $nombresF : $nombresM)[array_rand($nombresF)], 'segundo_nombre' => ($s = $segundos[array_rand($segundos)]) ?: null,
                    'primer_apellido' => $apellidos[array_rand($apellidos)], 'segundo_apellido' => $apellidos[array_rand($apellidos)],
                    'fecha_nacimiento' => $this->hoy->copy()->subYears(mt_rand(20, 82))->subDays(mt_rand(0, 360))->toDateString(), 'sexo' => $mujer ? 'F' : 'M',
                    'telefono' => '3'.mt_rand(100000000, 209999999), 'municipio_id' => $clave === 'capita' && mt_rand(0, 2) === 0 ? $jamundi : $cali,
                ]);
                $c = $cohortes[array_rand($cohortes)];
                $poblacion->pacientes()->attach($p->id, ['cohortes' => $c ? json_encode([$c], JSON_UNESCAPED_UNICODE) : null, 'activo' => true]);
            }
        }

        // Pacientes con nombre propio para el recorrido de la demo.
        $personas = [
            'rosa' => ['Rosa', 'Elena', 'Quintero', 'Arboleda', 58, 'F', 'pgp'],
            'kevin' => ['Kevin', 'Andrés', 'Mosquera', 'Caicedo', 9, 'M', 'capita'],
            'luis' => ['Luis', 'Eduardo', 'Cárdenas', 'Mora', 71, 'M', 'pgp'],
            'maria' => ['María', 'Fernanda', 'Ríos', 'Gómez', 52, 'F', 'evento'],
            'jorge' => ['Jorge', 'Iván', 'Murillo', 'Patiño', 45, 'M', 'pgp'],
            'gloria' => ['Gloria', 'Inés', 'Zapata', 'Rincón', 63, 'F', 'capita'],
            'andres' => ['Andrés', 'Felipe', 'Lenis', 'Salazar', 38, 'M', 'evento'],
            'diana' => ['Diana', 'Marcela', 'Ortiz', 'Valencia', 34, 'F', 'evento'],
            'hector' => ['Héctor', 'Fabio', 'Lenis', 'Muñoz', 66, 'M', 'capita'],
            'carmen' => ['Carmen', 'Rosa', 'Valencia', 'Ospina', 55, 'F', 'pgp'],
            'edgar' => ['Édgar', null, 'Ospina', 'Castaño', 68, 'M', 'pgp'],
            'sandra' => ['Sandra', 'Patricia', 'Gil', 'Martínez', 47, 'F', 'capita'],
            'ricardo' => ['Ricardo', null, 'Arboleda', 'López', 61, 'M', 'pgp'],
            'natalia' => ['Natalia', null, 'Patiño', 'Rodríguez', 29, 'F', 'evento'],
            'wilson' => ['Wilson', 'Alberto', 'Rincón', 'García', 50, 'M', 'capita'],
            'beatriz' => ['Beatriz', null, 'Castaño', 'Gómez', 44, 'F', 'pgp'],
            'alvaro' => ['Álvaro', null, 'Muñoz', 'Hernández', 73, 'M', 'pgp'],
        ];
        $resultado = [];
        foreach ($personas as $clave => [$n1, $n2, $a1, $a2, $edad, $sexo, $poblacion]) {
            $p = Paciente::create([
                'tipo_documento_id' => $edad < 18 ? $this->ti : $this->cc, 'numero_documento' => (string) ($edad < 18 ? 1107000000 + mt_rand(100000, 999999) : ($documento += mt_rand(1000, 90000))),
                'primer_nombre' => $n1, 'segundo_nombre' => $n2, 'primer_apellido' => $a1, 'segundo_apellido' => $a2,
                'fecha_nacimiento' => $this->hoy->copy()->subYears($edad)->subDays(mt_rand(10, 300))->toDateString(), 'sexo' => $sexo,
                'telefono' => '3'.mt_rand(100000000, 209999999), 'correo' => strtolower(Str::ascii($n1.'.'.$a1)).'@correo.co', 'municipio_id' => $cali,
            ]);
            $poblaciones[$poblacion]->pacientes()->attach($p->id, ['cohortes' => null, 'activo' => true]);
            $resultado[$clave] = $p;
        }

        return $resultado;
    }

    /**
     * Cirugías ya realizadas en lo corrido de cada contrato, para que el cumplimiento tenga cifras.
     * El PGP queda por debajo del tiempo transcurrido, así el motor favorece a sus pacientes.
     *
     * @param  array<string, Contrato>  $contratos
     * @param  array<string, Especialista>  $personal
     */
    private function historico(array $contratos, array $personal, Sede $norte, Sede $sur): void
    {
        $this->info('Cirugías realizadas en lo corrido de los contratos…');
        $metas = [
            'pgp' => ['512104' => 150, '471110' => 80, '530002' => 70, '534001' => 100],
            'evento' => ['512104' => 90, '471110' => 40, '530002' => 30, '534001' => 40],
            'capita' => ['512104' => 45, '471110' => 25, '530002' => 20, '534001' => 30],
        ];
        $salas = Sala::where('tipo', 'QUIROFANO')->get()->groupBy('sede_id');
        $cirujanos = [$norte->id => [$personal['morales']->id, $personal['ocampo']->id, $personal['restrepo']->id], $sur->id => [$personal['restrepo']->id]];
        $anestesiologos = [$norte->id => [$personal['mejia']->id, $personal['herrera']->id], $sur->id => [$personal['rojas']->id]];
        $diagnosticos = ['512104' => 'K802', '471110' => 'K359', '530002' => 'K409', '534001' => 'K429'];
        $ahora = now();

        foreach ($metas as $clave => $porCups) {
            $contrato = $contratos[$clave];
            $sedes = $contrato->sedes()->pluck('sedes.id')->all();
            $pacientes = DB::table('poblacion_paciente')->join('poblaciones', 'poblaciones.id', '=', 'poblacion_paciente.poblacion_id')
                ->where('poblaciones.contrato_id', $contrato->id)->pluck('poblacion_paciente.paciente_id')->all();
            $desde = $contrato->fecha_inicio->copy();
            $dias = max(1, $desde->diffInDays($this->hoy->copy()->subDay()));

            foreach ($porCups as $codigo => $cantidad) {
                for ($n = 0; $n < $cantidad; $n++) {
                    do {
                        $fecha = $desde->copy()->addDays(mt_rand(0, $dias));
                    } while ($fecha->isSunday());
                    $sede = $sedes[array_rand($sedes)];
                    $paciente = $pacientes[array_rand($pacientes)];
                    $hora = sprintf('%02d:00', [7, 9, 11, 14][mt_rand(0, 3)]);
                    $ordenId = DB::table('ordenes_quirurgicas')->insertGetId([
                        'paciente_id' => $paciente, 'contrato_id' => $contrato->id, 'cups_id' => $this->cups[$codigo]->id, 'especialidad_id' => $this->especialidades['cirugia-general'],
                        'diagnostico_cie10' => $diagnosticos[$codigo], 'prioridad' => 'ELECTIVA', 'fecha_orden' => $fecha->copy()->subDays(mt_rand(20, 60))->toDateString(),
                        'origen' => 'API', 'sistema_origen' => 'HIS Vida Plena', 'referencia_externa' => 'H-'.$clave.'-'.$codigo.'-'.$n,
                        'estado' => 'OPERADA', 'concepto' => 'APTO', 'asa' => mt_rand(1, 2), 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                    DB::table('cirugias')->insert([
                        'orden_id' => $ordenId, 'paciente_id' => $paciente, 'cups_id' => $this->cups[$codigo]->id, 'sede_id' => $sede,
                        'sala_id' => $salas[$sede]->random()->id, 'cirujano_id' => $cirujanos[$sede][array_rand($cirujanos[$sede])],
                        'anestesiologo_id' => $anestesiologos[$sede][array_rand($anestesiologos[$sede])], 'fecha' => $fecha->toDateString(),
                        'hora_inicio' => $hora, 'hora_fin' => Carbon::parse($hora)->addMinutes(90)->format('H:i'), 'estado' => 'REALIZADA',
                        'puntaje' => 0, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
            }
        }
    }

    /**
     * Órdenes en todos los estados del flujo.
     *
     * @param  array<string, Contrato>  $contratos
     * @param  array<string, Paciente>  $p
     * @param  array<string, Especialista>  $personal
     */
    private function ordenes(OrdenServicio $servicio, CalculadoraPreanestesia $calculadora, array $contratos, array $p, array $personal, Sede $norte, Sede $sur): void
    {
        $this->info('Órdenes, valoraciones y avales…');
        $version = PlantillaHc::with('versionVigente')->where('codigo', 'preanestesia')->firstOrFail()->versionVigente;
        $paula = User::where('email', 'paula.mejia@kizuna.com')->value('id');
        $c = $this->cups;

        $base = [
            'riesgo_quirurgico' => 'INTERMEDIO', 'anestesia_propuesta' => 'GENERAL', 'opioides_postoperatorios' => true, 'hta' => false, 'diabetes' => false,
            'cardiopatia_isquemica' => false, 'insuficiencia_cardiaca' => false, 'acv' => false, 'epoc_asma' => false, 'fumador' => false, 'nvpo_previa' => false,
            'alergias' => false, 'peso' => 72, 'talla' => 165, 'pa_sistolica' => 122, 'pa_diastolica' => 78, 'frecuencia_cardiaca' => 74, 'spo2' => 97,
            'ronquido' => false, 'cansancio_diurno' => false, 'apneas_observadas' => false, 'mallampati' => '1', 'movilidad_cervical' => 'NORMAL',
            'paraclinicos' => [['examen' => 'HEMOGRAMA', 'fecha' => $this->hoy->copy()->subDays(20)->toDateString(), 'resultado' => 'Normal']],
            'concepto' => 'APTO', 'ayuno' => 'Sólidos 8 horas, líquidos claros 2 horas', 'consentimiento' => true,
        ];

        // [paciente, cups, contrato, prioridad, días desde la orden, días desde la valoración, ASA, cambios a la historia, anestesióloga, sede, cirujano de la orden]
        $valoradas = [
            ['rosa', '512104', 'pgp', 'PRIORITARIA', 18, 4, 2, ['hta' => true, 'hta_controlada' => true, 'peso' => 81, 'talla' => 158], 'mejia', $norte, null],
            ['kevin', '471110', 'capita', 'ELECTIVA', 6, 2, 1, ['peso' => 31, 'talla' => 134, 'riesgo_quirurgico' => 'BAJO'], 'rojas', $sur, null],
            ['luis', '530002', 'pgp', 'ELECTIVA', 40, 6, 3, ['diabetes' => true, 'diabetes_insulina' => true, 'diabetes_controlada' => true, 'hta' => true, 'hta_controlada' => true, 'medicamentos' => [['nombre' => 'Insulina glargina', 'dosis' => '22 UI noche'], ['nombre' => 'Losartán 50 mg', 'dosis' => '1 diaria']]], 'mejia', $norte, null],
            ['maria', '512104', 'evento', 'ELECTIVA', 25, 1, 2, ['medicamentos' => [['nombre' => 'Warfarina 5 mg', 'dosis' => '1 diaria']], 'paraclinicos' => [['examen' => 'TP_INR', 'fecha' => $this->hoy->copy()->subDays(3)->toDateString(), 'resultado' => 'INR 2,3']], 'concepto' => 'APTO_CON_RECOMENDACIONES', 'recomendaciones' => 'Suspender warfarina 5 días antes y control de INR el día previo.', 'dias_suspension' => 5], 'herrera', $norte, null],
            ['jorge', '534001', 'pgp', 'ELECTIVA', 190, 170, 2, ['fumador' => true], 'mejia', $norte, null],
            ['gloria', '512104', 'capita', 'ELECTIVA', 33, 12, 2, ['hta' => true, 'hta_controlada' => true], 'rojas', $sur, null],
            ['andres', '471110', 'evento', 'ELECTIVA', 11, 8, 1, [], 'herrera', $norte, null],
            ['diana', '534001', 'evento', 'ELECTIVA', 9, 3, 1, ['peso' => 64, 'talla' => 162], 'herrera', $norte, null],
            ['hector', '512104', 'capita', 'ELECTIVA', 52, 20, 2, ['epoc_asma' => false, 'hta' => true, 'hta_controlada' => true], 'rojas', $sur, 'morales'],
            ['carmen', '530002', 'pgp', 'ELECTIVA', 27, 9, 2, ['peso' => 88, 'talla' => 160], 'mejia', $norte, null],
            ['edgar', '512104', 'pgp', 'ELECTIVA', 30, 5, 3, ['hta' => true, 'hta_controlada' => false, 'pa_sistolica' => 192, 'pa_diastolica' => 112, 'concepto' => 'NO_APTO', 'motivo' => 'Hipertensión no controlada (192/112). Remitir a medicina interna para control y nueva valoración.'], 'mejia', $norte, null],
            ['sandra', '530002', 'capita', 'ELECTIVA', 22, 7, 3, ['cardiopatia_isquemica' => true, 'iam_reciente' => true, 'concepto' => 'APLAZADO', 'motivo' => 'Evento coronario hace 6 semanas: aplazar cirugía electiva y valoración por cardiología.'], 'rojas', $sur, null],
        ];

        foreach ($valoradas as [$clave, $codigo, $contrato, $prioridad, $diasOrden, $diasValoracion, $asa, $cambios, $anestesiologo, $sede, $cirujano]) {
            $paciente = $p[$clave];
            $fechaValoracion = $this->hoy->copy()->subDays($diasValoracion)->setTime(14, 0);
            $orden = OrdenQuirurgica::create([
                'paciente_id' => $paciente->id, 'contrato_id' => $contratos[$contrato]->id, 'cups_id' => $c[$codigo]->id, 'especialidad_id' => $this->especialidades['cirugia-general'],
                'cirujano_id' => $cirujano ? $personal[$cirujano]->id : null,
                'diagnostico_cie10' => ['512104' => 'K802', '471110' => 'K359', '530002' => 'K409', '534001' => 'K429'][$codigo],
                'prioridad' => $prioridad, 'fecha_orden' => $this->hoy->copy()->subDays($diasOrden)->toDateString(), 'medico_ordenante' => 'Dr. Andrés Felipe Morales',
                'origen' => 'API', 'sistema_origen' => 'HIS Vida Plena', 'referencia_externa' => 'OQ-'.(26000 + $paciente->id), 'estado' => 'CITA_ASIGNADA',
            ]);
            $cita = Cita::create([
                'paciente_id' => $paciente->id, 'especialista_id' => $personal[$anestesiologo]->id, 'sede_id' => $sede->id, 'especialidad_id' => $this->especialidades['anestesiologia'],
                'cups_id' => $c['890226']->id, 'orden_id' => $orden->id, 'fecha' => $fechaValoracion->toDateString(), 'hora_inicio' => '14:00', 'hora_fin' => '14:30',
                'tipo' => 'PREANESTESIA', 'estado' => 'PROGRAMADA', 'origen' => 'AUTOMATICA',
            ]);

            $respuestas = array_merge($base, $cambios, ['asa' => (string) $asa]);
            if ($respuestas['concepto'] !== 'APTO' && $respuestas['concepto'] !== 'APTO_CON_RECOMENDACIONES') {
                unset($respuestas['ayuno'], $respuestas['consentimiento']);
            }
            $contexto = ['paciente' => ['sexo' => $paciente->sexo, 'edad' => $paciente->fecha_nacimiento->age]];
            $resultado = $calculadora->calcular($respuestas, $contexto);

            $historia = HistoriaClinica::create([
                'paciente_id' => $paciente->id, 'plantilla_version_id' => $version->id, 'especialista_id' => $personal[$anestesiologo]->id, 'cita_id' => $cita->id, 'orden_id' => $orden->id,
                'origen' => 'KIZUNA', 'estado' => 'FINALIZADA', 'respuestas' => $respuestas, 'resultado' => $resultado,
                'finalizada_en' => $fechaValoracion->copy()->addMinutes(25), 'finalizada_por' => $anestesiologo === 'mejia' ? $paula : null, 'creada_por' => $paula,
            ]);
            HistoriaEvento::create(['historia_id' => $historia->id, 'usuario_id' => $paula, 'accion' => 'CREADA']);
            HistoriaEvento::create(['historia_id' => $historia->id, 'usuario_id' => $paula, 'accion' => 'FINALIZADA', 'detalle' => $respuestas['concepto']]);

            $servicio->aplicarConcepto($orden, $historia->id, $respuestas['concepto'], $asa, $fechaValoracion, (int) ($respuestas['dias_suspension'] ?? $resultado['medicamentos']['dias_suspension'] ?? 0), null, $respuestas['motivo'] ?? null);
        }
        // Jorge: el aval vence en pocos días (valoración hace 170 días, ASA II = 180 días de vigencia).

        // Órdenes recién llegadas del HIS: Kizuna les agenda la cita de pre-anestesia sola.
        foreach (['ricardo' => '512104', 'natalia' => '534001', 'wilson' => '530002', 'beatriz' => '471110'] as $clave => $codigo) {
            $paciente = $p[$clave];
            $servicio->registrar([
                'paciente' => [
                    'tipo_documento' => 'CC', 'numero_documento' => $paciente->numero_documento, 'primer_nombre' => $paciente->primer_nombre, 'segundo_nombre' => $paciente->segundo_nombre,
                    'primer_apellido' => $paciente->primer_apellido, 'segundo_apellido' => $paciente->segundo_apellido, 'fecha_nacimiento' => $paciente->fecha_nacimiento->toDateString(), 'sexo' => $paciente->sexo,
                ],
                'cups' => $codigo, 'contrato_id' => $contratos[['ricardo' => 'pgp', 'natalia' => 'evento', 'wilson' => 'capita', 'beatriz' => 'pgp'][$clave]]->id,
                'prioridad' => 'ELECTIVA', 'fecha_orden' => $this->hoy->copy()->subDays(mt_rand(1, 4))->toDateString(), 'referencia_externa' => 'OQ-'.(26000 + $paciente->id),
            ], 'API', 'HIS Vida Plena');
        }

        // Una cita de pre-anestesia hoy, para atenderla en vivo en la demo.
        $alvaro = $p['alvaro'];
        $orden = OrdenQuirurgica::create([
            'paciente_id' => $alvaro->id, 'contrato_id' => $contratos['pgp']->id, 'cups_id' => $c['512104']->id, 'especialidad_id' => $this->especialidades['cirugia-general'],
            'diagnostico_cie10' => 'K802', 'diagnostico' => 'Colelitiasis sintomática', 'prioridad' => 'ELECTIVA', 'fecha_orden' => $this->hoy->copy()->subDays(9)->toDateString(),
            'medico_ordenante' => 'Dra. Catalina Restrepo', 'origen' => 'API', 'sistema_origen' => 'HIS Vida Plena', 'referencia_externa' => 'OQ-'.(26000 + $alvaro->id), 'estado' => 'CITA_ASIGNADA',
        ]);
        Cita::create([
            'paciente_id' => $alvaro->id, 'especialista_id' => $personal['mejia']->id, 'sede_id' => $norte->id, 'especialidad_id' => $this->especialidades['anestesiologia'],
            'cups_id' => $c['890226']->id, 'orden_id' => $orden->id, 'fecha' => $this->hoy->toDateString(), 'hora_inicio' => '16:00', 'hora_fin' => '16:30',
            'tipo' => 'PREANESTESIA', 'estado' => 'PROGRAMADA', 'origen' => 'AUTOMATICA',
        ]);

        // Una orden de urología: la IPS no tiene urólogo, queda rechazada.
        $servicio->registrar([
            'paciente' => ['tipo_documento' => 'CC', 'numero_documento' => '1144998877', 'primer_nombre' => 'Fernando', 'primer_apellido' => 'Hernández', 'segundo_apellido' => 'Castaño', 'fecha_nacimiento' => $this->hoy->copy()->subYears(67)->toDateString(), 'sexo' => 'M'],
            'cups' => $c['urologia']->codigo, 'contrato_id' => $contratos['pgp']->id, 'prioridad' => 'ELECTIVA', 'referencia_externa' => 'OQ-26999',
        ], 'API', 'HIS Vida Plena');

    }

    /**
     * Dígito de verificación de un NIT (algoritmo DIAN, módulo 11).
     */
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
