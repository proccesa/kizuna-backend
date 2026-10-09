<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Citas\Services\DisponibilidadServicio;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Entidad;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\RequerimientoCups;
use App\Modules\Inventario\Models\Reserva;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Programacion\Models\AsignacionAnestesia;
use App\Modules\Programacion\Models\Cirugia;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramacionTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cups $cups;

    private Especialista $cirujano;

    /** @var list<Especialista> */
    private array $anestesiologos = [];

    private Item $trocar;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 12 de octubre: con 3 días de anticipación, lo primero posible es el jueves 15.
        Carbon::setTestNow('2026-10-12 08:00:00');
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());

        $prestador = Prestador::create(['nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true]);
        $this->sede = Sede::create([
            'prestador_id' => $prestador->id, 'numero_sede' => '01', 'nombre' => 'Norte', 'municipio_id' => Municipio::where('codigo', '76001')->value('id'),
            'direccion' => 'Calle 1', 'dias_atencion' => [1, 2, 3, 4, 5], 'hora_apertura' => '06:00', 'hora_cierre' => '20:00', 'es_principal' => true, 'activo' => true,
        ]);
        foreach (['Q1', 'Q2'] as $codigo) {
            Sala::create(['sede_id' => $this->sede->id, 'codigo' => $codigo, 'nombre' => "Quirófano {$codigo}", 'tipo' => 'QUIROFANO', 'activo' => true]);
        }

        Cups::factory()->create(['codigo' => '890226']);
        $this->cups = Cups::factory()->create(['codigo' => '512104', 'es_quirurgico' => true]);
        PortafolioItem::create(['sede_id' => $this->sede->id, 'cups_id' => $this->cups->id, 'duracion_minutos' => 90, 'tipo_sala' => 'QUIROFANO', 'activo' => true]);

        $cirugiaGeneral = Especialidad::where('codigo', 'cirugia-general')->value('id');
        $anestesia = Especialidad::where('codigo', 'anestesiologia')->value('id');
        $this->cirujano = $this->especialista('Carlos', $cirugiaGeneral, '07:00', '13:00');
        $this->anestesiologos[] = $this->especialista('Andrea', $anestesia, '06:00', '13:00');
        $this->anestesiologos[] = $this->especialista('Bruno', $anestesia, '06:00', '13:00');

        $items = [
            'torre' => Item::create(['tipo' => 'EQUIPO', 'codigo' => 'EQ-TORRE', 'nombre' => 'Torre de laparoscopia', 'activo' => true]),
            'maquina' => Item::create(['tipo' => 'EQUIPO', 'codigo' => 'EQ-MAQ', 'nombre' => 'Máquina de anestesia', 'activo' => true]),
            'caja' => Item::create(['tipo' => 'INSTRUMENTAL', 'codigo' => 'IN-LAPA', 'nombre' => 'Caja de laparoscopia', 'minutos_esterilizacion' => 240, 'activo' => true]),
        ];
        $this->trocar = Item::create(['tipo' => 'INSUMO', 'codigo' => 'IS-TROC10', 'nombre' => 'Trocar 10 mm', 'activo' => true]);
        Unidad::create(['item_id' => $items['torre']->id, 'sede_id' => $this->sede->id, 'codigo' => 'TL-01', 'estado' => 'OPERATIVO']);
        Unidad::create(['item_id' => $items['maquina']->id, 'sede_id' => $this->sede->id, 'codigo' => 'MA-01', 'estado' => 'OPERATIVO']);
        Unidad::create(['item_id' => $items['maquina']->id, 'sede_id' => $this->sede->id, 'codigo' => 'MA-02', 'estado' => 'OPERATIVO']);
        Unidad::create(['item_id' => $items['caja']->id, 'sede_id' => $this->sede->id, 'codigo' => 'CL-01', 'estado' => 'OPERATIVO']);
        Existencia::create(['item_id' => $this->trocar->id, 'sede_id' => $this->sede->id, 'lote' => 'A', 'cantidad' => 20]);
        foreach ([[$items['torre'], 1], [$items['maquina'], 1], [$items['caja'], 1], [$this->trocar, 4]] as [$item, $cantidad]) {
            RequerimientoCups::create(['cups_id' => $this->cups->id, 'item_id' => $item->id, 'cantidad' => $cantidad]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function especialista(string $nombre, int $especialidadId, string $desde, string $hasta): Especialista
    {
        static $documento = 1000;
        $e = Especialista::create(['tipo_documento_id' => TipoDocumento::where('codigo', 'CC')->value('id'), 'numero_documento' => (string) $documento++, 'nombres' => $nombre, 'apellidos' => 'Prueba', 'activo' => true]);
        $e->especialidades()->attach($especialidadId);
        $e->agendas()->create(['sede_id' => $this->sede->id, 'especialidad_id' => $especialidadId, 'dias' => [1, 2, 3, 4, 5], 'hora_inicio' => $desde, 'hora_fin' => $hasta, 'vigente_desde' => '2026-01-01', 'activo' => true]);

        return $e;
    }

    private function ordenApta(string $documento, string $nacimiento, string $prioridad = 'ELECTIVA', string $fechaOrden = '2026-10-12', int $asa = 2): OrdenQuirurgica
    {
        $paciente = Paciente::create([
            'tipo_documento_id' => TipoDocumento::where('codigo', 'CC')->value('id'), 'numero_documento' => $documento,
            'primer_nombre' => "Paciente{$documento}", 'primer_apellido' => 'Prueba', 'fecha_nacimiento' => $nacimiento, 'sexo' => 'F',
        ]);

        return OrdenQuirurgica::create([
            'paciente_id' => $paciente->id, 'cups_id' => $this->cups->id, 'especialidad_id' => Especialidad::where('codigo', 'cirugia-general')->value('id'),
            'prioridad' => $prioridad, 'fecha_orden' => $fechaOrden, 'origen' => 'MANUAL', 'estado' => 'APTA', 'concepto' => 'APTO', 'asa' => $asa,
            'aval_desde' => '2026-10-12', 'aval_hasta' => '2027-04-10', 'programable_desde' => '2026-10-12',
        ]);
    }

    private function generar(string $hasta = '2026-10-30')
    {
        return $this->postJson('/api/v1/programacion/generar', ['desde' => '2026-10-12', 'hasta' => $hasta]);
    }

    public function test_el_motor_prioriza_y_respeta_recursos(): void
    {
        $electiva = $this->ordenApta('100', '1980-01-01', 'ELECTIVA', '2026-09-12');   // 30 días de espera
        $prioritaria = $this->ordenApta('200', '1975-01-01', 'PRIORITARIA');
        $nino = $this->ordenApta('300', '2018-05-05');                                   // pediátrico: primera hora

        $propuesta = $this->generar()->assertCreated()
            ->assertJsonPath('datos.resumen.evaluadas', 3)
            ->assertJsonPath('datos.resumen.programadas', 3)
            ->json('datos');

        $porOrden = collect($propuesta['cirugias'])->keyBy('orden_id');
        // La prioritaria va primero: jueves desde la hora reservada (09:00), en Q1.
        $this->assertSame(['2026-10-15', '09:00', 'Q1'], [$porOrden[$prioritaria->id]['fecha'], $porOrden[$prioritaria->id]['hora_inicio'], $porOrden[$prioritaria->id]['sala']['codigo']]);
        // El niño quería primera hora, pero la única caja está en uso o esterilización el jueves: va el viernes a las 07:00.
        $this->assertSame(['2026-10-16', '07:00'], [$porOrden[$nino->id]['fecha'], $porOrden[$nino->id]['hora_inicio']]);
        // La electiva: la caja vuelve de esterilización el viernes a las 12:30, así que va el lunes a las 09:00.
        $this->assertSame(['2026-10-19', '09:00'], [$porOrden[$electiva->id]['fecha'], $porOrden[$electiva->id]['hora_inicio']]);
        $this->assertNotNull($porOrden[$prioritaria->id]['anestesiologo']);
        $this->assertSame('Orden prioritaria', $porOrden[$prioritaria->id]['prioridad']['factores'][0]['etiqueta']);
        $this->assertSame('Paciente pediátrico', $porOrden[$nino->id]['prioridad']['temprano']);

        // Recursos reservados y anestesiólogo asignado a la sala en la jornada
        $this->assertSame(3, AsignacionAnestesia::count());
        $this->assertSame(12, (int) Reserva::where('item_id', $this->trocar->id)->where('estado', 'ACTIVA')->sum('cantidad'));

        // El anestesiólogo asignado al quirófano el jueves en la mañana no recibe consultas de pre-anestesia en ese bloque
        $asignado = AsignacionAnestesia::whereDate('fecha', '2026-10-15')->value('especialista_id');
        $cupos = app(DisponibilidadServicio::class)->cupos(Especialidad::where('codigo', 'anestesiologia')->value('id'), Cups::where('codigo', '890226')->value('id'), Carbon::parse('2026-10-15'), Carbon::parse('2026-10-15'), null, 30, 100);
        $this->assertFalse($cupos->contains(fn ($c) => $c['especialista_id'] === $asignado));

        // No se puede generar otra mientras haya una propuesta pendiente
        $this->generar()->assertStatus(422);

        // Aprobar
        $this->postJson("/api/v1/programacion/corridas/{$propuesta['id']}/aprobar")->assertOk()->assertJsonPath('datos.estado', 'APROBADA');
        $this->assertSame('PROGRAMADA', $prioritaria->refresh()->estado);
        $this->getJson('/api/v1/programacion/programa?desde=2026-10-12&hasta=2026-10-31')->assertJsonCount(3, 'datos');
        $this->getJson('/api/v1/programacion/cola')->assertJsonCount(0, 'datos');

        // Realizar: descuenta los trocares
        $cirugia = Cirugia::where('orden_id', $prioritaria->id)->first();
        $this->postJson("/api/v1/programacion/cirugias/{$cirugia->id}/realizar")->assertOk()->assertJsonPath('datos.cirugia.estado', 'REALIZADA');
        $this->assertSame(16, Existencia::where('item_id', $this->trocar->id)->value('cantidad'));
        $this->assertSame('OPERADA', $prioritaria->refresh()->estado);

        // Cancelar: el paciente vuelve a la cola y se liberan los recursos
        $otra = Cirugia::where('orden_id', $electiva->id)->first();
        $this->postJson("/api/v1/programacion/cirugias/{$otra->id}/cancelar", ['motivo' => 'Paciente con gripa'])->assertOk();
        $this->assertSame('APTA', $electiva->refresh()->estado);
        $this->assertSame(0, Reserva::where('referencia_id', $otra->id)->where('estado', 'ACTIVA')->count());
        $this->getJson('/api/v1/programacion/cola')->assertJsonCount(1, 'datos')->assertJsonPath('datos.0.orden.id', $electiva->id);
        $this->getJson('/api/v1/programacion/resumen')->assertJsonPath('datos.por_programar', 1)->assertJsonPath('datos.realizadas_mes', 1);
    }

    public function test_descartar_libera_y_explica_lo_no_programado(): void
    {
        $orden = $this->ordenApta('100', '1980-01-01');
        // Un CUPS sin cirujano con agenda: no se puede programar
        $otroCups = Cups::factory()->create(['codigo' => '471100', 'es_quirurgico' => true]);
        PortafolioItem::create(['sede_id' => $this->sede->id, 'cups_id' => $otroCups->id, 'duracion_minutos' => 60, 'activo' => true]);
        $sinCirujano = $this->ordenApta('200', '1980-01-01');
        $sinCirujano->update(['cups_id' => $otroCups->id, 'especialidad_id' => Especialidad::where('codigo', 'urologia')->value('id')]);

        $propuesta = $this->generar()->assertCreated()->assertJsonPath('datos.resumen.programadas', 1)->json('datos');
        $this->assertSame('No hay cirujanos activos de la especialidad.', $propuesta['resumen']['no_programadas'][0]['motivo']);

        // Quitar una cirugía de la propuesta
        $this->postJson("/api/v1/programacion/cirugias/{$propuesta['cirugias'][0]['id']}/rechazar", ['motivo' => 'Prefiere otra fecha'])->assertOk();
        $this->assertSame(0, AsignacionAnestesia::count());

        $this->postJson("/api/v1/programacion/corridas/{$propuesta['id']}/descartar")->assertOk()->assertJsonPath('datos.estado', 'DESCARTADA');
        $this->assertSame(0, Reserva::where('estado', 'ACTIVA')->count());
        $this->assertSame('APTA', $orden->refresh()->estado);

        // Sin recursos (la caja fuera de servicio) el motivo lo dice
        Unidad::where('codigo', 'CL-01')->update(['estado' => 'FUERA_SERVICIO']);
        $resumen = $this->generar('2026-10-16')->json('datos.resumen');
        $this->assertSame('Falta Caja de laparoscopia (0 de 1).', collect($resumen['no_programadas'])->firstWhere('orden_id', $orden->id)['motivo']);
    }

    public function test_permisos_de_programacion(): void
    {
        $operador = User::factory()->create();
        $operador->assignRole('operador');
        Sanctum::actingAs($operador);
        $this->getJson('/api/v1/programacion/cola')->assertOk();
        $propuesta = $this->generar()->assertCreated()->json('datos.id');
        $this->postJson("/api/v1/programacion/corridas/{$propuesta}/aprobar")->assertForbidden();
    }

    public function test_cumplimiento_de_contratos_y_prioridad_por_rezago_pgp(): void
    {
        $entidad = Entidad::create(['nit' => '901234560', 'digito_verificacion' => '1', 'razon_social' => 'EPS Prueba', 'tipo' => 'EPS', 'activo' => true]);
        $contrato = fn (string $modalidad, string $numero, float $valor) => Contrato::create([
            'entidad_id' => $entidad->id, 'numero' => $numero, 'modalidad_contratacion_id' => ModalidadContratacion::where('codigo', $modalidad)->value('id'),
            'regimen_id' => Regimen::where('codigo', 'CONTRIBUTIVO')->value('id'), 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'valor' => $valor, 'activo' => true,
        ]);
        $pgp = $contrato('PGP', 'PGP-1', 0);
        $pgp->cups()->attach($this->cups->id, ['cantidad' => 10]);
        $evento = $contrato('EVENTO', 'EV-1', 10000000);
        $evento->cups()->attach($this->cups->id, ['tarifa' => 1000000]);

        // PGP: 2 realizadas + 1 aprobada (y una cancelada y una de antes de la vigencia, que no cuentan). Evento: 2 realizadas.
        $cirugia = function (Contrato $c, string $estado, string $fecha) {
            $orden = $this->ordenApta((string) random_int(10000, 99999), '1980-01-01');
            $orden->update(['contrato_id' => $c->id, 'estado' => $estado === 'REALIZADA' ? 'OPERADA' : 'PROGRAMADA']);
            Cirugia::create([
                'orden_id' => $orden->id, 'paciente_id' => $orden->paciente_id, 'cups_id' => $this->cups->id, 'sede_id' => $this->sede->id,
                'cirujano_id' => $this->cirujano->id, 'fecha' => $fecha, 'hora_inicio' => '07:00', 'hora_fin' => '08:30', 'estado' => $estado,
            ]);
        };
        $cirugia($pgp, 'REALIZADA', '2026-03-10');
        $cirugia($pgp, 'REALIZADA', '2026-08-20');
        $cirugia($pgp, 'APROBADA', '2026-10-20');
        $cirugia($pgp, 'CANCELADA', '2026-10-21');
        $cirugia($pgp, 'REALIZADA', '2025-12-20');
        $cirugia($evento, 'REALIZADA', '2026-05-05');
        $cirugia($evento, 'REALIZADA', '2026-06-06');

        $lista = collect($this->getJson('/api/v1/contratos')->assertOk()->json('datos'))->keyBy('numero');
        $this->assertEquals(['realizadas' => 2, 'programadas' => 1, 'meta' => 10, 'porcentaje' => 20, 'porcentaje_comprometido' => 30, 'atrasado' => true],
            collect($lista['PGP-1']['cumplimiento'])->only(['realizadas', 'programadas', 'meta', 'porcentaje', 'porcentaje_comprometido', 'atrasado'])->all());
        $this->assertEquals(78.1, $lista['PGP-1']['cumplimiento']['avance_tiempo']);
        $this->assertEquals(2000000, $lista['EV-1']['cumplimiento']['ejecutado']);
        $this->assertEquals(20, $lista['EV-1']['cumplimiento']['porcentaje']);

        $cups = $this->getJson("/api/v1/contratos/{$pgp->id}/cups")->assertOk()->json('datos.0');
        $this->assertSame([2, 1], [$cups['realizadas'], $cups['programadas']]);

        // En la cola, un paciente del PGP atrasado gana puntos frente a uno igual sin contrato.
        $conPgp = $this->ordenApta('700', '1980-01-01');
        $conPgp->update(['contrato_id' => $pgp->id]);
        $sinContrato = $this->ordenApta('800', '1980-01-01');
        $cola = collect($this->getJson('/api/v1/programacion/cola')->assertOk()->json('datos'))->keyBy('orden.id');
        $factor = collect($cola[$conPgp->id]['factores'])->firstWhere('etiqueta', 'Contrato PGP atrasado (30 % de 78 % esperado)');
        $this->assertNotNull($factor);
        $this->assertEqualsWithDelta(200 * (285 / 365 - 0.3), $factor['puntos'], 0.5);
        $this->assertGreaterThan($cola[$sinContrato->id]['puntaje'], $cola[$conPgp->id]['puntaje']);
    }
}
