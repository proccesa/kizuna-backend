<?php

namespace Database\Seeders;

use App\Modules\Users\Models\Operador;
use App\Modules\Users\Models\TipoDocumento;
use App\Modules\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tipoCC = TipoDocumento::where('codigo', 'CC')->first();
        $tipoDocumentoId = $tipoCC ? $tipoCC->id : 1;

        // 1. Usuario Super Administrador Inicial
        $admin = User::firstOrCreate(
            ['email' => 'admin@kizuna.com'],
            [
                'name' => 'Super Administrador',
                'password' => Hash::make('admin123456'),
                'activo' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles(['super-admin']);

        Operador::updateOrCreate(
            ['user_id' => $admin->id],
            [
                'tipo_documento_id' => $tipoDocumentoId,
                'documento' => '1000000001',
                'nombre' => 'Super',
                'apellido' => 'Administrador',
                'telefono' => '3001234567',
                'direccion' => 'Calle Principal # 1-01',
                'activo' => true,
            ]
        );

        // 2. Usuario Operador de Ejemplo
        $operadorUser = User::firstOrCreate(
            ['email' => 'operador@kizuna.com'],
            [
                'name' => 'Operador Principal',
                'password' => Hash::make('operador123456'),
                'activo' => true,
                'email_verified_at' => now(),
            ]
        );

        $operadorUser->syncRoles(['operador']);

        Operador::updateOrCreate(
            ['user_id' => $operadorUser->id],
            [
                'tipo_documento_id' => $tipoDocumentoId,
                'documento' => '1000000002',
                'nombre' => 'Carlos',
                'apellido' => 'Operador',
                'telefono' => '3109876543',
                'direccion' => 'Carrera 15 # 45-20',
                'activo' => true,
            ]
        );
    }
}
