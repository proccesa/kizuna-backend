<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Red\Models\Sede;
use App\Modules\Red\Rules\DigitoVerificacionNit;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestadoresSedesTest extends TestCase
{
    use RefreshDatabase;

    private int $caliId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->caliId = Municipio::where('codigo', '76001')->value('id');
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());
    }

    private function datosPrestador(array $extra = []): array
    {
        $nit = $extra['nit'] ?? '900481226';

        return array_merge([
            'nit' => $nit,
            'digito_verificacion' => (string) DigitoVerificacionNit::calcular($nit),
            'razon_social' => 'IPS Salud Vital S.A.S.',
            'nombre_comercial' => 'IPS Salud Vital',
            'codigo_habilitacion' => '7600104812',
            'naturaleza' => 'PRIVADA',
        ], $extra);
    }

    private function datosSede(array $extra = []): array
    {
        return array_merge([
            'numero_sede' => '01',
            'nombre' => 'Sede Norte',
            'municipio_id' => $this->caliId,
            'direccion' => 'Av. 6N # 28-40',
            'consultorios' => 14,
            'dias_atencion' => [1, 2, 3, 4, 5],
            'hora_apertura' => '07:00',
            'hora_cierre' => '19:00',
        ], $extra);
    }

    private function crearPrestador(array $extra = []): int
    {
        return $this->postJson('/api/v1/prestadores', $this->datosPrestador($extra))->assertCreated()->json('datos.id');
    }

    public function test_digito_de_verificacion_coincide_con_nit_real_de_la_dian(): void
    {
        $this->assertSame(4, DigitoVerificacionNit::calcular('800197268'));
    }

    public function test_crea_prestador_con_nit_completo(): void
    {
        $response = $this->postJson('/api/v1/prestadores', $this->datosPrestador())->assertCreated();

        $this->assertSame('900481226-'.DigitoVerificacionNit::calcular('900481226'), $response->json('datos.nit_completo'));
        $this->assertTrue($response->json('datos.activo'));
        $this->assertSame(0, $response->json('datos.sedes_count'));
    }

    public function test_rechaza_digito_de_verificacion_incorrecto(): void
    {
        $correcto = DigitoVerificacionNit::calcular('900481226');

        $this->postJson('/api/v1/prestadores', $this->datosPrestador(['digito_verificacion' => (string) (($correcto + 1) % 10)]))
            ->assertStatus(422)
            ->assertJsonPath('errores.digito_verificacion.0', 'El dígito de verificación no corresponde al NIT.');
    }

    public function test_rechaza_nit_duplicado_y_naturaleza_invalida(): void
    {
        $this->crearPrestador();

        $this->postJson('/api/v1/prestadores', $this->datosPrestador(['codigo_habilitacion' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('nit', 'errores');

        $this->postJson('/api/v1/prestadores', $this->datosPrestador(['nit' => '800197268', 'naturaleza' => 'OTRA', 'codigo_habilitacion' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('naturaleza', 'errores');
    }

    public function test_la_primera_sede_es_principal_y_solo_hay_una_principal(): void
    {
        $id = $this->crearPrestador();

        $norte = $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->assertCreated();
        $this->assertTrue($norte->json('datos.es_principal'));
        $this->assertSame('Santiago de Cali', $norte->json('datos.municipio.nombre'));
        $this->assertSame('07:00', $norte->json('datos.hora_apertura'));

        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '02', 'nombre' => 'Sede Centro', 'es_principal' => true]))
            ->assertCreated();

        $this->assertSame(1, Sede::where('prestador_id', $id)->where('es_principal', true)->count());
        $this->assertSame('02', Sede::where('prestador_id', $id)->where('es_principal', true)->value('numero_sede'));
    }

    public function test_valida_numero_de_sede_unico_municipio_y_horario(): void
    {
        $id = $this->crearPrestador();
        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->assertCreated();

        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())
            ->assertStatus(422)->assertJsonValidationErrorFor('numero_sede', 'errores');

        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '03', 'municipio_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrorFor('municipio_id', 'errores');

        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '04', 'hora_apertura' => '18:00', 'hora_cierre' => '08:00']))
            ->assertStatus(422)->assertJsonValidationErrorFor('hora_cierre', 'errores');

        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '05', 'dias_atencion' => [8]]))
            ->assertStatus(422)->assertJsonValidationErrorFor('dias_atencion.0', 'errores');
    }

    public function test_editar_solo_la_hora_de_cierre_se_valida_contra_la_apertura_guardada(): void
    {
        $id = $this->crearPrestador();
        $sedeId = $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->json('datos.id');

        $this->putJson("/api/v1/sedes/{$sedeId}", ['hora_cierre' => '06:00'])
            ->assertStatus(422)->assertJsonValidationErrorFor('hora_cierre', 'errores');

        $this->putJson("/api/v1/sedes/{$sedeId}", ['hora_cierre' => '20:00'])
            ->assertOk()->assertJsonPath('datos.hora_cierre', '20:00');
    }

    public function test_eliminar_y_restaurar_prestador_en_cascada(): void
    {
        $id = $this->crearPrestador();
        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->assertCreated();
        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '02', 'nombre' => 'Sede Centro']))->assertCreated();

        $this->deleteJson("/api/v1/prestadores/{$id}")->assertOk();
        $this->assertSoftDeleted('prestadores', ['id' => $id]);
        $this->assertSame(0, Sede::where('prestador_id', $id)->count());

        $this->getJson('/api/v1/prestadores?solo_eliminados=1')->assertOk()->assertJsonPath('paginacion.total', 1);

        $this->patchJson("/api/v1/prestadores/{$id}/restaurar")->assertOk()->assertJsonPath('datos.sedes_count', 2);
        $this->assertNotSoftDeleted('prestadores', ['id' => $id]);
    }

    public function test_eliminar_la_sede_principal_promueve_otra(): void
    {
        $id = $this->crearPrestador();
        $principal = $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->json('datos.id');
        $otra = $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede(['numero_sede' => '02', 'nombre' => 'Sede Centro']))->json('datos.id');

        $this->deleteJson("/api/v1/sedes/{$principal}")->assertOk();

        $this->assertTrue(Sede::find($otra)->es_principal);
    }

    public function test_no_restaura_sede_si_el_prestador_esta_eliminado(): void
    {
        $id = $this->crearPrestador();
        $sedeId = $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->json('datos.id');
        $this->deleteJson("/api/v1/prestadores/{$id}")->assertOk();

        $this->patchJson("/api/v1/sedes/{$sedeId}/restaurar")
            ->assertStatus(422)
            ->assertJsonPath('errores.prestador_id.0', 'Restaura primero el prestador de esta sede.');
    }

    public function test_listado_con_busqueda_incluye_sedes(): void
    {
        $id = $this->crearPrestador();
        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->assertCreated();
        $this->crearPrestador(['nit' => '800197268', 'razon_social' => 'Clínica Los Andes', 'nombre_comercial' => null, 'codigo_habilitacion' => null]);

        $response = $this->getJson('/api/v1/prestadores?buscar=vital')->assertOk();

        $this->assertSame(1, $response->json('paginacion.total'));
        $this->assertSame('Sede Norte', $response->json('datos.0.sedes.0.nombre'));
        $this->assertSame('Valle del Cauca', $response->json('datos.0.sedes.0.municipio.departamento.nombre'));
    }

    public function test_permisos_por_rol(): void
    {
        $id = $this->crearPrestador();

        $consulta = User::factory()->create(['activo' => true]);
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);
        $this->getJson('/api/v1/prestadores')->assertOk();
        $this->postJson('/api/v1/prestadores', $this->datosPrestador(['nit' => '800197268']))->assertForbidden();
        $this->postJson("/api/v1/prestadores/{$id}/sedes", $this->datosSede())->assertForbidden();

        Sanctum::actingAs(User::where('email', 'operador@kizuna.com')->firstOrFail());
        $this->getJson('/api/v1/sedes')->assertOk();
        $this->getJson('/api/v1/prestadores')->assertForbidden();

        $administrador = User::factory()->create(['activo' => true]);
        $administrador->assignRole('administrador');
        Sanctum::actingAs($administrador);
        $this->putJson("/api/v1/prestadores/{$id}", ['telefono' => '6024441234'])->assertOk();
        $this->deleteJson("/api/v1/prestadores/{$id}")->assertForbidden();
    }
}
