# 🧪 Plan de pruebas — Dashboard del Tenant (AgentCore)

> **Para el tester.** Flujo completo: el **Superadmin** da de alta al cliente y su
> usuario → el tester **entra como ese usuario** al dashboard del tenant → se
> recorre **sección por sección**. Marca cada caso como ✅ OK / ❌ Falla / ⚠️ Observación.

## Cómo reportar
Por cada falla anota: **(1)** sección y caso, **(2)** pasos exactos, **(3)** qué
esperabas, **(4)** qué pasó, **(5)** captura, **(6)** navegador + tamaño de pantalla.

## Entornos / accesos
- Dashboard (frontend): `http://localhost:8080`
- Superadmin: `superadmin@agentcore.io` / `test1234`
- El usuario del tenant lo crea el superadmin en este plan (Fase 1).

## ⚠️ Limitaciones conocidas (NO reportar como bug)
- **Knowledge Base / RAG**: los embeddings usan OpenAI; si la llave no tiene saldo,
  los documentos quedan en estado `error`/`pending` y el bot no usa la KB.
- **WhatsApp a quien llamó por teléfono**: rebota (error 63016) hasta tener plantillas
  aprobadas + sender de producción. El bot lo avisa ("te lo estoy enviando…").
- **Stripe / Billing**: si la llave es de prueba/placeholder, el checkout de plan no
  completa el cobro real.
- **Links públicos (cédulas, widget)**: vía ngrok son temporales y muestran una
  pantalla intermedia.
- **Voz (Cartesia)**: requiere saldo en la cuenta para sintetizar.

---

# FASE 1 — Alta del cliente y su usuario (como Superadmin)

| # | Caso | Pasos | Resultado esperado | ✅/❌ |
|---|------|-------|--------------------|-----|
|1.1| Crear tenant | Admin AgentCore → **Tenants → Nuevo Tenant**. Llena Negocio (nombre, slug, **industria** = la vertical a probar: dental/consultorio/inmobiliaria/otra), Admin (email+contraseña), Límites | Tenant creado; aparece en la tabla con su plan y estado | |
|1.2| Crear/ver usuario | Admin AgentCore → **Usuarios** → verifica que aparece el admin creado, **separado por su negocio** | El usuario está listado bajo su negocio | |
|1.3| Reset de contraseña | En **Usuarios**, botón ✏️ del usuario → escribe nueva contraseña → Guardar | "Contraseña restablecida"; la nueva contraseña servirá para entrar | |
|1.4| Features del tenant | **Tenants → Administrar → Features** → revisa qué canales están ON (voz/webchat/whatsapp) | Coinciden con el plan; los toggles se guardan | |
|1.5| Logout superadmin | Menú de perfil → Cerrar sesión | Vuelve al login | |

---

# FASE 2 — Primer ingreso del tenant

| # | Caso | Pasos | Resultado esperado | ✅/❌ |
|---|------|-------|--------------------|-----|
|2.1| Login del tenant | Entra con el email + **contraseña nueva** del usuario | Entra al **Dashboard del tenant** (NO ve "Admin AgentCore") | |
|2.2| Login inválido | Intenta con contraseña incorrecta | Mensaje de error claro, no entra | |
|2.3| Badge de rol | Revisa el sidebar (abajo) | Dice **Admin** (no Superadmin) | |
|2.4| Aviso "bot no listo" | Observa si hay alerta de configuración pendiente / badge "!" en Simulador | El tenant nuevo arranca con `is_ready = false` hasta aprobar el bot | |

---

# FASE 3 — Recorrido sección por sección

### A. Dashboard (inicio)
- [ ] **A.1** Carga sin errores; muestra resumen/KPIs del negocio.
- [ ] **A.2** Los números tienen sentido (no negativos/NaN).
- [ ] **A.3** Accesos rápidos / pasos de configuración funcionan.

### B. Operación → Conversaciones
- [ ] **B.1** Lista de conversaciones (voz/WhatsApp/chat) con canal, estado, fecha.
- [ ] **B.2** Filtros/búsqueda funcionan.
- [ ] **B.3** Abrir una conversación → **detalle** con la transcripción completa.
- [ ] **B.4** Estados se ven correctos (activa/completada/fallida).

### C. Operación → Leads
- [ ] **C.1** Lista de leads con nombre, teléfono, **estado** (nuevo/calificado/convertido/perdido).
- [ ] **C.2** Cambiar el estado de un lead persiste tras refrescar.
- [ ] **C.3** Búsqueda/filtros por estado.

### D. Operación → Citas
- [ ] **D.1** Lista de citas con fecha, estado, profesional/doctor.
- [ ] **D.2** Confirmar/cancelar una cita refleja el cambio.
- [ ] **D.3** Las fechas/horas se muestran en la zona horaria correcta.

### E. Operación → Catálogo *(si aplica: comercio/inmobiliaria)*
- [ ] **E.1** Listar productos/propiedades.
- [ ] **E.2** Crear uno nuevo (con foto/ficha) → se guarda y aparece.
- [ ] **E.3** Editar y eliminar funcionan.

### F. Operación → Pedidos *(si aplica)*
- [ ] **F.1** Lista de pedidos; abrir detalle; cambiar estado.

### G. Inteligencia IA → Agentes IA
- [ ] **G.1** Ver el/los agentes; abrir el **editor** (agent-editor).
- [ ] **G.2** Editar objetivo/saludo/tono → **Guardar** persiste.
- [ ] **G.3** Activar/desactivar un agente.

### H. Inteligencia IA → Knowledge Base
- [ ] **H.1** Subir un documento (texto/PDF/URL/FAQ).
- [ ] **H.2** El doc aparece y procesa *(⚠️ ver limitación RAG: puede quedar en error)*.
- [ ] **H.3** Eliminar un documento.

### I. Inteligencia IA → Simulador Bot **(crítico — es el gate)**
- [ ] **I.1** Escribir un mensaje → el bot responde **conciso** (1-3 frases, sin muros de texto).
- [ ] **I.2** Probar varios **escenarios** sugeridos.
- [ ] **I.3** Intentar una pregunta fuera de tema → el bot **no se sale** del negocio (capa de seguridad).
- [ ] **I.4** **Aprobar el bot** (requiere ≥3 escenarios probados) → `is_ready` pasa a true.
- [ ] **I.5** Tras aprobar, el badge "!" del Simulador desaparece.

### J. Inteligencia IA → Chat Web (widget)
- [ ] **J.1** Ver el **snippet** para incrustar (debe traer `data-key` y `data-api`).
- [ ] **J.2** Personalizar color/saludo del widget → se guarda.
- [ ] **J.3** *(Opcional)* Pegar el snippet en una página de prueba y conversar.

### K. Vertical: Clínica Dental *(si industria = dental)*
- [ ] **K.1** **Doctores**: crear un doctor con su **horario** (días, horas, **comida opcional**); el *resumen en vivo* y el *llenado rápido* funcionan; validación de horas.
- [ ] **K.2** **Tipos de servicio**: crear/editar.
- [ ] **K.3** **Config. clínica**: ajustes se guardan.
- [ ] **K.4** El horario del doctor se respeta al agendar (probar en Citas/Simulador).

### K'. Vertical: Consultorios *(si industria = consultorio)*
- [ ] **K'.1** **Profesionales**: crear con horario (mismo editor mejorado).
- [ ] **K'.2** Tipos de sesión, Calificación, Sesiones, Config. cargan y guardan.

### L. Marketing → Campañas
- [ ] **L.1** Crear una campaña (contactos, horario, límites).
- [ ] **L.2** Ver estado/progreso.

### M. Marketing → Reportes
- [ ] **M.1** KPIs por periodo (conversaciones, leads + conversión, citas).
- [ ] **M.2** Cambiar el periodo recalcula.

### N. Marketing → Reporte de Valor (ROI)
- [ ] **N.1** Muestra dinero/tiempo ahorrado con valores configurables.

### O. Marketing → Voz del cliente (Insights) **— premium**
- [ ] **O.1** Si el plan es **Starter**: muestra **upsell/PRO** (no datos).
- [ ] **O.2** Si es **Growth+**: muestra los insights.

### P. Administración → Usuarios *(del propio tenant)*
- [ ] **P.1** Listar usuarios del negocio.
- [ ] **P.2** Crear un usuario `user`; **activar/desactivar**.
- [ ] **P.3** Login con ese nuevo usuario `user`.

### Q. Administración → Billing
- [ ] **Q.1** Ver plan actual, minutos usados/límite.
- [ ] **Q.2** Intentar cambiar de plan *(⚠️ depende de Stripe live)*.

### R. Administración → Configuración
- [ ] **R.1** **Perfil de negocio** (nombre, industria, tono, objetivo) guarda.
- [ ] **R.2** **Horarios** (días/horas) guarda — el bot solo agenda dentro de ese rango.
- [ ] **R.3** Otros ajustes (avatar/logo, etc.).

### S. Mi perfil
- [ ] **S.1** Editar nombre/avatar; **cambiar mi propia contraseña**; cerrar sesión.

---

# Casos transversales (probar durante todo el recorrido)
- [ ] **T.1 Rol `user` vs `admin`**: un usuario `user` NO debe ver secciones de admin (Catálogo, Agentes, Usuarios, Billing, Config).
- [ ] **T.2 Aislamiento de datos**: el tenant **solo ve SUS datos** (nunca de otro negocio).
- [ ] **T.3 Gate de canal (Features)**: si el superadmin apaga un canal (p. ej. WhatsApp), ese canal deja de funcionar para el tenant.
- [ ] **T.4 Responsive**: probar en móvil (sidebar colapsa, tablas hacen scroll).
- [ ] **T.5 Sesión**: tras inactividad/expiración, manda a login; logout limpia la sesión.
- [ ] **T.6 Errores**: formularios con datos inválidos muestran mensajes claros (no error 500 crudo).
- [ ] **T.7 Navegación**: el item activo se resalta; no hay enlaces rotos (404).

---

# Plantilla de registro de resultados

| ID caso | Resultado | Severidad | Observación / pasos para reproducir |
|---------|-----------|-----------|--------------------------------------|
| (ej. I.1) | ❌ | Alta | … |

**Severidad:** Crítica (bloquea) · Alta · Media · Baja/cosmética.

---

*Generado para QA del dashboard de tenant. Recorrer en orden; las secciones K/K'
dependen de la industria del tenant de prueba.*
