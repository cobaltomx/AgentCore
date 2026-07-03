# 🎯 Rediseño del Dashboard del Tenant — Investigación y propuesta

> Objetivo: que el dashboard **dé valor real**, se sienta **fluido, sin fricción y
> bien organizado**. Basado en (a) auditoría del dashboard actual, (b) patrones del
> mercado (recepcionistas IA, scheduling SaaS, front-desk de clínicas) y (c) una
> propuesta concreta priorizada.

---

## 1. Diagnóstico del dashboard actual (honesto)

Hoy el home es un **dashboard de métricas genérico** (plantilla Sneat): saludo →
teaser ROI → calendario → 4 KPIs → guía de setup → gráfica 7 días → tablas recientes.

**Lo bueno:** carga rápido, ya tiene calendario (lo subimos), guía de onboarding,
gráfica de conversaciones.

**Problemas de fondo (fricción / poco valor):**
1. **Es pasivo, no accionable.** Muestra *cuántas* conversaciones/leads/citas hay,
   pero NO **qué necesito hacer ahora** (citas por confirmar, leads sin atender,
   conversaciones escaladas). El usuario tiene que ir a buscar el trabajo.
2. **KPIs de vanidad.** "Conversaciones hoy", "Leads total", "Citas este mes",
   "Minutos usados" → cuentas, no decisiones. No dicen si algo va mal.
3. **El valor (ROI) está escondido** como "teaser". Lo más vendible —*"tu bot te
   generó $X / ahorró Y horas"*— debería ser protagonista, no un banner colapsado.
4. **Redundancia:** los minutos aparecen 2 veces (tarjeta de bienvenida + KPI).
5. **Controles muertos:** el dropdown "Esta semana / Este mes" de la gráfica son
   `href="#"` (no hacen nada) → fricción y desconfianza.
6. **KPI "Conversaciones hoy" se queda en placeholder** (carga client-side; si falla
   queda en "loading" gris).
7. **Sin "última actualización"** ni auto-refresh → no sabes si el dato es fresco.
8. **Tablas recientes sin triage:** listan, pero no priorizan (lead caliente, cita
   sin confirmar, sentimiento negativo).

---

## 2. Qué hace el mercado (y qué robar)

**Recepcionistas IA (myAIfrontdesk, Smith.ai, Synthflow, Newo):** centralizan TODA
la actividad entrante (llamadas/citas/leads) en una sola vista **accionable**, con
resumen por llamada (acciones realizadas, prioridad/urgencia, **"qué necesita tu
atención"**), y **alertas** cuando la IA detecta confusión o datos faltantes.

**Dashboards operativos (best practices 2025-26):** se escanean en **2-5 segundos**;
indicadores de estado grandes + color; **divulgación progresiva** (resumen primero,
detalle a demanda); vista **"hoy"**; **"última actualización a las HH:MM"**; y la
regla de oro: *esconder todo lo no esencial y priorizar "la única cosa que el usuario
debe entender en 5 segundos".*

**Front-desk de clínicas (no-shows):** una **cola de trabajo** con estado de
confirmación —*quién confirmó, quién NO respondió → mándale un recordatorio*—.
Recordatorios automáticos reducen no-shows de ~30% a <10%. Esto encaja perfecto con
AgentCore (el bot ya confirma por WhatsApp; el dashboard debe **mostrar quién falta**).

---

## 3. La idea central (north star)

> **De "panel de métricas" a "cabina de operación del día".**
> Lo primero que ves al entrar responde: **¿Qué necesita mi atención HOY?** y
> **¿Cuánto valor me está generando el bot?** El resto, por divulgación progresiva.

---

## 4. Propuesta concreta — nuevo orden del dashboard

### A. Barra de estado (1 línea, compacta)
Negocio · Plan · **Bot ✅ activo / ⚠️ no aprobado** · Minutos (1 sola vez, con barra) ·
**"Actualizado 10:42"** + auto-refresh cada 60s. Elimina la duplicación de minutos.

### B. ⭐ "Necesita tu atención" (la pieza estrella — solo aparece si hay algo)
Una **bandeja de pendientes** con acción directa, agrupada y con badges de conteo:
- **Citas de hoy/mañana SIN confirmar** → botón *Recordar por WhatsApp* / *Confirmar*.
- **Leads nuevos sin atender** (status `new` capturados por el bot) → *Ver / Llamar*.
- **Conversaciones escaladas** (el bot pidió handoff humano) → *Atender*.
- **Alertas del sistema**: minutos ≥90%, bot no aprobado, KB en error, WhatsApp
  rebotando (63016), Cartesia sin saldo.

> Es el mayor salto de valor: convierte el dashboard en un **inbox de trabajo**.
> Si no hay pendientes, muestra un estado vacío positivo ("Todo al día ✨").

### C. Hoy: Agenda
**"Hoy tienes N citas"** + lista del día con **estado de confirmación** (✅ confirmó /
⏳ esperando / ❌ canceló) y acción de recordatorio. El **calendario mensual** queda
debajo o en pestaña (Hoy | Semana | Mes) — divulgación progresiva.

### D. 💰 Valor generado (protagonista, no teaser)
*"Este mes tu bot atendió N conversaciones, generó N citas, capturó N leads → ~$X en
valor / Y horas ahorradas."* Con comparación vs mes anterior. Es el argumento de
retención del cliente.

### E. Pulso del negocio (KPIs que SÍ deciden)
Reemplazar las 4 cuentas por métricas accionables con **tendencia (▲▼ vs periodo
anterior)**: **Tasa de confirmación**, **Conversión lead→cita**, **No-shows**,
**Tiempo de respuesta del bot**. (Las cuentas crudas van como subtítulo.)

### F. Embudo + tendencia
**Embudo en vivo**: Conversaciones → Leads → Citas → Confirmadas (con %). Junto a la
gráfica de 7 días **con el dropdown FUNCIONAL** (semana/mes recalcula de verdad).

### G. Actividad reciente con triage
Conversaciones/leads recientes pero **ordenados por prioridad** (lead caliente,
sentimiento, cita ligada), con acción rápida y link al detalle.

---

## 5. Principios transversales (fluidez sin fricción)
- **Divulgación progresiva:** resumen arriba, detalle a un clic. Nada de muros.
- **Acción en su lugar:** cada item con su botón (confirmar, recordar, atender) sin
  saltar de página cuando se pueda (modales/inline).
- **Estados claros:** color por estado, *empty states* positivos, "última
  actualización", skeletons en vez de placeholders grises eternos.
- **Sin controles muertos:** todo lo que se ve, funciona.
- **Consistencia:** mismos patrones que ya usamos en el panel superadmin (selector,
  badges, tablas) para que se sienta un solo sistema.
- **Responsive de verdad:** prioridad de contenido en móvil (atención > agenda > resto).

---

## 6. Roadmap priorizado

### 🟢 Quick wins (alto impacto / bajo esfuerzo) — empezar aquí
1. **Quitar redundancia y arreglar lo roto**: 1 solo indicador de minutos; dropdown de
   la gráfica funcional o quitarlo; "última actualización".
2. **Subir el Valor generado** de teaser a tarjeta protagonista.
3. **Bandeja "Necesita tu atención" v1**: citas de hoy sin confirmar + leads nuevos +
   alertas (con los datos que YA tenemos). ← el mayor salto percibido.

### 🟡 Medio
4. **"Hoy / Semana / Mes"** en la agenda + estado de confirmación por cita + botón
   recordar (reusa el envío de WhatsApp/recordatorios que ya existe).
5. **KPIs accionables con tendencia** (tasa de confirmación, conversión, no-shows).
6. **Embudo en vivo** conversaciones→leads→citas→confirmadas.

### 🔵 Mayor (bets)
7. **Conversaciones escaladas / handoff humano** (requiere marcar en el bot cuándo
   escalar) → inbox de atención en vivo.
8. **Auto-refresh / tiempo real** (polling 60s o WebSocket) de la bandeja y agenda.
9. **Personalización por vertical**: clínica ve no-shows/confirmaciones; inmobiliaria
   ve propiedades enviadas/visitas; comercio ve pedidos.

---

## Fuentes (mercado)
- [myAIfrontdesk — AI Receptionist](https://www.myaifrontdesk.com/)
- [Smith.ai — AI Receptionist](https://smith.ai/ai-receptionist)
- [Synthflow — Enterprise AI Receptionist](https://synthflow.ai/ai-receptionist)
- [10 Dashboard Design Best Practices for SaaS (2025)](https://www.context.dev/blog/dashboard-design-best-practices)
- [Smart SaaS Dashboard Design Guide (2026) — F1Studioz](https://f1studioz.com/blog/smart-saas-dashboard-design/)
- [7 SaaS UI Trends 2026 — saasui.design](https://www.saasui.design/blog/7-saas-ui-design-trends-2026)
- [Appointment Reminders Reduce No-Shows — Curogram](https://curogram.com/blog/emr-integration/how-appointment-reminders-reduce-no-shows-in-clinics)
- [Appointment Reminder Best Practices — bcat](https://mybcat.com/blog/appointment-reminder-best-practices/)

*Documento de propuesta. Implementar por fases (ver Roadmap). Recomendado iniciar por
los Quick wins, especialmente la bandeja "Necesita tu atención".*
