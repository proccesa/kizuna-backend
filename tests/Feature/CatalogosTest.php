<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Departamento;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Users\Models\User;
use Database\Seeders\DivipolaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // El rol operador tiene catalogos.listar: los catálogos están disponibles para todo el personal.
        Sanctum::actingAs(User::where('email', 'operador@kizuna.com')->firstOrFail());
    }

    public function test_divipola_carga_todos_los_departamentos_y_municipios(): void
    {
        $this->assertSame(33, Departamento::count());
        $this->assertSame(1122, Municipio::count());
    }

    public function test_divipola_es_idempotente(): void
    {
        $this->seed(DivipolaSeeder::class);

        $this->assertSame(33, Departamento::count());
        $this->assertSame(1122, Municipio::count());
    }

    public function test_lista_departamentos_con_cantidad_de_municipios(): void
    {
        $response = $this->getJson('/api/v1/catalogos/departamentos')->assertOk();

        $this->assertCount(33, $response->json('datos'));
        $valle = collect($response->json('datos'))->firstWhere('codigo', '76');
        $this->assertSame('Valle del Cauca', $valle['nombre']);
        $this->assertSame(42, $valle['municipios_count']);
    }

    public function test_lista_municipios_de_un_departamento(): void
    {
        $antioquia = Departamento::where('codigo', '05')->firstOrFail();

        $response = $this->getJson("/api/v1/catalogos/departamentos/{$antioquia->id}/municipios")->assertOk();

        $this->assertCount(125, $response->json('datos'));
    }

    public function test_departamento_inexistente_retorna_404(): void
    {
        $this->getJson('/api/v1/catalogos/departamentos/99999/municipios')
            ->assertNotFound()
            ->assertJson(['exito' => false]);
    }

    public function test_busqueda_de_municipios_ignora_tildes_y_mayusculas(): void
    {
        $response = $this->getJson('/api/v1/catalogos/municipios?buscar=medellin')->assertOk();

        $medellin = collect($response->json('datos'))->firstWhere('codigo', '05001');
        $this->assertNotNull($medellin);
        $this->assertSame('Medellín', $medellin['nombre']);
        $this->assertSame('Antioquia', $medellin['departamento']['nombre']);
        $this->assertArrayNotHasKey('nombre_normalizado', $medellin);
    }

    public function test_busqueda_de_municipios_por_codigo_dane_y_departamento(): void
    {
        $valle = Departamento::where('codigo', '76')->firstOrFail();

        $response = $this->getJson("/api/v1/catalogos/municipios?buscar=76001&departamento_id={$valle->id}")->assertOk();

        $this->assertSame(1, $response->json('paginacion.total'));
        $this->assertSame('Santiago de Cali', $response->json('datos.0.nombre'));
    }

    public function test_paginacion_de_municipios_tiene_limite(): void
    {
        $response = $this->getJson('/api/v1/catalogos/municipios?por_pagina=5000')->assertOk();

        $this->assertSame(100, $response->json('paginacion.por_pagina'));
        $this->assertSame(1122, $response->json('paginacion.total'));
    }

    public function test_lista_regimenes_y_modalidades_de_contratacion(): void
    {
        $regimenes = $this->getJson('/api/v1/catalogos/regimenes')->assertOk()->json('datos');
        $this->assertSame(['CONTRIBUTIVO', 'SUBSIDIADO', 'ESPECIAL'], array_column($regimenes, 'codigo'));

        $modalidades = $this->getJson('/api/v1/catalogos/modalidades-contratacion')->assertOk()->json('datos');
        $this->assertSame(['PGP', 'EVENTO', 'CAPITA'], array_column($modalidades, 'codigo'));
    }
}
