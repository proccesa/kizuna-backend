<?php

namespace Tests\Feature;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Reserva;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cups $cups;

    private Sala $q1;

    private Sala $q2;

    /** @var array<string, Item> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 08:00:00');
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@kizuna.com')->firstOrFail());

        $prestador = Prestador::create(['nit' => '900481226', 'digito_verificacion' => '5', 'razon_social' => 'IPS Prueba', 'naturaleza' => 'PRIVADA', 'activo' => true]);
        $this->sede = Sede::create([
            'prestador_id' => $prestador->id, 'numero_sede' => '01', 'nombre' => 'Norte', 'municipio_id' => Municipio::where('codigo', '76001')->value('id'),
            'direccion' => 'Calle 1', 'dias_atencion' => [1, 2, 3, 4, 5], 'hora_apertura' => '07:00', 'hora_cierre' => '18:00', 'es_principal' => true, 'activo' => true,
        ]);
        $this->cups = Cups::factory()->create(['codigo' => '512104', 'es_quirurgico' => true]);
        PortafolioItem::create(['sede_id' => $this->sede->id, 'cups_id' => $this->cups->id, 'duracion_minutos' => 90, 'tipo_sala' => 'QUIROFANO', 'activo' => true]);

        $this->q1 = Sala::create(['sede_id' => $this->sede->id, 'codigo' => 'Q1', 'nombre' => 'Quirófano 1', 'tipo' => 'QUIROFANO', 'activo' => true]);
        $this->q2 = Sala::create(['sede_id' => $this->sede->id, 'codigo' => 'Q2', 'nombre' => 'Quirófano 2', 'tipo' => 'QUIROFANO', 'activo' => true]);

        $this->items = [
            'torre' => Item::create(['tipo' => 'EQUIPO', 'codigo' => 'EQ-TORRE', 'nombre' => 'Torre de laparoscopia', 'periodicidad_mantenimiento_meses' => 6, 'activo' => true]),
            'maquina' => Item::create(['tipo' => 'EQUIPO', 'codigo' => 'EQ-MAQ', 'nombre' => 'Máquina de anestesia', 'requiere_calibracion' => true, 'periodicidad_calibracion_meses' => 12, 'activo' => true]),
            'caja' => Item::create(['tipo' => 'INSTRUMENTAL', 'codigo' => 'IN-LAPA', 'nombre' => 'Caja de laparoscopia', 'minutos_esterilizacion' => 240, 'activo' => true]),
            'trocar' => Item::create(['tipo' => 'INSUMO', 'codigo' => 'IS-TROC10', 'nombre' => 'Trocar 10 mm', 'unidad_medida' => 'unidad', 'stock_minimo' => 5, 'activo' => true]),
        ];

        Unidad::create(['item_id' => $this->items['torre']->id, 'sede_id' => $this->sede->id, 'codigo' => 'TL-01', 'estado' => 'OPERATIVO', 'proximo_mantenimiento' => '2027-01-10']);
        Unidad::create(['item_id' => $this->items['maquina']->id, 'sede_id' => $this->sede->id, 'sala_id' => $this->q1->id, 'codigo' => 'MA-01', 'estado' => 'OPERATIVO', 'calibracion_vence' => '2027-03-01']);
        Unidad::create(['item_id' => $this->items['caja']->id, 'sede_id' => $this->sede->id, 'codigo' => 'CL-01', 'estado' => 'OPERATIVO']);
        Existencia::create(['item_id' => $this->items['trocar']->id, 'sede_id' => $this->sede->id, 'lote' => 'A', 'vence' => '2027-10-01', 'cantidad' => 10]);

        $this->putJson("/api/v1/inventario/requerimientos/{$this->cups->id}", ['items' => [
            ['item_id' => $this->items['torre']->id, 'cantidad' => 1],
            ['item_id' => $this->items['maquina']->id, 'cantidad' => 1],
            ['item_id' => $this->items['caja']->id, 'cantidad' => 1],
            ['item_id' => $this->items['trocar']->id, 'cantidad' => 4],
        ]])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function verificar(string $hora = '08:00', string $fecha = '2026-10-13')
    {
        return $this->getJson("/api/v1/inventario/verificar?cups_id={$this->cups->id}&sede_id={$this->sede->id}&fecha={$fecha}&hora={$hora}")->assertOk();
    }

    private function recurso(array $verificacion, string $codigo): array
    {
        return collect($verificacion['recursos'])->firstWhere('item.codigo', $codigo);
    }

    public function test_requerimientos_base_y_ajuste_por_sede(): void
    {
        $this->putJson("/api/v1/inventario/requerimientos/{$this->cups->id}", ['sede_id' => $this->sede->id, 'items' => [
            ['item_id' => $this->items['trocar']->id, 'cantidad' => 3],
        ]])->assertOk();

        $detalle = $this->getJson("/api/v1/inventario/requerimientos/{$this->cups->id}")->assertOk()->json('datos');
        $this->assertCount(4, $detalle['base']);
        $efectivos = collect($detalle['sedes'][0]['efectivos']);
        $this->assertSame(3, $efectivos->firstWhere('item_id', $this->items['trocar']->id)['cantidad']);
        $this->assertSame('QUIROFANO', $detalle['sedes'][0]['tipo_sala']);

        // Cantidad 0 en el ajuste quita el ítem en esa sede
        $this->putJson("/api/v1/inventario/requerimientos/{$this->cups->id}", ['sede_id' => $this->sede->id, 'items' => [['item_id' => $this->items['caja']->id, 'cantidad' => 0]]])->assertOk();
        $this->assertCount(3, $this->getJson("/api/v1/inventario/requerimientos/{$this->cups->id}")->json('datos.sedes.0.efectivos'));

        $this->getJson('/api/v1/inventario/requerimientos')->assertJsonPath('datos.0.requerimientos_count', 4)->assertJsonPath('datos.0.sedes_ajustadas_count', 1);
    }

    public function test_verifica_sala_equipos_fijos_instrumental_e_insumos(): void
    {
        $v = $this->verificar()->json('datos');
        $this->assertTrue($v['viable']);
        $this->assertSame('Q1', $v['sala']['codigo']);
        $this->assertSame('2026-10-13 09:30', $v['fin']);

        // Q1 ocupado: en Q2 no hay máquina de anestesia (la única está fija en Q1)
        Reserva::create(['sala_id' => $this->q1->id, 'sede_id' => $this->sede->id, 'inicio' => '2026-10-13 07:30', 'fin' => '2026-10-13 09:00', 'libera_en' => '2026-10-13 09:00', 'estado' => 'ACTIVA']);
        $v = $this->verificar()->json('datos');
        $this->assertFalse($v['viable']);
        $this->assertSame('Q2', $v['sala']['codigo']);
        $this->assertContains('1 instalada(s) en otra sala', $this->recurso($v, 'EQ-MAQ')['motivos']);

        // Con una máquina móvil, Q2 sirve
        Unidad::create(['item_id' => $this->items['maquina']->id, 'sede_id' => $this->sede->id, 'codigo' => 'MA-02', 'estado' => 'OPERATIVO', 'calibracion_vence' => '2027-03-01']);
        $this->assertTrue($this->verificar()->json('datos.viable'));

        // La caja se usó a las 06:00–07:00: con 4 h de esterilización no está lista a las 08:00
        Reserva::create(['item_id' => $this->items['caja']->id, 'unidad_id' => Unidad::where('codigo', 'CL-01')->value('id'), 'sede_id' => $this->sede->id, 'inicio' => '2026-10-13 06:00', 'fin' => '2026-10-13 07:00', 'libera_en' => '2026-10-13 11:00', 'estado' => 'ACTIVA']);
        $v = $this->verificar()->json('datos');
        $this->assertFalse($this->recurso($v, 'IN-LAPA')['ok']);
        $this->assertTrue($this->verificar('11:00')->json('datos.viable'));

        // Insumos: 8 reservados de 10 → quedan 2 y se necesitan 4
        Reserva::create(['item_id' => $this->items['trocar']->id, 'sede_id' => $this->sede->id, 'cantidad' => 8, 'inicio' => '2026-10-14 07:00', 'fin' => '2026-10-14 08:00', 'libera_en' => '2026-10-14 08:00', 'estado' => 'ACTIVA']);
        $trocar = $this->recurso($this->verificar('11:00')->json('datos'), 'IS-TROC10');
        $this->assertSame(2, $trocar['disponible']);
        $this->assertFalse($trocar['ok']);
    }

    public function test_mantenimiento_y_calibracion_bloquean_y_se_actualizan(): void
    {
        $torre = Unidad::where('codigo', 'TL-01')->first();
        $mantenimiento = $this->postJson("/api/v1/inventario/unidades/{$torre->id}/mantenimientos", ['tipo' => 'PREVENTIVO', 'inicio' => '2026-10-13 07:00', 'fin' => '2026-10-13 12:00', 'responsable' => 'Biomédica'])
            ->assertCreated()->json('datos.id');
        $v = $this->verificar()->json('datos');
        $this->assertSame(['TL-01: Mantenimiento programado en ese horario'], $this->recurso($v, 'EQ-TORRE')['motivos']);

        $this->patchJson("/api/v1/inventario/mantenimientos/{$mantenimiento}", ['estado' => 'TERMINADO'])->assertOk();
        $torre->refresh();
        $this->assertSame('2026-10-13', $torre->ultimo_mantenimiento->toDateString());
        $this->assertSame('2027-04-13', $torre->proximo_mantenimiento->toDateString());

        // Calibración vencida para la fecha → no sirve
        Unidad::where('codigo', 'MA-01')->update(['calibracion_vence' => '2026-10-01']);
        $this->assertSame(['MA-01: Calibración vencida'], $this->recurso($this->verificar()->json('datos'), 'EQ-MAQ')['motivos']);

        $this->getJson('/api/v1/inventario/unidades?tipo=EQUIPO&alerta=vencidos')->assertJsonPath('paginacion.total', 1)
            ->assertJsonPath('datos.0.alertas.0.nivel', 'bloqueo');

        // El mantenimiento preventivo vencido solo avisa: el equipo se sigue usando.
        Unidad::where('codigo', 'MA-01')->update(['calibracion_vence' => '2027-03-01']);
        Unidad::where('codigo', 'TL-01')->update(['proximo_mantenimiento' => '2026-09-30']);
        $v = $this->verificar()->json('datos');
        $this->assertTrue($v['viable']);
        $this->assertSame(['TL-01: mantenimiento preventivo vencido desde el 30/09/2026'], $v['avisos']);
        $this->assertSame('aviso', Unidad::where('codigo', 'TL-01')->first()->alertas[0]['nivel']);
        $this->getJson('/api/v1/inventario/resumen')->assertJsonPath('datos.equipos.calibracion_vencida', 0)->assertJsonPath('datos.equipos.mantenimiento_vencido', 1)->assertJsonPath('datos.cups.sin_requerimientos', 0);
    }

    public function test_movimientos_de_insumos_por_lote(): void
    {
        $item = $this->items['trocar']->id;
        $sede = $this->sede->id;
        $this->postJson('/api/v1/inventario/movimientos', ['item_id' => $item, 'sede_id' => $sede, 'tipo' => 'ENTRADA', 'cantidad' => 5, 'lote' => 'B', 'vence' => '2027-01-15'])
            ->assertCreated()->assertJsonPath('datos.total_sede', 15);

        // La salida sin lote descuenta primero del que vence antes (B)
        $this->postJson('/api/v1/inventario/movimientos', ['item_id' => $item, 'sede_id' => $sede, 'tipo' => 'SALIDA', 'cantidad' => 7])->assertCreated();
        $this->assertSame(0, Existencia::where('lote', 'B')->value('cantidad'));
        $this->assertSame(8, Existencia::where('lote', 'A')->value('cantidad'));

        $this->postJson('/api/v1/inventario/movimientos', ['item_id' => $item, 'sede_id' => $sede, 'tipo' => 'SALIDA', 'cantidad' => 50])->assertStatus(422);
        $this->postJson('/api/v1/inventario/movimientos', ['item_id' => $item, 'sede_id' => $sede, 'tipo' => 'AJUSTE', 'cantidad' => 3, 'lote' => 'A', 'motivo' => 'Conteo físico'])->assertCreated();

        $existencias = $this->getJson('/api/v1/inventario/existencias')->assertOk()->json('datos.0');
        $this->assertSame(3, $existencias['total']);
        $this->assertTrue($existencias['bajo_minimo']);
        $this->assertCount(4, $this->getJson("/api/v1/inventario/items/{$item}/movimientos")->json('datos'));
    }

    public function test_cargue_csv_e_integracion(): void
    {
        $csv = implode("\n", [
            'tipo;item_codigo;item_nombre;codigo;sede;sala;marca;modelo;estado;proximo_mantenimiento',
            'EQUIPO;EQ-ELEC;Electrobisturí;EB-01;Norte;Q2;Valleylab;FT10;OPERATIVO;15/01/2027',
            'EQUIPO;EQ-TORRE;;TL-01;Norte;;Stryker;1688;OPERATIVO;',
            'EQUIPO;EQ-NUEVO;;X-1;Norte;;;;;',
            'INSTRUMENTAL;IN-LAPA;;CL-02;Sur;;;;;',
        ]);
        $this->post('/api/v1/inventario/importar/unidades', ['archivo' => UploadedFile::fake()->createWithContent('equipos.csv', $csv), 'simular' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('datos.creados', 1)->assertJsonPath('datos.actualizados', 1)->assertJsonPath('datos.con_errores', 2);
        $this->post('/api/v1/inventario/importar/unidades', ['archivo' => UploadedFile::fake()->createWithContent('equipos.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($this->q2->id, Unidad::where('codigo', 'EB-01')->value('sala_id'));
        $this->assertSame('Stryker', Unidad::where('codigo', 'TL-01')->value('marca'));

        $token = $this->postJson('/api/v1/clientes-integracion', ['nombre' => 'ERP', 'permisos' => ['inventario:escribir']])->json('datos.token');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/integracion/inventario/existencias', ['existencias' => [
            ['item_codigo' => 'IS-TROC10', 'sede' => $this->sede->id, 'lote' => 'A', 'cantidad' => 25],
            ['item_codigo' => 'IS-GASA', 'item_nombre' => 'Gasa estéril', 'unidad_medida' => 'paquete', 'sede' => 'Norte', 'cantidad' => 100, 'vence' => '2028-01-01'],
        ]], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->assertOk()->assertJsonPath('datos.creados', 1)->assertJsonPath('datos.actualizados', 1);
        $this->assertSame(25, Existencia::where('lote', 'A')->value('cantidad'));
        $this->assertSame(100, Existencia::whereHas('item', fn ($q) => $q->where('codigo', 'IS-GASA'))->value('cantidad'));
    }

    public function test_salas_y_permisos(): void
    {
        $this->postJson('/api/v1/salas', ['sede_id' => $this->sede->id, 'codigo' => 'Q1', 'nombre' => 'Otro', 'tipo' => 'QUIROFANO'])->assertStatus(422);
        $this->deleteJson("/api/v1/salas/{$this->q1->id}")->assertStatus(422); // tiene la máquina fija
        $this->getJson("/api/v1/salas?sede_id={$this->sede->id}")->assertOk()->assertJsonPath('datos.0.equipos_count', 1);

        $consulta = User::factory()->create();
        $consulta->assignRole('consulta');
        Sanctum::actingAs($consulta);
        $this->getJson('/api/v1/inventario/unidades')->assertOk();
        $this->postJson('/api/v1/inventario/items', ['tipo' => 'INSUMO', 'codigo' => 'X', 'nombre' => 'X'])->assertForbidden();
    }
}
