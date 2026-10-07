<?php

namespace App\Modules\Red\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida el dígito de verificación de un NIT con el algoritmo de la DIAN
 * (módulo 11 con los pesos 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71).
 */
class DigitoVerificacionNit implements ValidationRule
{
    private const PESOS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    public function __construct(
        private readonly ?string $nit
    ) {}

    public static function calcular(string $nit): int
    {
        $digitos = array_reverse(str_split($nit));
        $suma = 0;
        foreach ($digitos as $i => $digito) {
            $suma += (int) $digito * self::PESOS[$i];
        }
        $residuo = $suma % 11;

        return $residuo > 1 ? 11 - $residuo : $residuo;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->nit || ! preg_match('/^\d{6,15}$/', $this->nit)) {
            return; // El formato del NIT lo valida su propia regla.
        }

        if ((string) self::calcular($this->nit) !== (string) $value) {
            $fail('El dígito de verificación no corresponde al NIT.');
        }
    }
}
