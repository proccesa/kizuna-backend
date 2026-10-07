<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Services\ImportadorCups;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoCupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::where('email', 'operador@kizuna.com')->firstOrFail());
    }

    public function test_importa_el_archivo_oficial_de_sispro_y_es_idempotente(): void
    {
        $importador = app(ImportadorCups::class);
        $ruta = database_path('data/cups.csv');

        $resultado = $importador->importar($ruta);

        $this->assertSame(13640, $resultado['procesados']);
        $this->assertSame(13640, Cups::count());
        $this->assertSame(10024, Cups::where('habilitado', true)->count());

        $consulta = Cups::where('codigo', '890201')->firstOrFail();
        $this->assertSame('CONSULTA DE PRIMERA VEZ POR MEDICINA GENERAL', $consulta->nombre);
        $this->assertNotNull($consulta->actualizado_minsalud);

        $importador->importar($ruta);
        $this->assertSame(13640, Cups::count());
    }

    public function test_deshabilita_los_codigos_que_ya_no_estan_en_el_archivo(): void
    {
        $retirado = Cups::factory()->create(['codigo' => '999998']);
        $archivo = tempnam(sys_get_temp_dir(), 'cups');
        file_put_contents($archivo, "Codigo,Nombre,Descripcion,Habilitado\n890201,CONSULTA DE PRIMERA VEZ POR MEDICINA GENERAL,ANEXO,SI\n");

        $resultado = app(ImportadorCups::class)->importar($archivo, deshabilitarAusentes: true);

        $this->assertSame(1, $resultado['deshabilitados']);
        $this->assertFalse($retirado->fresh()->habilitado);
        unlink($archivo);
    }

    public function test_rechaza_archivos_sin_las_columnas_de_sispro(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'cups');
        file_put_contents($archivo, "code,name\n890201,X\n");

        $this->artisan('kizuna:importar-cups', ['archivo' => $archivo])->assertFailed();
        unlink($archivo);
    }

    public function test_busca_por_prefijo_de_codigo_y_por_palabras_sin_tildes(): void
    {
        Cups::factory()->create(['codigo' => '890201', 'nombre' => 'CONSULTA DE PRIMERA VEZ POR MEDICINA GENERAL', 'nombre_normalizado' => 'CONSULTA DE PRIMERA VEZ POR MEDICINA GENERAL']);
        Cups::factory()->create(['codigo' => '890208', 'nombre' => 'CONSULTA DE PRIMERA VEZ POR PSICOLOGIA', 'nombre_normalizado' => 'CONSULTA DE PRIMERA VEZ POR PSICOLOGIA']);
        Cups::factory()->create(['codigo' => '895100', 'nombre' => 'ELECTROCARDIOGRAMA DE RITMO O DE SUPERFICIE SOD', 'nombre_normalizado' => 'ELECTROCARDIOGRAMA DE RITMO O DE SUPERFICIE SOD', 'habilitado' => false]);

        $this->getJson('/api/v1/catalogos/cups?buscar=8902')->assertOk()->assertJsonPath('paginacion.total', 2);
        $this->getJson('/api/v1/catalogos/cups?buscar=psicología primera')
            ->assertOk()
            ->assertJsonPath('paginacion.total', 1)
            ->assertJsonPath('datos.0.codigo', '890208');

        // Por defecto solo los habilitados; habilitado=todos incluye los demás.
        $this->getJson('/api/v1/catalogos/cups?buscar=electrocardiograma')->assertOk()->assertJsonPath('paginacion.total', 0);
        $this->getJson('/api/v1/catalogos/cups?buscar=electrocardiograma&habilitado=todos')->assertOk()->assertJsonPath('paginacion.total', 1);
    }
}
