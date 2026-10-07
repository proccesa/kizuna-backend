<?php

namespace Database\Seeders;

use App\Modules\Servicios\Models\Especialidad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Lista base de especialidades y profesiones habituales en una IPS.
 * Cada IPS puede editarla, desactivar las que no ofrece o agregar nuevas.
 */
class EspecialidadSeeder extends Seeder
{
    private const ESPECIALIDADES = [
        'Medicina general',
        'Medicina familiar',
        'Medicina interna',
        'Pediatría',
        'Ginecología y obstetricia',
        'Cirugía general',
        'Anestesiología',
        'Cardiología',
        'Dermatología',
        'Endocrinología',
        'Gastroenterología',
        'Geriatría',
        'Hematología',
        'Infectología',
        'Medicina física y rehabilitación',
        'Medicina del trabajo',
        'Nefrología',
        'Neumología',
        'Neurología',
        'Oftalmología',
        'Oncología clínica',
        'Ortopedia y traumatología',
        'Otorrinolaringología',
        'Psiquiatría',
        'Radiología e imágenes diagnósticas',
        'Reumatología',
        'Urología',
        'Odontología general',
        'Optometría',
        'Psicología',
        'Nutrición y dietética',
        'Fisioterapia',
        'Fonoaudiología',
        'Terapia ocupacional',
        'Terapia respiratoria',
        'Enfermería',
        'Trabajo social',
    ];

    public function run(): void
    {
        foreach (self::ESPECIALIDADES as $nombre) {
            Especialidad::withTrashed()->firstOrCreate(
                ['codigo' => Str::slug($nombre)],
                ['nombre' => $nombre, 'activo' => true]
            );
        }
    }
}
