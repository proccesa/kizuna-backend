<?php

namespace App\Modules\HistoriaClinica\Services;

/**
 * Interpreta el esquema de una plantilla: qué campos aplican, cómo se limpian y qué falta.
 * Es genérico: sirve para cualquier plantilla de cualquier especialidad.
 */
class EvaluadorPlantilla
{
    /**
     * @return list<array<string, mixed>>
     */
    public function campos(array $esquema): array
    {
        return array_merge(...array_map(fn ($s) => $s['campos'] ?? [], $esquema['secciones'] ?? []));
    }

    public function visible(array $campo, array $respuestas, array $contexto): bool
    {
        $condicion = $campo['visible_si'] ?? null;
        if (! $condicion) {
            return true;
        }

        $clave = $condicion['campo'];
        $valor = str_contains($clave, '.') ? data_get($contexto, $clave) : ($respuestas[$clave] ?? null);

        if (array_key_exists('igual', $condicion)) {
            return $valor === $condicion['igual'];
        }
        if (array_key_exists('en', $condicion)) {
            return in_array($valor, $condicion['en'], true);
        }

        return ! empty($valor);
    }

    /**
     * Aplica `valor_inicial` a los campos visibles que nunca se han diligenciado
     * (p. ej. las indicaciones de ayuno, que aparecen al elegir el concepto).
     *
     * @return array<string, mixed>
     */
    public function conValoresIniciales(array $esquema, array $respuestas, array $contexto): array
    {
        foreach ($this->campos($esquema) as $campo) {
            if (array_key_exists('valor_inicial', $campo) && ! array_key_exists($campo['id'], $respuestas) && $this->visible($campo, $respuestas, $contexto)) {
                $respuestas[$campo['id']] = $campo['valor_inicial'];
            }
        }

        return $respuestas;
    }

    /**
     * Normaliza tipos y descarta campos ocultos o desconocidos.
     *
     * @return array<string, mixed>
     */
    public function limpiar(array $esquema, array $respuestas, array $contexto): array
    {
        $limpias = [];
        foreach ($this->campos($esquema) as $campo) {
            if ($campo['tipo'] === 'calculado' || ! $this->visible($campo, $respuestas, $contexto)) {
                continue;
            }
            if (! array_key_exists($campo['id'], $respuestas)) {
                continue;
            }
            $valor = $this->normalizar($campo, $respuestas[$campo['id']]);
            if ($valor !== null) {
                $limpias[$campo['id']] = $valor;
            }
        }

        return $limpias;
    }

    /**
     * Errores por campo. Con `$completa = false` solo valida formato (borrador);
     * con `true` exige además los obligatorios (finalizar).
     *
     * @return array<string, list<string>>
     */
    public function validar(array $esquema, array $respuestas, array $contexto, bool $completa): array
    {
        $errores = [];
        foreach ($this->campos($esquema) as $campo) {
            if ($campo['tipo'] === 'calculado' || ! $this->visible($campo, $respuestas, $contexto)) {
                continue;
            }
            foreach ($this->erroresCampo($campo, $respuestas[$campo['id']] ?? null, $completa) as $clave => $mensaje) {
                $errores[$clave === '' ? $campo['id'] : "{$campo['id']}.{$clave}"][] = $mensaje;
            }
        }

        return $errores;
    }

    /**
     * @return array<string, string> clave relativa ('' = el campo) => mensaje
     */
    private function erroresCampo(array $campo, mixed $valor, bool $completa): array
    {
        $vacio = $valor === null || $valor === '' || $valor === [];
        if ($vacio) {
            return $completa && ! empty($campo['requerido']) ? ['' => "Completa «{$campo['etiqueta']}»."] : [];
        }

        $etiqueta = $campo['etiqueta'];

        return match ($campo['tipo']) {
            'numero' => match (true) {
                ! is_numeric($valor) => ['' => "«{$etiqueta}» debe ser un número."],
                isset($campo['min']) && $valor < $campo['min'], isset($campo['max']) && $valor > $campo['max'] => ['' => "«{$etiqueta}» debe estar entre {$campo['min']} y {$campo['max']}".(isset($campo['unidad']) ? " {$campo['unidad']}" : '').'.'],
                default => [],
            },
            'booleano' => is_bool($valor) ? [] : ['' => "«{$etiqueta}» debe ser sí o no."],
            'seleccion' => in_array((string) $valor, array_column($campo['opciones'] ?? [], 'valor'), true) ? [] : ['' => "Elige una opción válida en «{$etiqueta}»."],
            'seleccion_multiple' => is_array($valor) && ! array_diff($valor, array_column($campo['opciones'] ?? [], 'valor')) ? [] : ['' => "Elige opciones válidas en «{$etiqueta}»."],
            'fecha' => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? [] : ['' => "«{$etiqueta}» debe ser una fecha AAAA-MM-DD."],
            'texto', 'texto_largo' => is_string($valor) && mb_strlen($valor) <= ($campo['tipo'] === 'texto' ? 255 : 5000) ? [] : ['' => "«{$etiqueta}» es demasiado largo."],
            'lista' => $this->erroresLista($campo, $valor, $completa),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function erroresLista(array $campo, mixed $valor, bool $completa): array
    {
        if (! is_array($valor) || ! array_is_list($valor)) {
            return ['' => "«{$campo['etiqueta']}» debe ser una lista."];
        }

        $errores = [];
        foreach ($valor as $i => $fila) {
            // Las filas vacías se descartan al guardar: no se validan.
            if (! is_array($fila) || ! array_filter($fila, fn ($v) => $v !== '' && $v !== null)) {
                continue;
            }
            foreach ($campo['campos'] ?? [] as $sub) {
                foreach ($this->erroresCampo($sub, $fila[$sub['id']] ?? null, true) as $mensaje) {
                    $errores["{$i}.{$sub['id']}"] = $mensaje.' (fila '.($i + 1).')';
                }
            }
        }

        return $completa ? $errores : array_filter($errores, fn ($m) => ! str_starts_with($m, 'Completa'));
    }

    private function normalizar(array $campo, mixed $valor): mixed
    {
        if ($valor === '' || $valor === []) {
            return null;
        }

        return match ($campo['tipo']) {
            'numero' => is_numeric($valor) ? $valor + 0 : $valor,
            'texto', 'texto_largo' => is_string($valor) ? trim($valor) : $valor,
            'seleccion' => is_scalar($valor) ? (string) $valor : $valor,
            'lista' => is_array($valor) ? array_values(array_map(
                fn ($fila) => is_array($fila) ? array_filter(array_intersect_key($fila, array_flip(array_column($campo['campos'] ?? [], 'id'))), fn ($v) => $v !== '' && $v !== null) : $fila,
                array_filter($valor, fn ($fila) => is_array($fila) && array_filter($fila, fn ($v) => $v !== '' && $v !== null))
            )) : $valor,
            default => $valor,
        };
    }
}
