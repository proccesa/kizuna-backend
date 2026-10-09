<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Citas\Models\Cita;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Ausencia;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreanestesiaTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Especialista $anestesiologo;

    private Cups $colecistectomia;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 12 de octubre de 2026: la primera cita posible es el martes 13.
        Carbon::setTestNow('2026-10-12 08:00:00');
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());

        $prestador = Prestador::create(['nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true]);
        $this->sede = Sede::create([
            'prestador_id' => $prestador->id, 'numero_sede' => '01', 'nombre' => 'Norte', 'municipio_id' => Municipio::where('codigo', '76001')->value('id'),
            'direccion' => 'Calle 1', 'dias_atencion' => [1, 2, 3, 4, 5], 'hora_apertura' => '07:00', 'hora_cierre' => '18:00', 'es_principal' => true, 'activo' => true,
        ]);

        Cups::factory()->create(['codigo' => '890226', 'nombre' => 'CONSULTA DE PRIMERA VEZ POR ESPECIALISTA EN ANESTESIOLOGIA']);
        $this->colecistectomia = Cups::factory()->create(['codigo' => '512300', 'nombre' => 'COLECISTECTOMIA POR LAPAROSCOPIA', 'es_quirurgico' => true]);
        $cirugia = Especialidad::where('codigo', 'cirugia-general')->firstOrFail();
        $cirugia->cups()->attach($this->colecistectomia->id);

        $this->anestesiologo = $this->especialista('Andrea', 'anestesiologia', '1000');
        $this->especialista('Carlos', 'cirugia-general', '2000');
        $this->agenda($this->anestesiologo, '07:00', '09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function especialista(string $nombre, string $especialidad, string $documento): Especialista
    {
        $e = Especialista::create(['tipo_documento_id' => TipoDocumento::where('codigo', 'CC')->value('id'), 'numero_documento' => $documento, 'nombres' => $nombre, 'apellidos' => 'Prueba', 'activo' => true]);
        $e->especialidades()->attach(Especialidad::where('codigo', $especialidad)->value('id'));

        return $e;
    }

    private function agenda(Especialista $e, string $inicio, string $fin): void
    {
        $e->agendas()->create([
            'sede_id' => $this->sede->id, 'especialidad_id' => Especialidad::where('codigo', 'anestesiologia')->value('id'),
            'dias' => [1, 2, 3, 4, 5], 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'vigente_desde' => '2026-01-01', 'activo' => true,
        ]);
    }

    private function orden(array $extra = [], string $documento = '31987120'): array
    {
        return array_replace_recursive([
            'paciente' => [
                'tipo_documento' => 'CC', 'numero_documento' => $documento, 'primer_nombre' => 'MARÍA', 'primer_apellido' => 'RÍOS',
                'fecha_nacimiento' => '1968-04-12', 'sexo' => 'F',
            ],
            'cups' => '512300',
            'diagnostico_cie10' => 'K802',
            'prioridad' => 'ELECTIVA',
        ], $extra);
    }

    public function test_orden_valida_agenda_la_cita_de_preanestesia(): void
    {
        $orden = $this->postJson('/api/v1/ordenes', $this->orden())->assertCreated()
            ->assertJsonPath('datos.estado', 'CITA_ASIGNADA')
            ->assertJsonPath('datos.especialidad.codigo', 'cirugia-general')
            ->assertJsonPath('datos.paciente.primer_nombre', 'María')
            ->json('datos');

        $cita = Cita::where('orden_id', $orden['id'])->firstOrFail();
        $this->assertSame('2026-10-13', $cita->fecha->toDateString());
        $this->assertSame('07:00', $cita->hora_inicio);
        $this->assertSame($this->anestesiologo->id, $cita->especialista_id);

        // La segunda orden toma el siguiente cupo (30 minutos por defecto)
        $this->postJson('/api/v1/ordenes', $this->orden([], '16640881'))->assertCreated();
        $this->assertSame('07:30', Cita::latest('id')->first()->hora_inicio);

        // Si el anestesiólogo está de vacaciones el martes, la cita pasa al miércoles
        Ausencia::create(['especialista_id' => $this->anestesiologo->id, 'tipo' => 'VACACIONES', 'fecha_inicio' => '2026-10-13', 'fecha_fin' => '2026-10-13']);
        $this->postJson('/api/v1/ordenes', $this->orden([], '94301552'))->assertCreated();
        $this->assertSame('2026-10-14', Cita::latest('id')->first()->fecha->toDateString());

        $this->getJson('/api/v1/ordenes/resumen')->assertJsonPath('datos.CITA_ASIGNADA', 3);
        $this->getJson('/api/v1/citas?fecha=2026-10-13')->assertJsonPath('paginacion.total', 2);
    }

    public function test_orden_sin_especialidad_se_rechaza_y_se_puede_revalidar(): void
    {
        $hernia = Cups::factory()->create(['codigo' => '530100', 'es_quirurgico' => true]);
        $id = $this->postJson('/api/v1/ordenes', $this->orden(['cups' => '530100']))->assertCreated()
            ->assertJsonPath('datos.estado', 'RECHAZADA')
            ->assertJsonPath('datos.motivo_estado', 'Ninguna especialidad de la IPS atiende el CUPS 530100.')
            ->json('datos.id');
        $this->assertSame(0, Cita::count());

        Especialidad::where('codigo', 'cirugia-general')->first()->cups()->attach($hernia->id);
        $this->postJson("/api/v1/ordenes/{$id}/revalidar")->assertOk()->assertJsonPath('datos.estado', 'CITA_ASIGNADA');
    }

    public function test_sin_cupo_queda_pendiente_y_se_asigna_al_abrir_agenda(): void
    {
        $this->anestesiologo->agendas()->update(['activo' => false]);
        $id = $this->postJson('/api/v1/ordenes', $this->orden(['prioridad' => 'PRIORITARIA']))->assertCreated()
            ->assertJsonPath('datos.estado', 'PENDIENTE_CITA')->json('datos.id');

        $this->anestesiologo->agendas()->update(['activo' => true]);
        $this->postJson('/api/v1/ordenes/asignar-pendientes')->assertOk()->assertJsonPath('datos.asignadas', 1);
        $this->getJson("/api/v1/ordenes/{$id}")->assertJsonPath('datos.estado', 'CITA_ASIGNADA');

        // Reprogramar a otro cupo elegido por el operador
        $cupos = $this->getJson("/api/v1/ordenes/{$id}/cupos")->assertOk()->json('datos');
        $this->postJson("/api/v1/ordenes/{$id}/reprogramar", $cupos[2])->assertOk();
        $this->assertSame(1, Cita::where('orden_id', $id)->where('estado', 'PROGRAMADA')->count());
        $this->assertSame($cupos[2]['hora_inicio'], Cita::where('orden_id', $id)->where('estado', 'PROGRAMADA')->first()->hora_inicio);

        // Inasistencia: la orden vuelve a buscar cupo
        $citaId = Cita::where('orden_id', $id)->where('estado', 'PROGRAMADA')->value('id');
        $this->patchJson("/api/v1/citas/{$citaId}/estado", ['estado' => 'NO_ASISTIO'])->assertOk();
        $this->getJson("/api/v1/ordenes/{$id}")->assertJsonPath('datos.estado', 'PENDIENTE_CITA');
    }

    public function test_historia_de_preanestesia_calcula_escalas_y_da_el_aval(): void
    {
        $ordenId = $this->postJson('/api/v1/ordenes', $this->orden())->json('datos.id');
        $citaId = Cita::where('orden_id', $ordenId)->value('id');

        $historia = $this->postJson('/api/v1/historias', ['cita_id' => $citaId])->assertCreated()
            ->assertJsonPath('datos.estado', 'BORRADOR')
            ->assertJsonPath('datos.version.plantilla.codigo', 'preanestesia')
            ->assertJsonPath('datos.version.esquema.secciones.0.id', 'procedimiento')
            ->assertJsonPath('datos.respuestas.ayuno', 'Sólidos 8 horas, líquidos claros 2 horas')
            ->json('datos');
        // Abrir de nuevo la misma cita reutiliza el borrador
        $this->postJson('/api/v1/historias', ['cita_id' => $citaId])->assertJsonPath('datos.id', $historia['id']);

        $respuestas = [
            'riesgo_quirurgico' => 'INTERMEDIO', 'anestesia_propuesta' => 'GENERAL', 'opioides_postoperatorios' => true,
            'hta' => true, 'hta_controlada' => true, 'diabetes' => false, 'cardiopatia_isquemica' => false, 'fumador' => false, 'nvpo_previa' => true,
            'alergias' => false,
            'medicamentos' => [['nombre' => 'Warfarina 5 mg', 'dosis' => '1 diaria'], ['nombre' => 'Losartán 50 mg'], ['nombre' => '', 'dosis' => '']],
            'peso' => 92, 'talla' => 160, 'pa_sistolica' => 135, 'pa_diastolica' => 85, 'frecuencia_cardiaca' => 78, 'spo2' => 96,
            'perimetro_cuello' => 42, 'ronquido' => true, 'cansancio_diurno' => true, 'apneas_observadas' => false,
            'mallampati' => '3', 'distancia_tiromentoniana' => 5.5,
            'paraclinicos' => [['examen' => 'HEMOGRAMA', 'fecha' => '2026-01-10']],
            'embarazo' => true, // la paciente tiene 58 años pero el campo aplica a mujeres
        ];

        $resultado = $this->putJson("/api/v1/historias/{$historia['id']}", ['respuestas' => $respuestas])->assertOk()->json('datos.resultado');
        $this->assertSame(35.9, $resultado['imc']['valor']);
        $this->assertSame(5, $resultado['medicamentos']['dias_suspension']);
        $this->assertSame(2, $resultado['asa_sugerido']['clase']);
        $this->assertSame(4, $resultado['apfel']['puntos']);
        $this->assertSame('ALTO', $resultado['stop_bang']['riesgo']);
        $this->assertTrue($resultado['via_aerea']['dificil_predicha']);
        $mensajes = collect($resultado['alertas'])->pluck('mensaje')->implode(' | ');
        $this->assertStringContainsString('Warfarina 5 mg', $mensajes);
        $this->assertStringContainsString('no hay TP/INR registrado', $mensajes);
        $this->assertStringContainsString('más de 6 meses', $mensajes);

        // Formato inválido en borrador
        $this->putJson("/api/v1/historias/{$historia['id']}", ['respuestas' => ['peso' => 900]])->assertStatus(422)->assertJsonValidationErrorFor('peso', 'errores');

        // Finalizar sin concepto ni Mallampati falta información
        $this->postJson("/api/v1/historias/{$historia['id']}/finalizar", ['respuestas' => array_diff_key($respuestas, ['mallampati' => 1])])
            ->assertStatus(422)->assertJsonValidationErrorFor('mallampati', 'errores')->assertJsonValidationErrorFor('concepto', 'errores');

        $this->postJson("/api/v1/historias/{$historia['id']}/finalizar", ['respuestas' => $respuestas + ['asa' => '2', 'concepto' => 'APTO', 'consentimiento' => true]])
            ->assertOk()->assertJsonPath('datos.estado', 'FINALIZADA');

        $this->getJson("/api/v1/ordenes/{$ordenId}")->assertOk()
            ->assertJsonPath('datos.estado', 'APTA')
            ->assertJsonPath('datos.asa', 2)
            ->assertJsonPath('datos.aval_desde', '2026-10-12')
            ->assertJsonPath('datos.aval_hasta', '2027-04-10')
            ->assertJsonPath('datos.programable_desde', '2026-10-17');
        $this->assertSame('ATENDIDA', Cita::find($citaId)->estado);

        // Inmodificable
        $this->putJson("/api/v1/historias/{$historia['id']}", ['respuestas' => $respuestas])->assertStatus(422);

        // Anular devuelve la orden a valoración
        $this->postJson("/api/v1/historias/{$historia['id']}/anular", ['motivo' => 'Se registró en el paciente equivocado.'])->assertOk()->assertJsonPath('datos.estado', 'ANULADA');
        $this->getJson("/api/v1/ordenes/{$ordenId}")->assertJsonPath('datos.estado', 'APLAZADA')->assertJsonPath('datos.aval_hasta', null);
        $this->getJson("/api/v1/historias/{$historia['id']}")->assertJsonPath('datos.eventos.0.accion', 'ANULADA');
        // Cada consulta queda en la auditoría
        $this->assertDatabaseHas('historia_eventos', ['historia_id' => $historia['id'], 'accion' => 'CONSULTADA']);
        $this->assertDatabaseHas('historia_eventos', ['historia_id' => $historia['id'], 'accion' => 'FINALIZADA', 'detalle' => 'APTO']);
    }

    public function test_asa_iii_da_aval_de_tres_meses_y_no_apto_no_programa(): void
    {
        $ordenId = $this->postJson('/api/v1/ordenes', $this->orden())->json('datos.id');
        $historiaId = $this->postJson('/api/v1/historias', ['cita_id' => Cita::where('orden_id', $ordenId)->value('id')])->json('datos.id');
        $base = [
            'riesgo_quirurgico' => 'BAJO', 'anestesia_propuesta' => 'REGIONAL', 'alergias' => false, 'peso' => 70, 'talla' => 170,
            'pa_sistolica' => 190, 'pa_diastolica' => 115, 'frecuencia_cardiaca' => 80, 'spo2' => 97, 'mallampati' => '1',
        ];

        $resultado = $this->putJson("/api/v1/historias/{$historiaId}", ['respuestas' => $base])->json('datos.resultado');
        $this->assertSame('bloqueo', $resultado['alertas'][0]['nivel']);

        $this->postJson("/api/v1/historias/{$historiaId}/finalizar", ['respuestas' => $base + ['asa' => '3', 'concepto' => 'NO_APTO', 'motivo' => 'Hipertensión no controlada.']])->assertOk();
        $this->getJson("/api/v1/ordenes/{$ordenId}")->assertJsonPath('datos.estado', 'NO_APTA')->assertJsonPath('datos.aval_hasta', null)
            ->assertJsonPath('datos.motivo_estado', 'Hipertensión no controlada.');
    }

    public function test_integracion_externa_ordenes_y_concepto(): void
    {
        $token = $this->postJson('/api/v1/clientes-integracion', ['nombre' => 'HIS Hospital', 'permisos' => ['ordenes:escribir', 'ordenes:leer', 'historias:escribir']])
            ->assertCreated()->json('datos.token');
        $soloOrdenes = $this->postJson('/api/v1/clientes-integracion', ['nombre' => 'Otro', 'permisos' => ['ordenes:escribir']])->json('datos.token');

        $this->app['auth']->forgetGuards();
        $cabeceras = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // Sin referencia externa no se acepta
        $this->postJson('/api/v1/integracion/ordenes', $this->orden(), $cabeceras)->assertStatus(422)->assertJsonValidationErrorFor('referencia_externa', 'errores');

        $orden = $this->postJson('/api/v1/integracion/ordenes', $this->orden(['referencia_externa' => 'OQ-1001']), $cabeceras)->assertCreated()
            ->assertJsonPath('datos.estado', 'CITA_ASIGNADA')
            ->assertJsonPath('datos.cita_preanestesia.fecha', '2026-10-13')
            ->json('datos');
        // Idempotente
        $this->postJson('/api/v1/integracion/ordenes', $this->orden(['referencia_externa' => 'OQ-1001']), $cabeceras)->assertOk()->assertJsonPath('datos.id', $orden['id']);
        $this->assertSame(1, OrdenQuirurgica::count());

        // El token de integración no entra a la aplicación
        $this->getJson('/api/v1/ordenes', $cabeceras)->assertForbidden();

        // Concepto desde el sistema externo
        $this->postJson('/api/v1/integracion/historias/preanestesia', [
            'orden_referencia' => 'OQ-1001', 'fecha_valoracion' => '2026-10-12', 'concepto' => 'APTO', 'asa' => 3,
            'anestesiologo' => ['nombre' => 'Dra. Paula Mejía', 'registro' => 'RM-1234'],
        ], $cabeceras)->assertCreated()->assertJsonPath('datos.orden.estado', 'APTA')->assertJsonPath('datos.orden.aval_hasta', '2027-01-10');
        // La cita del martes ya no hace falta: el cupo se libera.
        $this->assertSame('CANCELADA', Cita::where('orden_id', $orden['id'])->value('estado'));

        $this->getJson('/api/v1/integracion/ordenes/OQ-1001', $cabeceras)->assertOk()->assertJsonPath('datos.concepto', 'APTO');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/integracion/historias/preanestesia', ['orden_referencia' => 'OQ-1001'], ['Authorization' => "Bearer {$soloOrdenes}", 'Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_cargue_csv_de_ordenes(): void
    {
        $csv = implode("\n", [
            'referencia;tipo_documento;numero_documento;primer_nombre;primer_apellido;fecha_nacimiento;sexo;cups;diagnostico_cie10;prioridad',
            'A-1;CC;31987120;María;Ríos;12/04/1968;F;512300;K80.2;ELECTIVA',
            'A-2;CC;16640881;Luis;Cárdenas;1955-09-03;M;512300;;PRIORITARIA',
            'A-3;CC;94301552;Jorge;Murillo;1980-01-01;M;999999;;',
            'A-1;CC;12345;Repetida;Ref;1990-01-01;F;512300;;',
        ]);
        $archivo = fn () => UploadedFile::fake()->createWithContent('ordenes.csv', $csv);

        $this->post('/api/v1/ordenes/importar', ['archivo' => $archivo(), 'simular' => 1], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('datos.nuevas', 2)->assertJsonPath('datos.con_errores', 2);
        $this->assertSame(0, OrdenQuirurgica::count());

        $this->post('/api/v1/ordenes/importar', ['archivo' => $archivo()], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('datos.nuevas', 2)->assertJsonPath('datos.citas_asignadas', 2);
        $this->post('/api/v1/ordenes/importar', ['archivo' => $archivo()], ['Accept' => 'application/json'])->assertJsonPath('datos.duplicadas', 2);
        $this->assertSame('K802', OrdenQuirurgica::where('referencia_externa', 'A-1')->value('diagnostico_cie10'));
    }

    public function test_reglas_configurables_y_permisos(): void
    {
        $this->putJson('/api/v1/ordenes/reglas', ['vigencia_dias_por_asa' => ['2' => 120], 'duracion_minutos' => 20])->assertOk()
            ->assertJsonPath('datos.vigencia_dias_por_asa.2', 120)
            ->assertJsonPath('datos.vigencia_dias_por_asa.3', 90)
            ->assertJsonPath('datos.cups_consulta.codigo', '890226');

        $this->postJson('/api/v1/ordenes', $this->orden())->assertCreated();
        $this->postJson('/api/v1/ordenes', $this->orden([], '16640881'))->assertCreated();
        $this->assertSame('07:20', Cita::latest('id')->first()->hora_inicio);

        $consulta = User::factory()->create();
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);
        $this->getJson('/api/v1/ordenes')->assertOk();
        $this->postJson('/api/v1/historias', [])->assertForbidden();
        $this->putJson('/api/v1/ordenes/reglas', [])->assertForbidden();
    }
}
