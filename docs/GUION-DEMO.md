# Guion del video de demostración, paso a paso

Duración: 3–4 minutos. Usas **dos usuarios** y cambias de sesión **dos veces**:

| Orden | Usuario | Correo | Para qué |
| :--- | :--- | :--- | :--- |
| 1.º | Mónica Salazar, jefe de cirugía | `jefe.cirugia@kizuna.com` | Inicio, orden por API, inventario |
| 2.º | Paula Andrea Mejía, anestesióloga | `paula.mejia@kizuna.com` | Atender la cita y diligenciar la historia |
| 3.º | Mónica otra vez | `jefe.cirugia@kizuna.com` | Generar y aprobar la programación |

La contraseña de las dos cuentas es `Kizuna2026*`. Todos los pacientes, profesionales, EPS y la IPS son ficticios.

---

## Parte 0. Preparación (sin grabar)

1. En una terminal, dentro de `kizuna-backend`, ejecuta:

   ```bash
   php artisan kizuna:demo --force
   ```

   Hazlo **el mismo día de la grabación**: la cita que atiendes en vivo se crea para la fecha en que lo corres.
   Al final, el comando imprime una línea `Token de integración (HIS Vida Plena): 16|abc...`. **Copia ese token.**

2. Deja corriendo el backend (`php artisan serve`) y el frontend (`npm run dev`).

3. Prepara en la terminal este comando (sin ejecutarlo). Reemplaza `TOKEN` por el que copiaste:

   ```bash
   curl -X POST http://localhost:8000/api/v1/integracion/ordenes -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" -d '{"referencia_externa":"OQ-30001","paciente":{"tipo_documento":"CC","numero_documento":"29876543","primer_nombre":"Teresa","primer_apellido":"Bermúdez","segundo_apellido":"Lozano","fecha_nacimiento":"1961-03-15","sexo":"F"},"cups":"512104","diagnostico_cie10":"K802","prioridad":"ELECTIVA"}'
   ```

4. Abre el navegador en `http://localhost:5173`, a pantalla completa. Cierra cualquier sesión abierta (ícono de salida,
   abajo a la izquierda, junto a tu nombre).

5. **No generes la programación antes de grabar.** Si ensayas, vuelve al paso 1 antes de la toma final (el token cambia).

---

## Parte 1. Entras como Mónica (jefe de cirugía)

### Paso 1. Inicio (0:00–0:20)

1. Inicia sesión con `jefe.cirugia@kizuna.com` / `Kizuna2026*`.
2. Quédate en **Inicio**. Señala con el mouse:
   - "Aptos por programar: 10".
   - "1 con el aval por vencer".

> **Narración:** "Programar cirugías en una IPS implica cruzar órdenes, valoraciones de anestesia, agendas, quirófanos,
> equipos y contratos. Hoy eso se hace en hojas de cálculo. Kizuna lo hace solo, y una persona aprueba."

### Paso 2. Llega una orden del sistema externo (0:20–0:50)

1. Cambia a la terminal y ejecuta el `curl` preparado. La respuesta dice `"Orden recibida."` y `"estado":"CITA_ASIGNADA"`.
2. Vuelve al navegador. Menú izquierdo: **Pre-anestesia → Órdenes quirúrgicas**.
3. Arriba aparece **Teresa Bermúdez Lozano**, estado *Cita asignada*. Haz clic en ella: el panel lateral muestra la
   línea de tiempo y la cita de pre-anestesia que Kizuna le dio. Cierra el panel.
4. Busca **Fernando Hernández Castaño** (estado *Rechazada*) y ábrelo: muestra el motivo.

> **Narración:** "La orden llega por API desde el sistema de historia clínica; también puede cargarse por CSV o a mano.
> Kizuna revisa que la IPS tenga la especialidad y le asigna sola la cita de pre-anestesia. Esta otra es de urología y
> la clínica no tiene urólogo: se rechaza con el motivo, sin que nadie tenga que revisarla."

### Paso 3. Inventario (0:50–1:20)

1. Menú: **Inventario → Requerimientos por CUPS**. Abre **512104 · colecistectomía**: torre de laparoscopia,
   máquina de anestesia, monitor, unidad electroquirúrgica, caja de laparoscopia, trocares, clips y bolsa extractora.
2. Menú: **Inventario → Biomédicos**. Señala:
   - **BIO-00125** (torre Stryker): mantenimiento preventivo vencido → solo aviso.
   - **BIO-00301** (máquina de anestesia, Sede Sur): calibración por vencer.

> **Narración:** "Cada procedimiento declara lo que necesita, con ajustes por sede. Si la calibración de un equipo vence,
> no se usa; si el mantenimiento preventivo está atrasado, Kizuna avisa pero no bloquea."

### Paso 4. Cerrar sesión

Abajo a la izquierda, junto al nombre de Mónica, haz clic en el ícono de **Cerrar sesión**.
(En el video puedes cortar este momento.)

---

## Parte 2. Entras como Paula (anestesióloga)

### Paso 5. Su agenda (1:20–1:35)

1. Inicia sesión con `paula.mejia@kizuna.com` / `Kizuna2026*`.
2. Menú: **Pre-anestesia → Agenda de pre-anestesia**. La agenda abre ya filtrada en sus citas.
3. En la cita de **hoy a las 16:00**, paciente **Álvaro Muñoz Hernández** (73 años), haz clic en **Atender**.

> **Narración:** "La anestesióloga ve solo su agenda y abre la historia clínica desde la cita. La plantilla es
> configurable por especialidad y queda versionada."

### Paso 6. Diligenciar la historia (1:35–2:30)

Llena el formulario en este orden. El panel **Asistente clínico**, a la derecha, se actualiza mientras escribes.

| Sección | Campo | Valor |
| :--- | :--- | :--- |
| Procedimiento | Riesgo del procedimiento | Intermedio |
| | Anestesia propuesta | General |
| Antecedentes | Hipertensión arterial | Sí → Controlada con tratamiento: Sí |
| | Diabetes mellitus | Sí → Usa insulina: No → Controlada: Sí |
| | Cardiopatía isquémica | Sí → Evento en los últimos 3 meses: **No** |
| | Ronca fuerte | Sí |
| | Cansancio o somnolencia diurna | Sí |
| | Alguien ha observado que deja de respirar | Sí |
| Alergias y medicamentos | Medicamentos actuales | Agregar: `Metformina 850 mg` y `Clopidogrel 75 mg` |
| Examen físico | Peso / Talla | 92 / 168 |
| | Presión sistólica / diastólica | 138 / 86 |
| | Frecuencia cardiaca / Saturación | 72 / 95 |
| Vía aérea | Mallampati | III |
| | Movilidad cervical | Limitada |

**Pausa aquí y muestra el Asistente.** Debe decir:
- ASA sugerido **III** (cardiopatía isquémica);
- STOP-Bang **alto**;
- **vía aérea difícil** predicha;
- RCRI 1 punto;
- **suspender clopidogrel 5 días antes**.

> **Narración:** "Mientras escribe, Kizuna calcula el IMC, el riesgo cardiaco, el riesgo de apnea, la vía aérea difícil
> y sugiere el ASA. También detecta qué medicamentos suspender y cuántos días antes."

Luego, en la sección **Concepto**:

| Campo | Valor |
| :--- | :--- |
| Clasificación ASA | ASA III (viene sugerido) |
| Concepto | Apto |
| Días antes de la cirugía para suspender medicamentos | 5 (viene sugerido) |
| Se explicaron riesgos y el paciente firmó el consentimiento anestésico | Sí |

Haz clic en **Finalizar historia** (debajo del Asistente) y, en el cuadro de confirmación, en **Finalizar**.

> **Narración:** "Al finalizar, la orden queda apta con un aval de 90 días, porque es ASA III, y no se puede operar
> antes de cumplir los 5 días sin clopidogrel."

### Paso 7. Cerrar sesión

Ícono de **Cerrar sesión**, abajo a la izquierda. (Puedes cortarlo en el video.)

---

## Parte 3. Vuelves a entrar como Mónica

### Paso 8. La cola de prioridad (2:30–2:50)

1. Inicia sesión con `jefe.cirugia@kizuna.com` / `Kizuna2026*`.
2. Menú: **Programación**. Haz clic en la pestaña **Cola de prioridad**.
3. Señala:
   - **Rosa Quintero** arriba: orden prioritaria.
   - **Jorge Murillo**: su aval vence en pocos días.
   - **Álvaro Muñoz**: el paciente que Paula acaba de valorar ya está en la cola.

> **Narración:** "Kizuna ordena a los pacientes aptos: primero lo urgente, luego los avales a punto de vencer, los días
> de espera y los grupos de especial protección."

### Paso 9. Generar la propuesta (2:50–3:25)

1. Botón verde **Generar propuesta** (arriba a la derecha). Deja las fechas como vienen y haz clic en **Generar propuesta**.
2. Aparece la tarjeta oscura **Propuesta pendiente de aprobación**, con las cirugías por día y quirófano. Señala:
   - **Kevin** (9 años) y **Luis** (diabético) a las **07:00**: niños y diabéticos van primero en la mañana.
   - **Rosa** el primer día hábil, antes que las electivas.
   - El **aviso naranja** de mantenimiento vencido en las cirugías del Quirófano 1 de Sede Norte.
   - En cualquier tarjeta, haz clic en **Prioridad** para mostrar de dónde sale el puntaje.

> **Narración:** "Para cada paciente, el motor busca cirujano, quirófano, anestesiólogo por sala y jornada, equipos,
> cajas esterilizadas e insumos. Y explica cada decisión."

### Paso 10. Aprobar (3:25–3:45)

1. En la tarjeta oscura, botón **Aprobar programa** → en el cuadro de confirmación, **Aprobar**.
2. Queda seleccionada la pestaña **Programa aprobado** con la semana. Si la semana actual está vacía, usa la flecha **›**
   para ir a la siguiente.

> **Narración:** "Nada se publica sin que una persona lo apruebe. Al aprobar, las órdenes quedan programadas y los
> recursos reservados."

### Cierre (3:45–4:00)

Vuelve a **Inicio** (las cifras ya cambiaron).

> **Narración:** "De la orden a la sala, con la historia clínica en el centro. Eso es Kizuna."

---

## Si algo falla durante la grabación

| Problema | Solución |
| :--- | :--- |
| La cita de Álvaro no aparece hoy | Corriste `kizuna:demo` otro día. Córrelo de nuevo hoy. |
| El `curl` responde "No autenticado" | El token cambió. Usa el que imprimió el último `kizuna:demo`. |
| "Ya hay una propuesta pendiente" | Ya generaste una antes. En Programación pulsa **Descartar**, o vuelve a correr `kizuna:demo`. |
| No puedes finalizar la historia | Falta un campo obligatorio (aparece marcado en rojo); casi siempre es el consentimiento. |
