# 🧪 Prueba con usuario nuevo — Guía + hoja de observación

> Para poner a un usuario sin conocimiento del sistema a probarlo y detectar
> fricción. Incluye: cuenta de prueba lista, tareas guiadas, hoja para anotar
> hallazgos, y lo que ya detecté en mi auditoría "ojos nuevos".

---

## 🔑 Cuenta de prueba (lista para usar)

| Campo | Valor |
|---|---|
| URL | http://localhost:8080 |
| Usuario | **tester@prueba.com** |
| Contraseña | **Prueba1234** |
| Negocio | Negocio de Prueba (restaurante, plan Growth) |

> Es una cuenta **limpia y aislada** — el tester no toca los datos demo.
> Cuando termines la prueba, se puede borrar sin afectar nada.

---

## 📋 Tareas para el tester (no le des pistas, observa dónde se traba)

Dale solo estas instrucciones de alto nivel y observa **cómo** las resuelve:

1. **Entra y configura tu negocio.** (Pasa por el onboarding.)
2. **Haz que tu bot sepa de tu negocio.** (Que cargue FAQs / conocimiento.)
3. **Prueba cómo responde tu bot** antes de activarlo.
4. **Activa tu bot.**
5. **Pon el chat en tu “sitio web”.** (Que encuentre el widget web.)
6. **Agrega un producto** y **simula una venta** por el chat.
7. **Encuentra cuánto valor generó tu bot.** (Reporte de Valor.)
8. **Haz que un cliente “pida hablar con una persona”** y atiéndelo.

---

## ✅ Hoja de observación (marca lo que pase)

Para cada tarea anota: **¿lo logró solo? ¿dónde dudó? ¿qué preguntó?**

| Tarea | ¿Lo logró? | ¿Dónde dudó / se trabó? | Cita textual del tester |
|---|---|---|---|
| 1. Onboarding | ☐ Sí ☐ No | | |
| 2. Conocimiento | ☐ Sí ☐ No | | |
| 3. Simulador | ☐ Sí ☐ No | | |
| 4. Activar bot | ☐ Sí ☐ No | | |
| 5. Widget web | ☐ Sí ☐ No | | |
| 6. Producto + venta | ☐ Sí ☐ No | | |
| 7. Reporte de Valor | ☐ Sí ☐ No | | |
| 8. Handoff humano | ☐ Sí ☐ No | | |

**Señales de fricción a vigilar:**
- Se queda mirando la pantalla sin saber qué clicar (falta de guía).
- Usa el buscador o el menú para algo que debería ser obvio.
- Pregunta "¿y ahora qué?" o "¿por qué no funciona?".
- Abandona una tarea a la mitad.
- Repite un paso porque no entendió que ya lo hizo.

---

## 🔍 Lo que YA detecté en mi auditoría ojos nuevos

Recorrí el flujo completo como usuario nuevo. Esto es lo que vi:

### 👍 Lo que funciona bien (no te preocupes por esto)
- **Onboarding**: 4 pasos claros, saludo personalizado, el saludo del agente
  **se auto-adapta al rubro** (sugirió "¿hacer una reserva?" para restaurante).
- **Botones "Saltar por ahora"** en Teléfono y Conocimiento → el tester sin
  credenciales puede avanzar sin trabarse.
- **Dashboard post-onboarding**: la tarjeta "Configuración inicial — Activa tu
  bot (1/5 pasos)" le dice exactamente qué hacer después. Excelente brújula.
- **Simulador**: escenarios sugeridos por industria + zona de protección visible.

### 🟢 Bugs que encontré y YA corregí durante la auditoría
1. **La industria del onboarding no llegaba al simulador**: el tester elegía
   "restaurante" pero el simulador mostraba escenarios "general". Causa: se
   guardaba en `businessProfile.industry` y el simulador leía `industry`.
   **Corregido** (ahora lee ambas rutas). El tester ya verá escenarios de su giro.
2. **El widget web de un cliente NUEVO daba 404** (¡crítico!): los tenants
   creados después de la migración del widget quedaban sin `widget_key`, así que
   su chat web no funcionaba. **Corregido de raíz**: la columna ahora tiene
   DEFAULT, todo tenant nuevo genera su clave automáticamente.

### 🔵 Hallazgo de integridad (anotado, no bloquea la prueba)
- **No se puede borrar un tenant** si tiene registros en `audit_log` (la FK no
  tiene `ON DELETE CASCADE`). Relevante para "borrar cuenta" / GDPR / data
  retention. Lo agregué al checklist de producción.

### 🟡 Fricciones probables que conviene vigilar (aún no resueltas)
1. **"¿Por qué mi bot no responde?"** — Hasta no aprobar en el Simulador
   (`is_ready`), el widget/WhatsApp/voz NO responden. Un tester puede activar el
   widget y frustrarse porque "no contesta". → Vigila si entiende que primero
   debe aprobar en el Simulador.
2. **Conectar canal sin credenciales** — Twilio/Meta requieren cuentas externas.
   El tester sin ellas no puede probar voz/WhatsApp reales; solo el widget web
   y el simulador. → ¿Entiende que el widget web es el camino sin credenciales?
3. **Industrias sin escenarios propios** — inmobiliaria, gym, taller, educación
   caen a escenarios "general" (solo dental, restaurante, ecommerce, consultorio
   tienen propios). → Menor; solo si el tester eligió una de esas.
4. **RAG/Knowledge Base** — hoy la indexación falla por la cuota de OpenAI (ver
   checklist). El bot responde igual con el prompt, pero las FAQs cargadas no se
   "recuperan". → El tester no lo notará salvo que pruebe una FAQ muy específica.

---

## 🎯 Cómo capturar el máximo valor de la prueba

- **No ayudes** mientras prueba. El silencio incómodo revela la fricción.
- Pídele que **piense en voz alta** ("¿qué crees que hace ese botón?").
- Anota las **palabras exactas** que usa — su vocabulario te dice cómo etiquetar
  las cosas en la interfaz.
- Al final pregúntale: *"¿En qué momento te sentiste perdido?"* y
  *"¿Qué esperabas que pasara y no pasó?"*.

---

*Documento generado el 2026-06-17 tras una auditoría ojos nuevos del flujo real.*
