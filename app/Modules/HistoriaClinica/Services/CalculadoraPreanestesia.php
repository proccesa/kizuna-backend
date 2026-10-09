<?php

namespace App\Modules\HistoriaClinica\Services;

use App\Modules\Cirugia\Services\ReglasPreanestesia;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Escalas y alertas de la valoración pre-anestésica. Todo es sugerencia: el anestesiólogo decide.
 *
 * - IMC.
 * - RCRI / índice de Lee (riesgo cardiaco), con los riesgos actualizados de Duceppe et al., 2017.
 * - STOP-Bang (apnea obstructiva del sueño).
 * - Apfel (náusea y vómito postoperatorio).
 * - Vía aérea difícil predicha.
 * - ASA sugerido según los antecedentes.
 * - Medicamentos que requieren suspensión (orientativo, cada IPS debe validarlo con su protocolo).
 */
class CalculadoraPreanestesia
{
    /** Riesgo de evento cardiaco mayor a 30 días según los puntos RCRI. */
    private const RIESGO_RCRI = [0 => '3,9 %', 1 => '6,0 %', 2 => '10,1 %', 3 => '15 %'];

    /** Riesgo de náusea y vómito postoperatorio según los puntos Apfel. */
    private const RIESGO_APFEL = [0 => '10 %', 1 => '21 %', 2 => '39 %', 3 => '61 %', 4 => '79 %'];

    /**
     * Medicamentos que se suspenden antes de cirugía. Los días son orientativos.
     *
     * @var list<array{patrones: list<string>, grupo: string, dias: int, nota: string}>
     */
    private const MEDICAMENTOS = [
        ['patrones' => ['warfarina', 'coumadin'], 'grupo' => 'Anticoagulante (AVK)', 'dias' => 5, 'nota' => 'Suspender 5 días antes y verificar INR < 1,5. Valorar terapia puente.'],
        ['patrones' => ['acenocumarol', 'sintrom'], 'grupo' => 'Anticoagulante (AVK)', 'dias' => 3, 'nota' => 'Suspender 3 días antes y verificar INR < 1,5.'],
        ['patrones' => ['rivaroxaban', 'xarelto'], 'grupo' => 'Anticoagulante oral directo', 'dias' => 2, 'nota' => 'Suspender 48 horas antes (72 horas si el riesgo de sangrado es alto).'],
        ['patrones' => ['apixaban', 'eliquis'], 'grupo' => 'Anticoagulante oral directo', 'dias' => 2, 'nota' => 'Suspender 48 horas antes (72 horas si el riesgo de sangrado es alto).'],
        ['patrones' => ['edoxaban'], 'grupo' => 'Anticoagulante oral directo', 'dias' => 2, 'nota' => 'Suspender 48 horas antes.'],
        ['patrones' => ['dabigatran', 'pradaxa'], 'grupo' => 'Anticoagulante oral directo', 'dias' => 3, 'nota' => 'Suspender 2 a 4 días antes según la función renal.'],
        ['patrones' => ['clopidogrel', 'plavix'], 'grupo' => 'Antiagregante', 'dias' => 5, 'nota' => 'Suspender 5 días antes, salvo stent reciente (consultar a cardiología).'],
        ['patrones' => ['ticagrelor', 'brilinta'], 'grupo' => 'Antiagregante', 'dias' => 5, 'nota' => 'Suspender 3 a 5 días antes, salvo stent reciente.'],
        ['patrones' => ['prasugrel'], 'grupo' => 'Antiagregante', 'dias' => 7, 'nota' => 'Suspender 7 días antes, salvo stent reciente.'],
        ['patrones' => ['enoxaparina', 'clexane', 'dalteparina', 'nadroparina'], 'grupo' => 'Heparina de bajo peso molecular', 'dias' => 1, 'nota' => 'Última dosis terapéutica 24 horas antes (profiláctica 12 horas).'],
        ['patrones' => ['empagliflozina', 'dapagliflozina', 'canagliflozina', 'jardiance', 'forxiga'], 'grupo' => 'iSGLT2', 'dias' => 3, 'nota' => 'Suspender 3 días antes por riesgo de cetoacidosis euglucémica.'],
        ['patrones' => ['ertugliflozina'], 'grupo' => 'iSGLT2', 'dias' => 4, 'nota' => 'Suspender 4 días antes.'],
        ['patrones' => ['metformina'], 'grupo' => 'Hipoglucemiante', 'dias' => 0, 'nota' => 'No tomar el día de la cirugía.'],
        ['patrones' => ['insulina', 'glargina', 'degludec', 'detemir', 'nph'], 'grupo' => 'Insulina', 'dias' => 0, 'nota' => 'Ajustar la dosis basal la noche previa (usualmente 75–80 %).'],
        ['patrones' => ['enalapril', 'captopril', 'lisinopril', 'ramipril', 'losartan', 'valsartan', 'irbesartan', 'telmisartan', 'candesartan'], 'grupo' => 'IECA / ARA II', 'dias' => 1, 'nota' => 'Considerar omitir 24 horas antes por riesgo de hipotensión.'],
        ['patrones' => ['aspirina', 'asa ', 'acido acetilsalicilico'], 'grupo' => 'Antiagregante', 'dias' => 0, 'nota' => 'Usualmente se continúa en prevención secundaria; suspender 7 días antes solo si el riesgo de sangrado lo justifica.'],
    ];

    public function __construct(
        private readonly ReglasPreanestesia $reglas
    ) {}

    /**
     * @param  array{paciente: array{sexo: ?string, edad: ?int}}  $contexto
     * @return array<string, mixed>
     */
    public function calcular(array $r, array $contexto): array
    {
        $si = fn (string $c) => ($r[$c] ?? null) === true;
        $edad = $contexto['paciente']['edad'] ?? null;
        $sexo = $contexto['paciente']['sexo'] ?? null;
        $alertas = [];

        // IMC
        $imc = null;
        if (! empty($r['peso']) && ! empty($r['talla'])) {
            $valor = round($r['peso'] / (($r['talla'] / 100) ** 2), 1);
            $imc = ['valor' => $valor, 'categoria' => match (true) {
                $valor < 18.5 => 'Bajo peso', $valor < 25 => 'Normal', $valor < 30 => 'Sobrepeso', $valor < 35 => 'Obesidad I', $valor < 40 => 'Obesidad II', default => 'Obesidad III',
            }];
        }

        // RCRI
        $factoresRcri = array_keys(array_filter([
            'Cirugía de alto riesgo' => ($r['riesgo_quirurgico'] ?? null) === 'ALTO',
            'Cardiopatía isquémica' => $si('cardiopatia_isquemica'),
            'Insuficiencia cardiaca' => $si('insuficiencia_cardiaca'),
            'ACV o AIT' => $si('acv'),
            'Diabetes con insulina' => $si('diabetes') && $si('diabetes_insulina'),
            'Creatinina > 2 mg/dL' => ($r['creatinina'] ?? 0) > 2,
        ]));
        $rcri = ['puntos' => count($factoresRcri), 'factores' => $factoresRcri, 'riesgo' => self::RIESGO_RCRI[min(3, count($factoresRcri))]];

        // STOP-Bang (solo si hay datos suficientes)
        $stopBang = null;
        $preguntasSb = [$r['ronquido'] ?? null, $r['cansancio_diurno'] ?? null, $r['apneas_observadas'] ?? null];
        if (! in_array(null, $preguntasSb, true)) {
            $puntos = count(array_filter([
                $si('ronquido'), $si('cansancio_diurno'), $si('apneas_observadas'), $si('hta'),
                ($imc['valor'] ?? 0) > 35, ($edad ?? 0) > 50, ($r['perimetro_cuello'] ?? 0) > 40, $sexo === 'M',
            ]));
            $stopBang = ['puntos' => $puntos, 'riesgo' => $puntos >= 5 ? 'ALTO' : ($puntos >= 3 ? 'INTERMEDIO' : 'BAJO')];
        }

        // Apfel
        $apfelPuntos = count(array_filter([$sexo === 'F', ($r['fumador'] ?? null) === false, $si('nvpo_previa'), $si('opioides_postoperatorios')]));
        $apfel = ['puntos' => $apfelPuntos, 'riesgo' => self::RIESGO_APFEL[$apfelPuntos]];

        // Vía aérea
        $predictores = array_keys(array_filter([
            'Mallampati III–IV' => in_array($r['mallampati'] ?? null, ['3', '4'], true),
            'Apertura oral < 3 cm' => isset($r['apertura_oral']) && $r['apertura_oral'] < 3,
            'Distancia tiromentoniana < 6 cm' => isset($r['distancia_tiromentoniana']) && $r['distancia_tiromentoniana'] < 6,
            'Movilidad cervical limitada' => ($r['movilidad_cervical'] ?? null) === 'LIMITADA',
            'Antecedente de vía aérea difícil' => $si('via_aerea_dificil_previa'),
            'STOP-Bang alto' => ($stopBang['riesgo'] ?? null) === 'ALTO',
        ]));
        $viaAerea = ['dificil_predicha' => count($predictores) >= 2 || $si('via_aerea_dificil_previa'), 'predictores' => $predictores];
        if ($viaAerea['dificil_predicha']) {
            $alertas[] = $this->alerta('aviso', 'Vía aérea difícil predicha: '.implode(', ', $predictores).'. Prever dispositivos de rescate.');
        }

        // ASA sugerido
        $asa = $this->asaSugerido($r, $imc['valor'] ?? null);

        // Medicamentos
        $medicamentos = $this->medicamentos($r['medicamentos'] ?? []);
        foreach ($medicamentos['hallazgos'] as $m) {
            $alertas[] = $this->alerta($m['dias'] > 0 ? 'aviso' : 'info', "{$m['medicamento']} ({$m['grupo']}): {$m['nota']}");
        }
        $anticoagulado = collect($medicamentos['hallazgos'])->contains(fn ($m) => str_contains($m['grupo'], 'AVK'));
        $paraclinicos = collect($r['paraclinicos'] ?? []);
        if ($anticoagulado && ! $paraclinicos->contains(fn ($p) => ($p['examen'] ?? null) === 'TP_INR')) {
            $alertas[] = $this->alerta('aviso', 'Toma anticoagulante cumarínico y no hay TP/INR registrado.');
        }

        // Paraclínicos de más de 6 meses
        foreach ($paraclinicos as $p) {
            if (! empty($p['fecha']) && Carbon::parse($p['fecha'])->lt(now()->subMonths(6))) {
                $alertas[] = $this->alerta('aviso', 'Paraclínico de hace más de 6 meses: '.Str::of($p['examen'] ?? 'examen')->replace('_', ' ')->lower()->ucfirst().' del '.Carbon::parse($p['fecha'])->format('d/m/Y').'.');
            }
        }

        // Signos vitales y condiciones que suelen aplazar una cirugía electiva
        if (($r['pa_sistolica'] ?? 0) >= 180 || ($r['pa_diastolica'] ?? 0) >= 110) {
            $alertas[] = $this->alerta('bloqueo', 'Presión arterial ≥ 180/110 mmHg: considerar aplazar la cirugía electiva hasta controlarla.');
        }
        if (isset($r['spo2']) && $r['spo2'] < 92) {
            $alertas[] = $this->alerta('aviso', "Saturación de {$r['spo2']} %: evaluar causa antes de la cirugía.");
        }
        if ($si('iam_reciente')) {
            $alertas[] = $this->alerta('bloqueo', 'Evento coronario en los últimos 3 meses: la cirugía electiva usualmente se aplaza.');
        }
        if ($si('diabetes') && ($r['diabetes_controlada'] ?? null) === false) {
            $alertas[] = $this->alerta('aviso', 'Diabetes no controlada: valorar optimización antes de la cirugía.');
        }
        if (($imc['valor'] ?? 0) >= 40) {
            $alertas[] = $this->alerta('aviso', 'IMC ≥ 40: obesidad mórbida.');
        }
        if ($rcri['puntos'] >= 3) {
            $alertas[] = $this->alerta('aviso', "RCRI de {$rcri['puntos']} puntos: riesgo cardiaco alto ({$rcri['riesgo']}). Considerar valoración por cardiología.");
        }
        if (isset($r['asa']) && $asa['clase'] !== null && (int) $r['asa'] !== $asa['clase']) {
            $alertas[] = $this->alerta('info', 'El ASA elegido ('.$this->romano((int) $r['asa']).') difiere del sugerido ('.$this->romano($asa['clase']).').');
        }

        $asaFinal = isset($r['asa']) ? (int) $r['asa'] : $asa['clase'];
        $vigencia = $this->reglas->vigenciaDias($asaFinal);

        return [
            'imc' => $imc,
            'rcri' => $rcri,
            'stop_bang' => $stopBang,
            'apfel' => $apfel,
            'via_aerea' => $viaAerea,
            'asa_sugerido' => $asa,
            'medicamentos' => ['dias_suspension' => $medicamentos['dias'], 'hallazgos' => $medicamentos['hallazgos']],
            'alertas' => $alertas,
            'concepto' => $r['concepto'] ?? null,
            'asa' => $asaFinal,
            'vigencia_dias' => $vigencia,
        ];
    }

    /**
     * @return array{clase: ?int, razones: list<string>}
     */
    private function asaSugerido(array $r, ?float $imc): array
    {
        $si = fn (string $c) => ($r[$c] ?? null) === true;

        $iv = array_keys(array_filter([
            'Evento coronario reciente' => $si('iam_reciente'),
        ]));
        if ($iv) {
            return ['clase' => 4, 'razones' => $iv];
        }

        $iii = array_keys(array_filter([
            'Diabetes no controlada' => $si('diabetes') && ($r['diabetes_controlada'] ?? null) === false,
            'Hipertensión no controlada' => $si('hta') && ($r['hta_controlada'] ?? null) === false,
            'EPOC o asma' => $si('epoc_asma'),
            'IMC ≥ 40' => ($imc ?? 0) >= 40,
            'Cardiopatía isquémica' => $si('cardiopatia_isquemica'),
            'Insuficiencia cardiaca' => $si('insuficiencia_cardiaca'),
            'ACV o AIT' => $si('acv'),
            'Enfermedad renal crónica' => $si('erc'),
            'Hepatopatía' => $si('hepatopatia'),
        ]));
        if ($iii) {
            return ['clase' => 3, 'razones' => $iii];
        }

        $ii = array_keys(array_filter([
            'Hipertensión controlada' => $si('hta'),
            'Diabetes controlada' => $si('diabetes'),
            'Fumador' => $si('fumador'),
            'Obesidad (IMC 30–40)' => ($imc ?? 0) >= 30,
            'Embarazo' => $si('embarazo'),
            'Apnea del sueño' => $si('apnea_sueno'),
        ]));
        if ($ii) {
            return ['clase' => 2, 'razones' => $ii];
        }

        $hayDatos = array_intersect_key($r, array_flip(['hta', 'diabetes', 'cardiopatia_isquemica', 'epoc_asma']));

        return ['clase' => $hayDatos ? 1 : null, 'razones' => $hayDatos ? ['Sin enfermedad sistémica registrada'] : []];
    }

    /**
     * @return array{dias: int, hallazgos: list<array{medicamento: string, grupo: string, dias: int, nota: string}>}
     */
    private function medicamentos(array $lista): array
    {
        $hallazgos = [];
        foreach ($lista as $m) {
            $nombre = trim((string) ($m['nombre'] ?? ''));
            $normalizado = ' '.Str::lower(Str::ascii($nombre)).' ';
            foreach (self::MEDICAMENTOS as $regla) {
                foreach ($regla['patrones'] as $patron) {
                    if (str_contains($normalizado, $patron)) {
                        $hallazgos[] = ['medicamento' => $nombre, 'grupo' => $regla['grupo'], 'dias' => $regla['dias'], 'nota' => $regla['nota']];

                        continue 3;
                    }
                }
            }
        }

        return ['dias' => (int) max([0, ...array_column($hallazgos, 'dias')]), 'hallazgos' => $hallazgos];
    }

    private function alerta(string $nivel, string $mensaje): array
    {
        return ['nivel' => $nivel, 'mensaje' => $mensaje];
    }

    private function romano(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI'][$n] ?? (string) $n;
    }
}
