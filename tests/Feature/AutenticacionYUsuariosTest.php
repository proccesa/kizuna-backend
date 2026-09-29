<?php

namespace Tests\Feature;

use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutenticacionYUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_exitoso_y_obtencion_de_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@kizuna.com',
            'password' => 'admin123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'exito',
                'mensaje',
                'datos' => [
                    'token',
                    'tipo_token',
                    'usuario' => [
                        'id',
                        'name',
                        'email',
                        'operador',
                        'roles',
                        'permisos',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('exito'));
    }

    public function test_login_con_credenciales_invalidas_retorna_422(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@kizuna.com',
            'password' => 'password_incorrecta',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'exito' => false,
            ]);
    }

    public function test_validacion_retorna_422_json_con_mensajes(): void
    {
        $admin = User::where('email', 'admin@kizuna.com')->first();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usuarios', [
                // Faltan campos requeridos: name, email, password
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'exito',
                'mensaje',
                'errores' => [
                    'name',
                    'email',
                    'password',
                ],
            ]);
    }

    public function test_crud_completo_de_usuario_con_operador_y_softdelete(): void
    {
        $admin = User::where('email', 'admin@kizuna.com')->first();
        $token = $admin->createToken('test')->plainTextToken;
        $tipoDoc = TipoDocumento::first();

        // 1. Crear Usuario con Operador asociado
        $crearResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usuarios', [
                'name' => 'Juan Perez',
                'email' => 'juan.perez@example.com',
                'password' => 'claveSegura123',
                'roles' => ['operador'],
                'operador' => [
                    'tipo_documento_id' => $tipoDoc->id,
                    'documento' => '1098765432',
                    'nombre' => 'Juan',
                    'apellido' => 'Perez Gomez',
                    'telefono' => '3201122334',
                    'direccion' => 'Avenida Siempre Viva 123',
                ],
            ]);

        $crearResponse->assertStatus(201)
            ->assertJson([
                'exito' => true,
            ]);

        $usuarioId = $crearResponse->json('datos.id');
        $this->assertDatabaseHas('users', ['id' => $usuarioId, 'email' => 'juan.perez@example.com']);
        $this->assertDatabaseHas('operadores', ['documento' => '1098765432', 'user_id' => $usuarioId]);

        // 2. Consultar Usuario (Show)
        $showResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/usuarios/{$usuarioId}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('datos.email', 'juan.perez@example.com')
            ->assertJsonPath('datos.operador.documento', '1098765432');

        // 3. Actualizar Usuario
        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/usuarios/{$usuarioId}", [
                'name' => 'Juan Carlos Perez',
                'operador' => [
                    'tipo_documento_id' => $tipoDoc->id,
                    'documento' => '1098765432',
                    'nombre' => 'Juan Carlos',
                    'apellido' => 'Perez Actualizado',
                ],
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('datos.name', 'Juan Carlos Perez')
            ->assertJsonPath('datos.operador.nombre', 'Juan Carlos');

        // 4. Eliminar Usuario (SoftDelete)
        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/usuarios/{$usuarioId}");

        $deleteResponse->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $usuarioId]);
        $this->assertSoftDeleted('operadores', ['user_id' => $usuarioId]);

        // 5. Restaurar Usuario
        $restoreResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/usuarios/{$usuarioId}/restaurar");

        $restoreResponse->assertStatus(200);
        $this->assertNotSoftDeleted('users', ['id' => $usuarioId]);
        $this->assertNotSoftDeleted('operadores', ['user_id' => $usuarioId]);
    }
}
