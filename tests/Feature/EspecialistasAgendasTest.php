<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EspecialistasAgendasTest extends TestCase
{
    use RefreshDatabase;

    private Sede $norte;

    private Sede $centro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());

        $prestador = Prestador::create([
            'nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true,
        ]);
        $base = [
            'prestador_id' => $prestador->id, 'municipio_id' => Municipio::where('codigo', '76001')->value('id'), 'direccion' => 'Calle 1',
            'hora_apertura' => '07:00', 'hora_cierre' => '18:00', 'activo' => true,
        ];
        $this->norte = Sede::create($base + ['numero_sede' => '01', 'nombre' => 'Norte', 'es_principal' => true, 'dias_atencion' => [1, 2, 3, 4, 5]]);
        $this->centro = Sede::create($base + ['numero_sede' => '02', 'nombre' => 'Centro', 'dias_atencion' => [1, 2, 3, 4, 5, 6]]);
    }

    private function especialidad(string $codigo): int
    {
        return Especialidad::where('codigo', $codigo)->value('id');
    }

    private function crearEspecialista(array $extra = []): int
    {
        return $this->postJson('/api/v1/especialistas', $extra + [
            'tipo_documento_id' => TipoDocumento::where('codigo', 'CC')->value('id'),
            'numero_documento' => '1.144.209.770',
            'nombres' => 'Laura',
            'apellidos' => 'Gómez Ríos',
            'registro_profesional' => 'RM-76-1234',
            'especialidad_ids' => [$this->especialidad('medicina-general'), $this->especialidad('medicina-familiar')],
        ])->assertCreated()->json('datos.id');
    }

    private function franja(array $extra = []): array
    {
        return $extra + [
            'sede_id' => $this->norte->id,
            'especialidad_id' => $this->especialidad('medicina-general'),
            'dias' => [1, 2, 3],
            'hora_inicio' => '07:00',
            'hora_fin' => '12:00',
            'vigente_desde' => '2026-10-01',
        ];
    }

    public function test_crud_de_especialista(): void
    {
        $id = $this->crearEspecialista();

        $this->assertDatabaseHas('especialistas', ['id' => $id, 'numero_documento' => '1144209770']);
        $this->getJson("/api/v1/especialistas/{$id}")
            ->assertOk()
            ->assertJsonPath('datos.nombre_completo', 'Laura Gómez Ríos')
            ->assertJsonCount(2, 'datos.especialidades')
            ->assertJsonPath('datos.horas_semana', 0);

        // Documento duplicado
        $this->postJson('/api/v1/especialistas', [
            'tipo_documento_id' => TipoDocumento::where('codigo', 'CC')->value('id'), 'numero_documento' => '1144209770',
            'nombres' => 'Otra', 'apellidos' => 'Persona', 'especialidad_ids' => [$this->especialidad('pediatria')],
        ])->assertStatus(422)->assertJsonValidationErrorFor('numero_documento', 'errores');

        $this->postJson('/api/v1/especialistas', ['nombres' => 'X'])->assertStatus(422)->assertJsonValidationErrorFor('especialidad_ids', 'errores');

        $this->putJson("/api/v1/especialistas/{$id}", ['especialidad_ids' => [$this->especialidad('pediatria')]])
            ->assertOk()->assertJsonPath('datos.especialidades.0.codigo', 'pediatria');

        $this->getJson('/api/v1/especialistas?buscar=gomez laura')->assertOk()->assertJsonPath('paginacion.total', 0);
        $this->getJson('/api/v1/especialistas?buscar=laura gómez')->assertOk()->assertJsonPath('paginacion.total', 1);
        $this->getJson("/api/v1/especialistas?especialidad_id={$this->especialidad('pediatria')}")->assertJsonPath('paginacion.total', 1);

        $this->deleteJson("/api/v1/especialistas/{$id}")->assertOk();
        $this->assertSoftDeleted('especialistas', ['id' => $id]);
        $this->patchJson("/api/v1/especialistas/{$id}/restaurar")->assertOk()->assertJsonPath('datos.activo', true);
    }

    public function test_franjas_de_agenda_y_sus_reglas(): void
    {
        $id = $this->crearEspecialista();

        $agendaId = $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja())
            ->assertCreated()
            ->assertJsonPath('datos.horas_semana', 15)
            ->assertJsonPath('datos.hora_inicio', '07:00')
            ->json('datos.id');

        // Fuera del horario de la sede
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['hora_inicio' => '06:00', 'dias' => [4]]))
            ->assertStatus(422)->assertJsonValidationErrorFor('hora_inicio', 'errores');

        // Día en que la sede no atiende
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['dias' => [6]]))
            ->assertStatus(422)->assertJsonPath('errores.dias.0', 'La sede Norte no atiende el sábado.');

        // Especialidad que el profesional no tiene
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['especialidad_id' => $this->especialidad('cardiologia'), 'dias' => [4]]))
            ->assertStatus(422)->assertJsonValidationErrorFor('especialidad_id', 'errores');

        // Cruce con otra franja, aunque sea en otra sede
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['sede_id' => $this->centro->id, 'dias' => [3, 6], 'hora_inicio' => '11:00', 'hora_fin' => '14:00']))
            ->assertStatus(422)->assertJsonPath('errores.hora_inicio.0', 'Se cruza con la franja de 07:00 a 12:00 en Norte (miércoles).');

        // Sin cruce: la tarde en otra sede, y una franja futura que no se superpone en vigencia
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['sede_id' => $this->centro->id, 'dias' => [1, 6], 'hora_inicio' => '13:00', 'hora_fin' => '17:00']))
            ->assertCreated();
        $this->putJson("/api/v1/agendas/{$agendaId}", ['vigente_hasta' => '2026-10-31'])->assertOk();
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja(['vigente_desde' => '2026-11-01', 'especialidad_id' => $this->especialidad('medicina-familiar')]))
            ->assertCreated();

        // No se puede retirar una especialidad que está en agenda vigente
        $this->putJson("/api/v1/especialistas/{$id}", ['especialidad_ids' => [$this->especialidad('medicina-familiar')]])
            ->assertStatus(422)->assertJsonValidationErrorFor('especialidad_ids', 'errores');

        $this->getJson("/api/v1/agendas?sede_id={$this->centro->id}")->assertOk()->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.especialista.nombres', 'Laura');
        $this->getJson("/api/v1/especialistas?sede_id={$this->centro->id}")->assertJsonPath('paginacion.total', 1);

        $this->deleteJson("/api/v1/agendas/{$agendaId}")->assertOk();
        $this->assertSoftDeleted('agendas', ['id' => $agendaId]);
    }

    public function test_novedades_y_resumen(): void
    {
        $id = $this->crearEspecialista();
        $this->crearEspecialista(['numero_documento' => '31987120', 'nombres' => 'Andrés', 'apellidos' => 'Mejía']);
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja())->assertCreated();

        $hoy = now()->toDateString();
        $ausenciaId = $this->postJson("/api/v1/especialistas/{$id}/ausencias", ['tipo' => 'VACACIONES', 'fecha_inicio' => $hoy, 'fecha_fin' => now()->addDays(5)->toDateString()])
            ->assertCreated()->json('datos.id');
        $this->postJson("/api/v1/especialistas/{$id}/ausencias", ['tipo' => 'INCAPACIDAD', 'fecha_inicio' => now()->addDays(3)->toDateString(), 'fecha_fin' => now()->addDays(8)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrorFor('fecha_inicio', 'errores');

        $this->getJson('/api/v1/especialistas/resumen')->assertOk()
            ->assertJsonPath('datos.activos', 2)
            ->assertJsonPath('datos.horas_semana', 15)
            ->assertJsonPath('datos.sin_agenda', 1)
            ->assertJsonPath('datos.ausentes_hoy', 1);

        $this->getJson("/api/v1/especialistas/{$id}")->assertJsonCount(1, 'datos.ausencias');
        $this->deleteJson("/api/v1/ausencias/{$ausenciaId}")->assertOk();
        $this->getJson('/api/v1/especialistas?sin_agenda=1')->assertJsonPath('paginacion.total', 1)->assertJsonPath('datos.0.nombres', 'Andrés');
    }

    public function test_cargue_masivo_simula_y_luego_importa(): void
    {
        $this->crearEspecialista();
        $csv = implode("\r\n", [
            'tipo_documento;numero_documento;nombres;apellidos;registro_profesional;correo;telefono;especialidades',
            'CC;1144209770;Laura;Gómez Ríos;RM-1;laura@ips.co;;Pediatría',
            'CC;94301552;JORGE IVÁN;MURILLO;;;3001234567;Medicina general|psicologia',
            'XX;123;Sin;Tipo;;;;Medicina general',
            'CC;94301552;Repetido;Doc;;;;Medicina general',
            'CE;55667788;Ana;Pérez;;correo-malo;;Astrología',
            ';;;;;;;',
        ]);
        $archivo = fn () => UploadedFile::fake()->createWithContent('personal.csv', $csv);

        $this->post('/api/v1/especialistas/importar', ['archivo' => $archivo(), 'simular' => 1], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('datos.total', 5)
            ->assertJsonPath('datos.creados', 1)
            ->assertJsonPath('datos.actualizados', 1)
            ->assertJsonCount(3, 'datos.errores')
            ->assertJsonPath('datos.errores.2.fila', 6);
        $this->assertSame(1, Especialista::count());

        $this->post('/api/v1/especialistas/importar', ['archivo' => $archivo()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('datos.creados', 1);

        $jorge = Especialista::where('numero_documento', '94301552')->firstOrFail();
        $this->assertSame('Jorge Iván', $jorge->nombres);
        $this->assertEqualsCanonicalizing(['medicina-general', 'psicologia'], $jorge->especialidades->pluck('codigo')->all());
        // Al actualizar, las especialidades quedan como vienen en el archivo
        $this->assertSame(['pediatria'], Especialista::where('numero_documento', '1144209770')->first()->especialidades->pluck('codigo')->all());

        $this->post('/api/v1/especialistas/importar', ['archivo' => UploadedFile::fake()->createWithContent('x.csv', "nombre,doc\nA,1")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrorFor('archivo', 'errores');
    }

    public function test_cargue_no_quita_especialidades_con_agenda_vigente(): void
    {
        $id = $this->crearEspecialista();
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja())->assertCreated();
        $csv = "tipo_documento,numero_documento,nombres,apellidos,especialidades\nCC,1144209770,Laura,Gómez Ríos,Pediatría";

        $this->post('/api/v1/especialistas/importar', ['archivo' => UploadedFile::fake()->createWithContent('p.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('datos.actualizados', 0)
            ->assertJsonPath('datos.errores.0.mensajes.0', 'Tiene agenda vigente de Medicina general; inclúyela en el archivo o termina esas franjas primero.');
    }

    public function test_rol_consulta_no_gestiona_agendas(): void
    {
        $id = $this->crearEspecialista();
        $consulta = User::factory()->create();
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);

        $this->getJson('/api/v1/especialistas')->assertOk();
        $this->getJson('/api/v1/agendas')->assertOk();
        $this->postJson("/api/v1/especialistas/{$id}/agendas", $this->franja())->assertForbidden();
        $this->post('/api/v1/especialistas/importar', [], ['Accept' => 'application/json'])->assertForbidden();
    }
}
