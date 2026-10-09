<?php

namespace App\Modules\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ajustes de la IPS en clave/valor. Se leen con caché.
 */
class Ajuste extends Model
{
    protected $table = 'ajustes';

    /**
     * @var list<string>
     */
    protected $fillable = ['clave', 'valor'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['valor' => 'array'];

    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        return Cache::rememberForever("ajuste:{$clave}", fn () => static::where('clave', $clave)->first()?->valor) ?? $porDefecto;
    }

    public static function guardar(string $clave, mixed $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
        Cache::forget("ajuste:{$clave}");
    }
}
