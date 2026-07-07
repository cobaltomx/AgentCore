# 🔍 Auditoría: Usabilidad, Seguridad y Rentabilidad

> **Fecha:** 3 jul 2026 · **Enfoque:** que el sistema sea sólido, intuitivo y altamente rentable.
> Complementa `docs/AUDITORIA-PRODUCCION.md` (infraestructura/resiliencia, ya resuelta).
> **Los hallazgos críticos de seguridad de esta ronda ya fueron corregidos** (commit `b3add31`).

---

## 🔴 SEGURIDAD — hallazgos y estado

### ✅ CORREGIDOS EN ESTA AUDITORÍA

| # | Hallazgo | Riesgo | Fix |
|---|----------|--------|-----|
| S1 | **Fuga de datos cross-tenant en `PATCH /appointments/:id/status`** — el `SELECT` posterior al `UPDATE` no filtraba por `tenant_id`. Un usuario autenticado de CUALQUIER tenant que conociera/adivinara el UUID de una cita ajena recibía sus datos completos (nombre, teléfono, notas) en la respuesta. El `UPDATE` en sí ya estaba protegido (0 filas afectadas), pero el `SELECT` no. | **Alto** — fuga de PII entre negocios competidores en la misma plataforma | Filtrado por `tenant_id`. Verificado con un JWT falsificado cross-tenant: antes fugaba el registro completo, ahora responde vacío. |
| S2 | Mismo patrón en `PATCH /kb/:id` (`knowledge-base.js`) — documentos de base de conocimiento de otro tenant expuestos en la respuesta | **Alto** | Corregido igual |
| S3 | Mismo patrón (menor, side-channel) en `sessions.js:122` | Bajo | Corregido |
| S4 | `/auth/forgot-password` y `/auth/reset-password` **sin rate limit** — abre enumeración de emails y (cuando se active el envío real) spam masivo de correos | Medio | Rate limit agregado (5/15min y 10/15min) |

### 🟡 PENDIENTES (no corregidos, quedan documentados)

| # | Hallazgo | Riesgo | Recomendación |
|---|----------|--------|----------------|
| S5 | El error de S1 al intentar cancelar una cita de otro tenant devuelve `500 Internal Server Error` en vez de `404` — filtra menos que antes, pero el código de estado no es semánticamente correcto | Bajo (cosmético) | Envolver `scheduling.cancelAppointment` en try/catch → 404 si no existe para ese tenant |
| S6 | Self-XSS menor en `appointments.php` (creación manual de cita): el `innerHTML` de la fila recién creada no escapa `name`/`phone` — pero esos datos los escribe el propio admin en su formulario, así que el impacto real es mínimo (el admin solo podría auto-inyectarse) | Bajo | Usar `textContent` o una función `esc()` como ya hacen `widget.js` y `dashboard.js` |
| S7 | Sin CSRF token explícito en los ~60 proxies PHP (mitigado parcialmente por `SameSite=Lax` en la cookie de sesión, que ya está bien configurada con `httpOnly`+`secure` en producción) | Bajo-Medio | Si se expande el equipo/API pública, agregar token CSRF de doble-submit |
| S8 | Envío de email real de "forgot password" es un placeholder (`// En producción aquí iría el envío de email`) — hoy el link se devuelve en la respuesta JSON en modo dev, lo cual es correcto para dev pero significa que la función de recuperación de contraseña **no funciona en producción todavía** | Medio (funcional, no solo seguridad) | Integrar un proveedor de email (Resend/SendGrid/SES) antes del piloto real |

### 🟢 LO QUE ESTÁ BIEN
- `reset-password`: token de un solo uso (se borra tras usar), TTL de 1h, invalida sesiones activas al cambiar contraseña, bcrypt cost 12.
- Cookie de sesión: `httpOnly` + `SameSite=Lax` + `secure` en producción.
- Uploads: whitelist de MIME + entidades permitidas.
- XSS: `widget.js` (público) y `dashboard.js` escapan consistentemente los datos dinámicos (`esc()`/`escHtml()`), incluyendo el HTML server-side de citas/leads reales (`e()` en PHP).
- Sin secretos en logs (verificado tras la migración a logger estructurado).
- Todas las demás rutas `v1/*` revisadas SÍ filtran por `tenant_id` correctamente — S1-S3 eran los únicos escapes del patrón.

---

## 🟠 USABILIDAD / INTUITIVO — hallazgos

| # | Hallazgo | Impacto | Recomendación |
|---|----------|---------|----------------|
| U1 | **Sidebar con hasta 40 ítems** para un tenant con todas las verticales/features activas — puede abrumar a un dueño de PyME no-técnico | Medio | Ya está agrupado por secciones colapsables; considerar un modo "básico/avanzado" que oculte configuración rara vez usada (Cal.com, EA) por default |
| U2 | **Help-tips (tooltips contextuales) solo en 2 de 20 páginas** — la infraestructura ya existe (popovers inicializados globalmente en `footer.php`) pero está subutilizada. Campos como "temperatura del modelo", "triage", "slot duration" no son intuitivos para un no-técnico | **Alto** — es la palanca de "intuitivo" más barata de accionar | Agregar `help-tip` a los ~15-20 campos más confusos en agent-editor.php, settings.php, campaigns.php |
| U3 | Mensajes de error con fallback genérico `"Error"` sin acción sugerida en 3 páginas (conversations, orders, products) cuando la API no devuelve `.error` | Bajo | Fallback más útil: `"Algo salió mal. Intenta de nuevo o contacta soporte."` |
| U4 | Onboarding (5 pasos) permite "saltar por ahora" y persiste cada paso vía API inmediatamente (no se pierde progreso si se cierra a medias) — **esto está bien resuelto**, no requiere cambio | — | — |
| U5 | Confirmaciones ante acciones destructivas (eliminar KB, desactivar usuario) usan un `confirmToast()` custom consistente — **bien resuelto** | — | — |
| U6 | El modal de aceptación de Términos (recién agregado) es bloqueante mundial — correcto, pero conviene revisar en el primer login real de un tenant existente que no rompa flujos automatizados (ej. si algún script/integración usa la sesión sin pasar por el navbar) | Bajo | Verificar una vez con un usuario real |

---

## 💰 RENTABILIDAD / MONETIZACIÓN — hallazgos (vía agente explorador)

| # | Hallazgo | Impacto | Recomendación |
|---|----------|---------|----------------|
| R1 | **Brecha de concurrencia en el límite de minutos**: el gate de `max_minutes_mo` se revisa SOLO al inicio de la llamada (`twilio.js`); el consumo real se registra recién al cerrar la conversación (`usage-tracker.js`). Una llamada que empieza con crédito disponible puede extenderse mucho más allá del límite sin cortarse a medio camino | **Alto** (fuga de margen real) | Chequeo periódico de duración vs. límite durante la llamada (ej. cada 60s en el media-stream), o límite duro de duración por llamada |
| R2 | **Sin visibilidad de ROI para el tenant** — el dashboard de reportes muestra actividad (conversaciones, leads, citas) pero no traduce eso a valor: "$X ahorrados en horas de recepcionista", "$Y en ventas cerradas por el bot". Sin esto, un tenant que paga $149-899 MXN/mes no tiene un recordatorio concreto de por qué vale la pena — **factor de churn directo** | **Alto** | Agregar una tarjeta de "Valor generado este mes" en el dashboard/reportes (ya existe un borrador de "Reporte de Valor" según memoria del proyecto — completar/exponerlo mejor) |
| R3 | **Trial sin expiración automática** — `trial_period_days: 14` se configura en Stripe, pero no hay job que marque `status='suspended'` cuando el trial vence sin tarjeta, ni notificación previa ("tu prueba termina en 3 días") | **Alto** (fuga de conversión) | Webhook de Stripe (`customer.subscription.deleted`) debe marcar el tenant como suspendido; agregar recordatorio a los 11 días de trial |
| R4 | **Costo/margen por tenant visible solo al superadmin, sin alertas proactivas** — existe `cost-estimator.js` que calcula margen real, pero no hay alerta automática si un tenant cuesta más de lo que paga (margen negativo) | Medio | Notificación al superadmin cuando `marginPct < 0` para algún tenant (reusa el patrón de `balance-monitor.js` ya construido) |
| R5 | **Cero prevención de churn por inactividad** — no hay detección de tenants que dejaron de usar el bot (conversaciones cayendo a 0), ni campaña de reactivación automática | Medio | Job semanal simple: tenants con <N conversaciones en 7 días → notificar al superadmin o email automático al tenant |
| R6 | ✅ Enforcement de `max_agents` (número de agentes por plan) SÍ bloquea correctamente con error 403 — fuerte | — | — |
| R7 | ✅ Barra de uso de minutos con alerta visual al 80-90% en `reports.php`/`billing.php` — buen micro-engagement, aunque pasivo (sin CTA proactiva de upgrade) | — | Agregar botón de upgrade directo en esa misma alerta, no solo informativo |

---

## 📋 Priorización sugerida

**Ya resuelto en esta sesión:** S1, S2, S3, S4 (seguridad crítica).

**Quick wins (próxima sesión, bajo esfuerzo/alto impacto):**
1. **U2** — help-tips en campos confusos (sube "intuitivo" con poco esfuerzo, la infra ya existe).
2. **R7** — CTA de upgrade en la barra de uso (un botón).
3. **S5/S6** — pulido cosmético de errores.

**Estructurales (mayor esfuerzo, alto impacto en rentabilidad):**
4. **R1** — chequeo de límite de minutos durante la llamada (evita fuga real de margen).
5. **R2** — reporte de ROI visible al tenant (reduce churn).
6. **R3** — expiración automática de trial + recordatorio (sube conversión).
7. **R4/R5** — alertas de margen negativo y de inactividad (protege rentabilidad y reduce churn).

---
*Generado por auditoría dirigida (exploración directa + agente especializado en monetización). Actualizar al cerrar cada punto.*
