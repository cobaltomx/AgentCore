# Tests del backend

Runner nativo de Node (`node --test`, sin dependencias extra).

```bash
npm test          # toda la suite
```

> **Correr dentro del contenedor** (ahí están las deps y `DATABASE_URL`):
> ```bash
> docker exec agentcore_backend sh -c "cd /app && npm test"
> ```

## Tipos de test

| Archivo | Tipo | Necesita |
|---|---|---|
| `features.test.js` | unit | — |
| `voice-gate.test.js`, `webchat-gate.test.js`, `whatsapp-gate.test.js` | integración con `app.inject()` y **DB mockeada** | — |
| `superadmin-endpoints.test.js` | integración contra **Postgres real** | `DATABASE_URL` |

Los tests con DB mockeada (`_helpers.js`) no tocan Postgres. Los de
`superadmin-endpoints.test.js` (`_db.js`) sí: ejecutan el SQL de verdad con un
`pg.Pool` real, saltando solo el JWT.

## ⚠️ NUNCA contra la BD productiva

`superadmin-endpoints.test.js` apunta a la base de `DATABASE_URL` y ejecuta
operaciones reales (`INSERT`/`UPDATE`/`DELETE`, incluido borrar tenants y mover
minutos). Los datos son **desechables** (`slug __test_sa_*`, tel `+9999*`) y se
limpian en el hook `after`, pero las operaciones corren sobre la base apuntada.

- En **dev** apunta al Postgres local en Docker (seguro).
- En **CI/prod** usa una **BD de test dedicada** — nunca la productiva.
- Si `DATABASE_URL` no está definida, esa suite se salta (`skip`) en vez de fallar.
