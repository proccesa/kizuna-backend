<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EspecialidadesPortafolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());
    }

    /** @return array{0: Sede, 1: Sede} */
    private function dosSedes(): array
    {
        $prestador = Prestador::create([
            'nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true,
        ]);
        $base = [
            'prestador_id' => $prestador->id, 'municipio_id' => Municipio::where('codigo', '76001')->value('id'), 'direccion' => 'Calle 1',
            'dias_atencion' => [1, 2, 3, 4, 5], 'hora_apertura' => '07:00', 'hora_cierre' => '18:00', 'activo' => true,
        ];

        return [
            Sede::create($base + ['numero_sede' => '01', 'nombre' => 'Norte', 'es_principal' => true]),
            Sede::create($base + ['numero_sede' => '02', 'nombre' => 'Centro']),
        ];
    }

    public function test_seeder_carga_la_lista_base_de_especialidades(): void
    {
        $response = $this->getJson('/api/v1/especialidades')->assertOk();

        $this->assertGreaterThanOrEqual(30, count($response->json('datos')));
        $this->assertNotNull(collect($response->json('datos'))->firstWhere('codigo', 'medicina-general'));
    }

    public function test_crud_de_especialidad_con_codigo_automatico(): void
    {
        $id = $this->postJson('/api/v1/especialidades', ['nombre' => 'Cirugía plástica'])
            ->assertCreated()
            ->assertJsonPath('datos.codigo', 'cirugia-plastica')
            ->json('datos.id');

        $this->postJson('/api/v1/especialidades', ['nombre' => 'Cirugía plástica'])
            ->assertStatus(422)->assertJsonValidationErrorFor('nombre', 'errores');

        $this->putJson("/api/v1/especialidades/{$id}", ['descripcion' => 'Estética y reconstructiva'])->assertOk();
        $this->deleteJson("/api/v1/especialidades/{$id}")->assertOk();
        $this->assertSoftDeleted('especialidades', ['id' => $id]);
        $this->patchJson("/api/v1/especialidades/{$id}/restaurar")->assertOk()->assertJsonPath('datos.nombre', 'Cirugía plástica');
    }

    public function test_relacion_cups_especialidad(): void
    {
        $cups = Cups::factory()->create(['codigo' => '890202']);
        $interna = Especialidad::where('codigo', 'medicina-interna')->firstOrFail();

        $this->postJson("/api/v1/especialidades/{$interna->id}/cups/{$cups->id}")->assertOk()->assertJsonPath('datos.cups_count', 1);
        // Idempotente
        $this->postJson("/api/v1/especialidades/{$interna->id}/cups/{$cups->id}")->assertOk()->assertJsonPath('datos.cups_count', 1);

        $this->getJson("/api/v1/catalogos/cups/{$cups->id}")->assertOk()->assertJsonPath('datos.especialidades.0.codigo', 'medicina-interna');

        $this->deleteJson("/api/v1/especialidades/{$interna->id}/cups/{$cups->id}")->assertOk()->assertJsonPath('datos.cups_count', 0);
        $this->postJson("/api/v1/especialidades/{$interna->id}/cups/999999")->assertNotFound();
    }

    public function test_portafolio_agrega_en_lote_y_omite_duplicados(): void
    {
        [$norte, $centro] = $this->dosSedes();
        $a = Cups::factory()->create();
        $b = Cups::factory()->create();

        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id, $centro->id], 'cups_ids' => [$a->id, $b->id], 'duracion_minutos' => 20])
            ->assertCreated()
            ->assertJsonPath('datos.creados', 4);

        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id], 'cups_ids' => [$a->id], 'duracion_minutos' => 30])
            ->assertCreated()
            ->assertJsonPath('datos.creados', 0)
            ->assertJsonPath('datos.existentes', 1);

        $this->assertSame(4, PortafolioItem::count());
        $this->getJson("/api/v1/portafolio?sede_id={$norte->id}")->assertOk()->assertJsonPath('paginacion.total', 2);
    }

    public function test_portafolio_valida_cups_habilitado_y_duracion(): void
    {
        [$norte] = $this->dosSedes();
        $noHabilitado = Cups::factory()->create(['habilitado' => false]);

        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id], 'cups_ids' => [$noHabilitado->id], 'duracion_minutos' => 20])
            ->assertStatus(422)->assertJsonValidationErrorFor('cups_ids.0', 'errores');

        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id], 'cups_ids' => [Cups::factory()->create()->id], 'duracion_minutos' => 2])
            ->assertStatus(422)->assertJsonValidationErrorFor('duracion_minutos', 'errores');
    }

    public function test_filtra_servicios_sin_especialidad_y_por_especialidad(): void
    {
        [$norte] = $this->dosSedes();
        $conEspecialidad = Cups::factory()->create();
        $sinEspecialidad = Cups::factory()->create();
        $general = Especialidad::where('codigo', 'medicina-general')->firstOrFail();
        $general->cups()->attach($conEspecialidad->id);

        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id], 'cups_ids' => [$conEspecialidad->id, $sinEspecialidad->id], 'duracion_minutos' => 20])->assertCreated();

        $this->getJson('/api/v1/portafolio?sin_especialidad=1')
            ->assertOk()->assertJsonPath('paginacion.total', 1)->assertJsonPath('datos.0.cups.codigo', $sinEspecialidad->codigo);

        $this->getJson("/api/v1/portafolio?especialidad_id={$general->id}")
            ->assertOk()->assertJsonPath('paginacion.total', 1)->assertJsonPath('datos.0.cups.especialidades.0.codigo', 'medicina-general');
    }

    public function test_actualiza_y_retira_servicios_del_portafolio(): void
    {
        [$norte] = $this->dosSedes();
        $this->postJson('/api/v1/portafolio', ['sede_ids' => [$norte->id], 'cups_ids' => [Cups::factory()->create()->id], 'duracion_minutos' => 20])->assertCreated();
        $item = PortafolioItem::firstOrFail();

        $this->putJson("/api/v1/portafolio/{$item->id}", ['duracion_minutos' => 40, 'activo' => false])
            ->assertOk()->assertJsonPath('datos.duracion_minutos', 40)->assertJsonPath('datos.activo', false);

        $this->deleteJson("/api/v1/portafolio/{$item->id}")->assertOk();
        $this->assertSame(0, PortafolioItem::count());
    }

    public function test_permisos_por_rol(): void
    {
        $consulta = User::factory()->create(['activo' => true]);
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);
        $this->getJson('/api/v1/especialidades')->assertOk();
        $this->getJson('/api/v1/portafolio')->assertOk();
        $this->postJson('/api/v1/especialidades', ['nombre' => 'Nueva'])->assertForbidden();
        $this->postJson('/api/v1/portafolio', [])->assertForbidden();

        $administrador = User::factory()->create(['activo' => true]);
        $administrador->assignRole('administrador');
        Sanctum::actingAs($administrador);
        $id = Especialidad::firstOrFail()->id;
        $this->deleteJson("/api/v1/especialidades/{$id}")->assertForbidden();
    }
}
