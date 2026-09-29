<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TipoDocumentoSeeder::class,
            PermisoSeeder::class,
            RolSeeder::class,
            UsuarioSeeder::class,
        ]);
    }
}
