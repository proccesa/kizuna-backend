<?php

/**
 * Plantilla de valoración pre-anestésica (versión 1).
 *
 * Tipos de campo: texto, texto_largo, numero, booleano, seleccion, seleccion_multiple, fecha, lista.
 * `visible_si`: el campo solo aplica si se cumple la condición sobre otra respuesta o sobre el
 * contexto (`paciente.sexo`, `paciente.edad`). Un campo oculto no se valida ni se guarda.
 * `calculado`: lo llena Kizuna a partir del resultado (escalas), no el profesional.
 */
$siNo = fn (string $id, string $etiqueta, array $extra = []) => ['id' => $id, 'tipo' => 'booleano', 'etiqueta' => $etiqueta] + $extra;

return [
    'codigo' => 'preanestesia',
    'nombre' => 'Valoración pre-anestésica',
    'descripcion' => 'Consulta de anestesiología previa a una cirugía programada. Su concepto define si el paciente puede programarse y hasta cuándo.',
    'especialidad_codigo' => 'anestesiologia',
    'esquema' => [
        'secciones' => [
            [
                'id' => 'procedimiento',
                'titulo' => 'Procedimiento',
                'campos' => [
                    ['id' => 'riesgo_quirurgico', 'tipo' => 'seleccion', 'etiqueta' => 'Riesgo del procedimiento', 'requerido' => true, 'opciones' => [
                        ['valor' => 'BAJO', 'etiqueta' => 'Bajo (superficial, endoscópico, oftalmológico)'],
                        ['valor' => 'INTERMEDIO', 'etiqueta' => 'Intermedio (ortopédico, cabeza y cuello, próstata)'],
                        ['valor' => 'ALTO', 'etiqueta' => 'Alto (intraperitoneal, intratorácico, vascular suprainguinal)'],
                    ]],
                    ['id' => 'anestesia_propuesta', 'tipo' => 'seleccion', 'etiqueta' => 'Anestesia propuesta', 'requerido' => true, 'opciones' => [
                        ['valor' => 'GENERAL', 'etiqueta' => 'General'],
                        ['valor' => 'REGIONAL', 'etiqueta' => 'Regional (raquídea, peridural, bloqueo)'],
                        ['valor' => 'SEDACION', 'etiqueta' => 'Sedación'],
                        ['valor' => 'LOCAL_ASISTIDA', 'etiqueta' => 'Local asistida'],
                        ['valor' => 'COMBINADA', 'etiqueta' => 'Combinada'],
                    ]],
                    $siNo('opioides_postoperatorios', 'Requerirá opioides en el postoperatorio'),
                ],
            ],
            [
                'id' => 'antecedentes',
                'titulo' => 'Antecedentes',
                'campos' => [
                    $siNo('hta', 'Hipertensión arterial'),
                    $siNo('hta_controlada', 'Controlada con tratamiento', ['visible_si' => ['campo' => 'hta', 'igual' => true]]),
                    $siNo('diabetes', 'Diabetes mellitus'),
                    $siNo('diabetes_insulina', 'Usa insulina', ['visible_si' => ['campo' => 'diabetes', 'igual' => true]]),
                    $siNo('diabetes_controlada', 'Controlada (HbA1c < 8 %)', ['visible_si' => ['campo' => 'diabetes', 'igual' => true]]),
                    $siNo('cardiopatia_isquemica', 'Cardiopatía isquémica (infarto, angina, stent)'),
                    $siNo('iam_reciente', 'Infarto o evento coronario en los últimos 3 meses', ['visible_si' => ['campo' => 'cardiopatia_isquemica', 'igual' => true]]),
                    $siNo('insuficiencia_cardiaca', 'Insuficiencia cardiaca'),
                    $siNo('acv', 'ACV o accidente isquémico transitorio'),
                    $siNo('epoc_asma', 'EPOC o asma'),
                    $siNo('apnea_sueno', 'Apnea del sueño diagnosticada'),
                    $siNo('erc', 'Enfermedad renal crónica'),
                    ['id' => 'creatinina', 'tipo' => 'numero', 'etiqueta' => 'Creatinina', 'unidad' => 'mg/dL', 'min' => 0.1, 'max' => 20, 'decimales' => 2],
                    $siNo('hepatopatia', 'Hepatopatía'),
                    $siNo('embarazo', 'Embarazo actual', ['visible_si' => ['campo' => 'paciente.sexo', 'igual' => 'F']]),
                    $siNo('fumador', 'Fumador actual'),
                    $siNo('nvpo_previa', 'Náusea o vómito postoperatorio previo, o mareo con el movimiento'),
                    $siNo('complicaciones_anestesicas', 'Complicaciones anestésicas previas (propias o familiares)'),
                    ['id' => 'complicaciones_detalle', 'tipo' => 'texto_largo', 'etiqueta' => '¿Cuáles?', 'requerido' => true, 'visible_si' => ['campo' => 'complicaciones_anestesicas', 'igual' => true]],
                    ['id' => 'antecedentes_quirurgicos', 'tipo' => 'texto_largo', 'etiqueta' => 'Antecedentes quirúrgicos'],
                    ['id' => 'otros_antecedentes', 'tipo' => 'texto_largo', 'etiqueta' => 'Otros antecedentes'],
                ],
            ],
            [
                'id' => 'medicamentos',
                'titulo' => 'Alergias y medicamentos',
                'campos' => [
                    $siNo('alergias', 'Alergias conocidas', ['requerido' => true]),
                    ['id' => 'alergias_detalle', 'tipo' => 'texto', 'etiqueta' => '¿A qué?', 'requerido' => true, 'visible_si' => ['campo' => 'alergias', 'igual' => true]],
                    ['id' => 'medicamentos', 'tipo' => 'lista', 'etiqueta' => 'Medicamentos actuales', 'ayuda' => 'Kizuna detecta anticoagulantes, antiagregantes y otros que requieren suspensión.', 'campos' => [
                        ['id' => 'nombre', 'tipo' => 'texto', 'etiqueta' => 'Medicamento', 'requerido' => true],
                        ['id' => 'dosis', 'tipo' => 'texto', 'etiqueta' => 'Dosis y frecuencia'],
                    ]],
                ],
            ],
            [
                'id' => 'examen',
                'titulo' => 'Examen físico',
                'campos' => [
                    ['id' => 'peso', 'tipo' => 'numero', 'etiqueta' => 'Peso', 'unidad' => 'kg', 'requerido' => true, 'min' => 2, 'max' => 350, 'decimales' => 1],
                    ['id' => 'talla', 'tipo' => 'numero', 'etiqueta' => 'Talla', 'unidad' => 'cm', 'requerido' => true, 'min' => 40, 'max' => 230],
                    ['id' => 'imc', 'tipo' => 'calculado', 'etiqueta' => 'IMC', 'unidad' => 'kg/m²', 'fuente' => 'imc.valor'],
                    ['id' => 'pa_sistolica', 'tipo' => 'numero', 'etiqueta' => 'Presión sistólica', 'unidad' => 'mmHg', 'requerido' => true, 'min' => 50, 'max' => 260],
                    ['id' => 'pa_diastolica', 'tipo' => 'numero', 'etiqueta' => 'Presión diastólica', 'unidad' => 'mmHg', 'requerido' => true, 'min' => 30, 'max' => 160],
                    ['id' => 'frecuencia_cardiaca', 'tipo' => 'numero', 'etiqueta' => 'Frecuencia cardiaca', 'unidad' => 'lpm', 'requerido' => true, 'min' => 20, 'max' => 220],
                    ['id' => 'spo2', 'tipo' => 'numero', 'etiqueta' => 'Saturación de oxígeno', 'unidad' => '%', 'requerido' => true, 'min' => 50, 'max' => 100],
                    ['id' => 'perimetro_cuello', 'tipo' => 'numero', 'etiqueta' => 'Perímetro de cuello', 'unidad' => 'cm', 'min' => 20, 'max' => 70],
                    $siNo('ronquido', 'Ronca fuerte'),
                    $siNo('cansancio_diurno', 'Cansancio o somnolencia diurna'),
                    $siNo('apneas_observadas', 'Alguien ha observado que deja de respirar al dormir'),
                ],
            ],
            [
                'id' => 'via_aerea',
                'titulo' => 'Vía aérea',
                'campos' => [
                    ['id' => 'mallampati', 'tipo' => 'seleccion', 'etiqueta' => 'Mallampati', 'requerido' => true, 'opciones' => [
                        ['valor' => '1', 'etiqueta' => 'I'], ['valor' => '2', 'etiqueta' => 'II'], ['valor' => '3', 'etiqueta' => 'III'], ['valor' => '4', 'etiqueta' => 'IV'],
                    ]],
                    ['id' => 'apertura_oral', 'tipo' => 'numero', 'etiqueta' => 'Apertura oral', 'unidad' => 'cm', 'min' => 0, 'max' => 8, 'decimales' => 1],
                    ['id' => 'distancia_tiromentoniana', 'tipo' => 'numero', 'etiqueta' => 'Distancia tiromentoniana', 'unidad' => 'cm', 'min' => 0, 'max' => 15, 'decimales' => 1],
                    ['id' => 'movilidad_cervical', 'tipo' => 'seleccion', 'etiqueta' => 'Movilidad cervical', 'opciones' => [
                        ['valor' => 'NORMAL', 'etiqueta' => 'Normal'], ['valor' => 'LIMITADA', 'etiqueta' => 'Limitada'],
                    ]],
                    $siNo('protesis_dental', 'Prótesis dental removible'),
                    $siNo('via_aerea_dificil_previa', 'Antecedente de vía aérea difícil'),
                ],
            ],
            [
                'id' => 'paraclinicos',
                'titulo' => 'Paraclínicos',
                'campos' => [
                    ['id' => 'paraclinicos', 'tipo' => 'lista', 'etiqueta' => 'Exámenes revisados', 'campos' => [
                        ['id' => 'examen', 'tipo' => 'seleccion', 'etiqueta' => 'Examen', 'requerido' => true, 'opciones' => [
                            ['valor' => 'HEMOGRAMA', 'etiqueta' => 'Hemograma'], ['valor' => 'GLICEMIA', 'etiqueta' => 'Glicemia'],
                            ['valor' => 'HBA1C', 'etiqueta' => 'Hemoglobina glicosilada'], ['valor' => 'CREATININA', 'etiqueta' => 'Creatinina'],
                            ['valor' => 'ELECTROLITOS', 'etiqueta' => 'Electrolitos'], ['valor' => 'TP_INR', 'etiqueta' => 'TP / INR'],
                            ['valor' => 'TPT', 'etiqueta' => 'TPT'], ['valor' => 'EKG', 'etiqueta' => 'Electrocardiograma'],
                            ['valor' => 'RX_TORAX', 'etiqueta' => 'Radiografía de tórax'], ['valor' => 'ECOCARDIOGRAMA', 'etiqueta' => 'Ecocardiograma'],
                            ['valor' => 'OTRO', 'etiqueta' => 'Otro'],
                        ]],
                        ['id' => 'fecha', 'tipo' => 'fecha', 'etiqueta' => 'Fecha', 'requerido' => true],
                        ['id' => 'resultado', 'tipo' => 'texto', 'etiqueta' => 'Resultado'],
                    ]],
                ],
            ],
            [
                'id' => 'concepto',
                'titulo' => 'Concepto',
                'campos' => [
                    ['id' => 'asa', 'tipo' => 'seleccion', 'etiqueta' => 'Clasificación ASA', 'requerido' => true, 'sugerencia' => 'asa_sugerido.clase', 'opciones' => [
                        ['valor' => '1', 'etiqueta' => 'ASA I · Sano'],
                        ['valor' => '2', 'etiqueta' => 'ASA II · Enfermedad sistémica leve'],
                        ['valor' => '3', 'etiqueta' => 'ASA III · Enfermedad sistémica grave'],
                        ['valor' => '4', 'etiqueta' => 'ASA IV · Amenaza constante para la vida'],
                        ['valor' => '5', 'etiqueta' => 'ASA V · Moribundo'],
                    ]],
                    ['id' => 'concepto', 'tipo' => 'seleccion', 'etiqueta' => 'Concepto', 'requerido' => true, 'opciones' => [
                        ['valor' => 'APTO', 'etiqueta' => 'Apto'],
                        ['valor' => 'APTO_CON_RECOMENDACIONES', 'etiqueta' => 'Apto con recomendaciones'],
                        ['valor' => 'APLAZADO', 'etiqueta' => 'Aplazado (requiere nueva valoración)'],
                        ['valor' => 'NO_APTO', 'etiqueta' => 'No apto'],
                    ]],
                    ['id' => 'recomendaciones', 'tipo' => 'texto_largo', 'etiqueta' => 'Recomendaciones', 'requerido' => true, 'visible_si' => ['campo' => 'concepto', 'en' => ['APTO_CON_RECOMENDACIONES']]],
                    ['id' => 'motivo', 'tipo' => 'texto_largo', 'etiqueta' => 'Motivo', 'requerido' => true, 'visible_si' => ['campo' => 'concepto', 'en' => ['APLAZADO', 'NO_APTO']]],
                    ['id' => 'ayuno', 'tipo' => 'texto', 'etiqueta' => 'Indicaciones de ayuno', 'valor_inicial' => 'Sólidos 8 horas, líquidos claros 2 horas', 'visible_si' => ['campo' => 'concepto', 'en' => ['APTO', 'APTO_CON_RECOMENDACIONES']]],
                    ['id' => 'suspension_medicamentos', 'tipo' => 'texto_largo', 'etiqueta' => 'Suspensión de medicamentos', 'ayuda' => 'Kizuna sugiere los días según los medicamentos registrados.', 'visible_si' => ['campo' => 'concepto', 'en' => ['APTO', 'APTO_CON_RECOMENDACIONES']]],
                    ['id' => 'dias_suspension', 'tipo' => 'numero', 'etiqueta' => 'Días antes de la cirugía para suspender medicamentos', 'unidad' => 'días', 'min' => 0, 'max' => 30, 'sugerencia' => 'medicamentos.dias_suspension', 'visible_si' => ['campo' => 'concepto', 'en' => ['APTO', 'APTO_CON_RECOMENDACIONES']]],
                    $siNo('consentimiento', 'Se explicaron riesgos y el paciente firmó el consentimiento anestésico', ['requerido' => true, 'visible_si' => ['campo' => 'concepto', 'en' => ['APTO', 'APTO_CON_RECOMENDACIONES']]]),
                ],
            ],
        ],
    ],
];
