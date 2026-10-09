<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Movimiento;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Red\Models\Sala;
use App\Modules\Red\Models\Sede;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carga o sincroniza inventario desde otro sistema (endpoint) o desde un CSV.
 *
 * - Unidades (equipos biomédicos y cajas de instrumental): se identifican por `codigo` (placa).
 * - Existencias de insumos: la cantidad enviada es el conteo actual del lote (reemplaza la anterior).
 *
 * Si el ítem (`item_codigo`) no existe, se crea con `item_nombre`. Las sedes se indican por id o por nombre.
 */
class SincronizadorInventario
{
    /** @var array<string, ?int> */
    private array $sedes = [];

    /**
     * @param  list<array<string, mixed>>  $filas  Indexadas por número de fila.
     * @return array{total: int, creados: int, actualizados: int, errores: list<array>, simulado: bool}
     */
    public function unidades(array $filas, bool $simular, string $origen = 'API'): array
    {
        $resultado = $this->vacio(count($filas), $simular);

        foreach ($filas as $numero => $f) {
            $mensajes = [];
            $tipo = strtoupper(trim((string) ($f['tipo'] ?? '')));
            $codigo = trim((string) ($f['codigo'] ?? ''));
            $sedeId = $this->sede($f['sede'] ?? $f['sede_id'] ?? null);
            $estado = strtoupper(trim((string) ($f['estado'] ?? 'OPERATIVO'))) ?: 'OPERATIVO';

            if (! in_array($tipo, ['EQUIPO', 'INSTRUMENTAL'], true)) {
                $mensajes[] = 'El tipo debe ser EQUIPO o INSTRUMENTAL.';
            }
            if ($codigo === '' || mb_strlen($codigo) > 60) {
                $mensajes[] = 'Falta el código (placa) de la unidad.';
            }
            if (empty($f['item_codigo'])) {
                $mensajes[] = 'Falta el código del tipo de equipo o caja (item_codigo).';
            }
            if (! $sedeId) {
                $mensajes[] = 'La sede no existe.';
            }
            if (! in_array($estado, Unidad::ESTADOS, true)) {
                $mensajes[] = 'El estado debe ser OPERATIVO, MANTENIMIENTO, FUERA_SERVICIO o BAJA.';
            }
            $fechas = [];
            foreach (['ultimo_mantenimiento', 'proximo_mantenimiento', 'calibracion_vence'] as $campo) {
                $fechas[$campo] = $this->fecha($f[$campo] ?? null, $campo, $mensajes);
            }
            $salaId = null;
            if (! empty($f['sala']) && $sedeId) {
                $salaId = Sala::where('sede_id', $sedeId)->where('codigo', trim((string) $f['sala']))->value('id');
                if (! $salaId) {
                    $mensajes[] = "La sala \"{$f['sala']}\" no existe en esa sede.";
                }
            }

            $item = ! empty($f['item_codigo']) ? Item::where('codigo', trim((string) $f['item_codigo']))->first() : null;
            if ($item && $item->tipo !== $tipo && $tipo !== '') {
                $mensajes[] = "El ítem {$item->codigo} es de tipo {$item->tipo}, no {$tipo}.";
            }
            if (! $item && empty($f['item_nombre'])) {
                $mensajes[] = 'El tipo de equipo o caja no existe: envía item_nombre para crearlo.';
            }

            if ($mensajes) {
                $resultado['errores'][] = ['fila' => $numero, 'documento' => $codigo, 'mensajes' => $mensajes];

                continue;
            }

            $existe = Unidad::withTrashed()->where('codigo', $codigo)->first();
            $resultado[$existe ? 'actualizados' : 'creados']++;
            if ($simular) {
                continue;
            }

            try {
                DB::transaction(function () use ($item, $f, $tipo, $existe, $codigo, $sedeId, $salaId, $estado, $fechas) {
                    $item ??= Item::create(['tipo' => $tipo, 'codigo' => trim((string) $f['item_codigo']), 'nombre' => trim((string) $f['item_nombre']), 'activo' => true]);
                    $unidad = $existe ?? new Unidad(['codigo' => $codigo]);
                    if ($unidad->trashed()) {
                        $unidad->restore();
                    }
                    $unidad->fill(array_filter([
                        'item_id' => $item->id, 'sede_id' => $sedeId, 'sala_id' => $salaId, 'estado' => $estado,
                        'serie' => $this->texto($f['serie'] ?? null), 'marca' => $this->texto($f['marca'] ?? null), 'modelo' => $this->texto($f['modelo'] ?? null),
                        'registro_invima' => $this->texto($f['registro_invima'] ?? null),
                        ...$fechas,
                    ], fn ($v) => $v !== null) + ['sala_id' => $salaId])->save();
                });
            } catch (Throwable $e) {
                $resultado['errores'][] = ['fila' => $numero, 'documento' => $codigo, 'mensajes' => ['No se pudo guardar: '.$e->getMessage()]];
            }
        }

        return $this->cerrar($resultado);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    public function existencias(array $filas, bool $simular, string $origen = 'API', ?int $usuarioId = null): array
    {
        $resultado = $this->vacio(count($filas), $simular);

        foreach ($filas as $numero => $f) {
            $mensajes = [];
            $codigo = trim((string) ($f['item_codigo'] ?? ''));
            $sedeId = $this->sede($f['sede'] ?? $f['sede_id'] ?? null);
            $lote = Str::limit(trim((string) ($f['lote'] ?? '')), 60, '');
            $cantidad = $f['cantidad'] ?? null;

            if ($codigo === '') {
                $mensajes[] = 'Falta el código del insumo (item_codigo).';
            }
            if (! $sedeId) {
                $mensajes[] = 'La sede no existe.';
            }
            if (! is_numeric($cantidad) || (int) $cantidad < 0 || (int) $cantidad != $cantidad) {
                $mensajes[] = 'La cantidad debe ser un número entero mayor o igual a cero.';
            }
            $vence = $this->fecha($f['vence'] ?? null, 'vence', $mensajes);
            $item = $codigo !== '' ? Item::where('codigo', $codigo)->first() : null;
            if ($item && $item->tipo !== 'INSUMO') {
                $mensajes[] = "El ítem {$codigo} no es un insumo.";
            }
            if (! $item && empty($f['item_nombre'])) {
                $mensajes[] = 'El insumo no existe: envía item_nombre para crearlo.';
            }

            if ($mensajes) {
                $resultado['errores'][] = ['fila' => $numero, 'documento' => trim("{$codigo} {$lote}"), 'mensajes' => $mensajes];

                continue;
            }

            $existe = $item ? Existencia::where('item_id', $item->id)->where('sede_id', $sedeId)->where('lote', $lote)->exists() : false;
            $resultado[$existe ? 'actualizados' : 'creados']++;
            if ($simular) {
                continue;
            }

            DB::transaction(function () use ($item, $f, $codigo, $sedeId, $lote, $cantidad, $vence, $origen, $usuarioId) {
                $item ??= Item::create(['tipo' => 'INSUMO', 'codigo' => $codigo, 'nombre' => trim((string) $f['item_nombre']), 'unidad_medida' => $this->texto($f['unidad_medida'] ?? null), 'activo' => true]);
                $existencia = Existencia::lockForUpdate()->firstOrNew(['item_id' => $item->id, 'sede_id' => $sedeId, 'lote' => $lote]);
                $anterior = (int) $existencia->cantidad;
                $existencia->fill(['cantidad' => (int) $cantidad] + ($vence ? ['vence' => $vence] : []))->save();
                if ($anterior !== (int) $cantidad) {
                    Movimiento::create([
                        'item_id' => $item->id, 'sede_id' => $sedeId, 'existencia_id' => $existencia->id, 'tipo' => 'SINCRONIZACION',
                        'cantidad' => (int) $cantidad - $anterior, 'saldo' => (int) $cantidad, 'motivo' => 'Conteo recibido', 'origen' => $origen, 'usuario_id' => $usuarioId,
                    ]);
                }
            });
        }

        return $this->cerrar($resultado);
    }

    private function sede(mixed $valor): ?int
    {
        $clave = Str::lower(trim((string) $valor));
        if ($clave === '') {
            return null;
        }

        return $this->sedes[$clave] ??= ctype_digit($clave)
            ? Sede::whereKey((int) $clave)->value('id')
            : Sede::whereRaw('LOWER(nombre) = ?', [$clave])->value('id');
    }

    private function fecha(mixed $valor, string $campo, array &$mensajes): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y'] as $formato) {
            try {
                $f = Carbon::createFromFormat("!{$formato}", $valor);
                if ($f && $f->format($formato) === $valor) {
                    return $f->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }
        $mensajes[] = "La fecha de {$campo} no es válida (AAAA-MM-DD o DD/MM/AAAA).";

        return null;
    }

    private function texto(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : Str::limit($v, 80, '');
    }

    private function vacio(int $total, bool $simular): array
    {
        return ['total' => $total, 'creados' => 0, 'actualizados' => 0, 'errores' => [], 'simulado' => $simular];
    }

    private function cerrar(array $resultado): array
    {
        $resultado['con_errores'] = count($resultado['errores']);

        return $resultado;
    }
}
