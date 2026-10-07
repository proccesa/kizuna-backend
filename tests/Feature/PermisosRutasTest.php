<?php

namespace Tests\Feature;

use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermisosRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function usuarioConRol(string $rol): User
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_sin_token_retorna_401(): void
    {
        $this->getJson('/api/v1/usuarios')
            ->assertStatus(401)
            ->assertJson(['exito' => false]);
    }

    public function test_sin_permiso_retorna_403_con_formato_estandar(): void
    {
        Sanctum::actingAs(User::where('email', 'operador@kizuna.com')->firstOrFail());

        $this->getJson('/api/v1/usuarios')
            ->assertStatus(403)
            ->assertExactJson([
                'exito' => false,
                'mensaje' => 'No posee los permisos necesarios para realizar esta acción.',
            ]);
    }

    public function test_el_operador_solo_puede_ver_operadores(): void
    {
        $operador = User::where('email', 'operador@kizuna.com')->firstOrFail();
        Sanctum::actingAs($operador);

        // operadores.ver: sí
        $this->getJson('/api/v1/operadores/'.$operador->operador->id)->assertOk();
        // operadores.listar y operadores.crear: no
        $this->getJson('/api/v1/operadores')->assertForbidden();
        $this->postJson('/api/v1/operadores', [])->assertForbidden();
    }

    public function test_el_rol_consulta_lista_pero_no_modifica(): void
    {
        Sanctum::actingAs($this->usuarioConRol('consulta'));

        $this->getJson('/api/v1/usuarios')->assertOk();
        $this->getJson('/api/v1/roles')->assertOk();
        $this->postJson('/api/v1/usuarios', [])->assertForbidden();
        $this->deleteJson('/api/v1/usuarios/1')->assertForbidden();
        $this->patchJson('/api/v1/usuarios/1/estado', ['activo' => false])->assertForbidden();
    }

    public function test_el_administrador_no_puede_eliminar_ni_restaurar(): void
    {
        Sanctum::actingAs($this->usuarioConRol('administrador'));

        $this->getJson('/api/v1/usuarios')->assertOk();
        $this->deleteJson('/api/v1/usuarios/2')->assertForbidden();
        $this->patchJson('/api/v1/usuarios/2/restaurar')->assertForbidden();
    }

    public function test_el_super_admin_pasa_cualquier_permiso(): void
    {
        $admin = User::where('email', 'admin@kizuna.com')->firstOrFail();
        Sanctum::actingAs($admin);

        // Permiso que no existe en la base de datos: el Gate::before lo autoriza.
        $this->assertTrue($admin->can('modulo.que-aun-no-existe'));
        $this->getJson('/api/v1/usuarios')->assertOk();
        $this->getJson('/api/v1/catalogos/regimenes')->assertOk();
    }

    public function test_sin_roles_no_accede_a_nada_protegido(): void
    {
        Sanctum::actingAs(User::factory()->create(['activo' => true]));

        $this->getJson('/api/v1/auth/perfil')->assertOk();
        $this->getJson('/api/v1/tipos-documento')->assertForbidden();
        $this->getJson('/api/v1/catalogos/departamentos')->assertForbidden();
    }
}
