<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContratacionTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());

        $prestador = Prestador::create(['nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true]);
        $this->sede = Sede::create([
            'prestador_id' => $prestador->id, 'numero_sede' => '01', 'nombre' => 'Norte', 'municipio_id' => Municipio::where('codigo', '76001')->value('id'),
            'direccion' => 'Calle 1', 'dias_atencion' => [1, 2, 3, 4, 5], 'hora_apertura' => '07:00', 'hora_cierre' => '18:00', 'es_principal' => true, 'activo' => true,
        ]);
    }

    private function regimen(string $codigo): int
    {
        return Regimen::where('codigo', $codigo)->value('id');
    }

    private function crearEntidad(array $extra = []): int
    {
        return $this->postJson('/api/v1/entidades', $extra + [
            'nit' => '900156264', 'digito_verificacion' => '2', 'razon_social' => 'Nueva EPS S.A.', 'sigla' => 'NEPS',
            'codigo_minsalud' => 'eps037', 'tipo' => 'EPS', 'regimen_ids' => [$this->regimen('CONTRIBUTIVO'), $this->regimen('SUBSIDIADO')],
        ])->assertCreated()->json('datos.id');
    }

    private function crearContrato(int $entidadId, array $extra = []): int
    {
        return $this->postJson('/api/v1/contratos', $extra + [
            'entidad_id' => $entidadId,
            'numero' => 'CT-2026-014',
            'modalidad_contratacion_id' => ModalidadContratacion::where('codigo', 'PGP')->value('id'),
            'regimen_id' => $this->regimen('CONTRIBUTIVO'),
            'fecha_inicio' => now()->subMonths(2)->toDateString(),
            'fecha_fin' => now()->addMonths(10)->toDateString(),
            'valor' => 4850000000,
            'sede_ids' => [$this->sede->id],
        ])->assertCreated()->json('datos.id');
    }

    public function test_crud_de_entidad(): void
    {
        $id = $this->crearEntidad();
        $this->getJson("/api/v1/entidades/{$id}")->assertOk()
            ->assertJsonPath('datos.nit_completo', '900156264-2')
            ->assertJsonPath('datos.codigo_minsalud', 'EPS037')
            ->assertJsonCount(2, 'datos.regimenes');

        $this->postJson('/api/v1/entidades', ['nit' => '900156264', 'digito_verificacion' => '3', 'razon_social' => 'X', 'tipo' => 'EPS'])
            ->assertStatus(422)->assertJsonValidationErrorFor('nit', 'errores')->assertJsonValidationErrorFor('digito_verificacion', 'errores');

        $this->getJson('/api/v1/entidades?buscar=neps')->assertJsonPath('paginacion.total', 1);
        $this->putJson("/api/v1/entidades/{$id}", ['regimen_ids' => [$this->regimen('SUBSIDIADO')]])->assertOk()->assertJsonCount(1, 'datos.regimenes');

        // No se elimina una entidad con contratos
        $contratoId = $this->crearContrato($id);
        $this->deleteJson("/api/v1/entidades/{$id}")->assertStatus(422);
        $this->deleteJson("/api/v1/contratos/{$contratoId}")->assertOk();
        $this->deleteJson("/api/v1/entidades/{$id}")->assertOk();
        // El contrato no se restaura mientras su entidad esté eliminada
        $this->patchJson("/api/v1/contratos/{$contratoId}/restaurar")->assertStatus(422)->assertJsonValidationErrorFor('entidad_id', 'errores');
        $this->patchJson("/api/v1/entidades/{$id}/restaurar")->assertOk();
        $this->patchJson("/api/v1/contratos/{$contratoId}/restaurar")->assertOk();
    }

    public function test_contrato_con_estado_sedes_y_cups(): void
    {
        $entidadId = $this->crearEntidad();
        $id = $this->crearContrato($entidadId);

        $this->getJson("/api/v1/contratos/{$id}")->assertOk()
            ->assertJsonPath('datos.estado', 'VIGENTE')
            ->assertJsonPath('datos.modalidad.codigo', 'PGP')
            ->assertJsonPath('datos.sedes.0.nombre', 'Norte');

        // Mismo número en la misma entidad
        $this->postJson('/api/v1/contratos', [
            'entidad_id' => $entidadId, 'numero' => 'CT-2026-014', 'modalidad_contratacion_id' => ModalidadContratacion::where('codigo', 'EVENTO')->value('id'),
            'regimen_id' => $this->regimen('CONTRIBUTIVO'), 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2025-12-31',
        ])->assertStatus(422)->assertJsonValidationErrorFor('numero', 'errores')->assertJsonValidationErrorFor('fecha_fin', 'errores');

        $porVencer = $this->crearContrato($entidadId, ['numero' => 'CT-2026-099', 'fecha_fin' => now()->addDays(10)->toDateString()]);
        $this->getJson("/api/v1/contratos/{$porVencer}")->assertJsonPath('datos.estado', 'POR_VENCER');
        $this->getJson('/api/v1/contratos?estado=por_vencer')->assertJsonPath('paginacion.total', 1);
        $this->getJson('/api/v1/contratos/resumen')->assertOk()
            ->assertJsonPath('datos.en_ejecucion', 2)
            ->assertJsonPath('datos.por_vencer', 1)
            ->assertJsonPath('datos.por_modalidad.0.cantidad', 2);

        // CUPS pactados y su estado frente al portafolio
        $consulta = Cups::factory()->create(['codigo' => '890201']);
        $control = Cups::factory()->create(['codigo' => '890301']);
        PortafolioItem::create(['sede_id' => $this->sede->id, 'cups_id' => $consulta->id, 'duracion_minutos' => 20, 'activo' => true]);

        $this->postJson("/api/v1/contratos/{$id}/cups", ['cups_ids' => [$consulta->id, $control->id], 'cantidad' => 1200])
            ->assertCreated()->assertJsonPath('datos.creados', 2);
        $this->postJson("/api/v1/contratos/{$id}/cups", ['cups_ids' => [$consulta->id]])->assertJsonPath('datos.existentes', 1);

        $this->getJson("/api/v1/contratos/{$id}")->assertJsonPath('datos.cups_count', 2)->assertJsonPath('datos.cups_sin_portafolio_count', 1);
        $cups = $this->getJson("/api/v1/contratos/{$id}/cups")->assertOk()->json('datos');
        $this->assertTrue($cups[0]['en_portafolio']);
        $this->assertFalse($cups[1]['en_portafolio']);
        $this->assertSame(1200, $cups[0]['cantidad']);
        $this->getJson("/api/v1/contratos/{$id}/cups?sin_portafolio=1")->assertJsonPath('paginacion.total', 1)->assertJsonPath('datos.0.codigo', '890301');

        $this->putJson("/api/v1/contratos/{$id}/cups/{$control->id}", ['tarifa' => 45000])->assertOk();
        $this->deleteJson("/api/v1/contratos/{$id}/cups/{$control->id}")->assertOk();
        $this->getJson("/api/v1/contratos/{$id}")->assertJsonPath('datos.cups_count', 1);
    }

    public function test_cargue_de_poblacion_reemplazar_y_agregar(): void
    {
        $contratoId = $this->crearContrato($this->crearEntidad());
        $id = $this->postJson('/api/v1/poblaciones', ['contrato_id' => $contratoId, 'nombre' => 'Afiliados contributivo Cali'])
            ->assertCreated()->assertJsonPath('datos.cargue_al_dia', false)->json('datos.id');

        $cargar = fn (string $csv, array $extra = []) => $this->post("/api/v1/poblaciones/{$id}/cargar", ['archivo' => UploadedFile::fake()->createWithContent('poblacion.csv', $csv)] + $extra, ['Accept' => 'application/json']);
        $encabezado = 'tipo_documento;numero_documento;primer_nombre;segundo_nombre;primer_apellido;segundo_apellido;fecha_nacimiento;sexo;telefono;correo;direccion;municipio;cohortes';

        $primero = implode("\n", [
            $encabezado,
            'CC;31987120;MARÍA;FERNANDA;RÍOS;;1968-04-12;F;3001112233;;Calle 5;76001;Hipertensión|Diabetes',
            'CC;16640881;Luis;Eduardo;Cárdenas;Mora;03/09/1955;M;;;;6001;Hipertensión',
            'CC;29110457;Gloria;;Zapata;;1972-13-01;F;;;;;',
            'TI;1107889012;Kevin;;Mosquera;;2012-05-20;masculino;;kevin@correo;;;',
        ]);

        $cargar($primero, ['simular' => 1])->assertOk()
            ->assertJsonPath('datos.total', 4)
            ->assertJsonPath('datos.nuevos', 1)
            ->assertJsonPath('datos.con_errores', 3);
        $this->assertSame(0, Paciente::count());

        $valido = implode("\n", [
            $encabezado,
            'CC;31987120;MARÍA;FERNANDA;RÍOS;;1968-04-12;F;3001112233;;Calle 5;76001;Hipertensión|Diabetes',
            'CC;16640881;Luis;Eduardo;Cárdenas;Mora;03/09/1955;M;;;;76001;Hipertensión',
            'TI;1107889012;Kevin;;Mosquera;;2012-05-20;masculino;;;;;',
        ]);
        $cargar($valido)->assertOk()->assertJsonPath('datos.nuevos', 3)->assertJsonPath('datos.retirados', 0);

        $maria = Paciente::where('numero_documento', '31987120')->firstOrFail();
        $this->assertSame('María', $maria->primer_nombre);
        $this->assertSame('Ríos', $maria->primer_apellido);
        $this->assertSame('1955-09-03', Paciente::where('numero_documento', '16640881')->first()->fecha_nacimiento->toDateString());

        $this->getJson("/api/v1/poblaciones/{$id}")->assertOk()
            ->assertJsonPath('datos.pacientes_count', 3)
            ->assertJsonPath('datos.cargue_al_dia', true)
            ->assertJsonPath('datos.cohortes.0.nombre', 'Hipertensión')
            ->assertJsonPath('datos.cohortes.0.pacientes', 2)
            ->assertJsonCount(1, 'datos.cargues');
        $this->getJson("/api/v1/poblaciones/{$id}/pacientes?cohorte=Diabetes")->assertJsonPath('paginacion.total', 1)->assertJsonPath('datos.0.nombre_completo', 'María Fernanda Ríos');
        $this->getJson("/api/v1/poblaciones/{$id}/pacientes?buscar=kevin")->assertJsonPath('paginacion.total', 1);

        // Reemplazar: Kevin no viene y queda retirado; Luis viene con error pero no se retira.
        $mes = implode("\n", [
            $encabezado,
            'CC;31987120;María;Fernanda;Ríos;;1968-04-12;F;;;;;Hipertensión',
            'CC;16640881;Luis;Eduardo;Cárdenas;Mora;fecha-mala;M;;;;;',
            'CC;94301552;Jorge;Iván;Murillo;;1980-01-01;M;;;;;',
        ]);
        $cargar($mes, ['simular' => 1])->assertJsonPath('datos.retirados', 1)->assertJsonPath('datos.actualizados', 1)->assertJsonPath('datos.nuevos', 1);
        $cargar($mes)->assertOk();
        $this->getJson("/api/v1/poblaciones/{$id}")->assertJsonPath('datos.pacientes_count', 3);
        $this->getJson("/api/v1/poblaciones/{$id}/pacientes?buscar=kevin")->assertJsonPath('paginacion.total', 0);
        $this->getJson("/api/v1/poblaciones/{$id}/pacientes?buscar=kevin&incluir_retirados=1")->assertJsonPath('datos.0.activo_en_poblacion', false);

        // Agregar: Kevin vuelve sin retirar a nadie
        $cargar($encabezado."\nTI;1107889012;Kevin;;Mosquera;;2012-05-20;M;;;;;", ['modo' => 'AGREGAR'])
            ->assertJsonPath('datos.nuevos', 1)->assertJsonPath('datos.retirados', 0);
        $this->getJson("/api/v1/poblaciones/{$id}")->assertJsonPath('datos.pacientes_count', 4);

        $this->getJson('/api/v1/poblaciones/resumen')->assertOk()->assertJsonPath('datos.pacientes', 4)->assertJsonPath('datos.sin_cargue_mes', 0);
        $this->getJson('/api/v1/entidades')->assertJsonPath('datos.0.pacientes_count', 4)->assertJsonPath('datos.0.contratos_en_ejecucion_count', 1);
        $this->getJson("/api/v1/contratos/{$contratoId}")->assertJsonPath('datos.pacientes_count', 4)->assertJsonPath('datos.poblaciones.0.pacientes_count', 4);
    }

    public function test_reemplazo_que_retira_mas_de_la_mitad_advierte(): void
    {
        $contratoId = $this->crearContrato($this->crearEntidad());
        $id = $this->postJson('/api/v1/poblaciones', ['contrato_id' => $contratoId, 'nombre' => 'P'])->json('datos.id');
        $encabezado = 'tipo_documento,numero_documento,primer_nombre,primer_apellido,fecha_nacimiento,sexo';
        $filas = collect(range(1, 4))->map(fn ($i) => "CC,1000{$i},Ana,Pérez,1990-01-0{$i},F")->implode("\n");

        $this->post("/api/v1/poblaciones/{$id}/cargar", ['archivo' => UploadedFile::fake()->createWithContent('a.csv', "{$encabezado}\n{$filas}")], ['Accept' => 'application/json'])->assertOk();
        $this->post("/api/v1/poblaciones/{$id}/cargar", ['archivo' => UploadedFile::fake()->createWithContent('b.csv', "{$encabezado}\nCC,10001,Ana,Pérez,1990-01-01,F"), 'simular' => 1], ['Accept' => 'application/json'])
            ->assertJsonPath('datos.retirados', 3)
            ->assertJsonPath('datos.advertencia', 'Se retirarán 3 de 4 pacientes, más de la mitad. Verifica que el archivo esté completo.');
    }

    public function test_rol_consulta_solo_lee_contratacion(): void
    {
        $entidadId = $this->crearEntidad();
        $consulta = User::factory()->create();
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);

        $this->getJson('/api/v1/entidades')->assertOk();
        $this->getJson('/api/v1/contratos')->assertOk();
        $this->getJson('/api/v1/poblaciones')->assertOk();
        $this->putJson("/api/v1/entidades/{$entidadId}", ['sigla' => 'X'])->assertForbidden();
        $this->post('/api/v1/poblaciones/1/cargar', [], ['Accept' => 'application/json'])->assertForbidden();
    }
}
