-- ── Catálogo de productos (conversational commerce) ─────────────────────────
-- Vertical comercios: el bot vende productos con carrito y checkout.
--   products      → catálogo del tenant
--   orders        → pedidos (cabecera)
--   order_items   → líneas de cada pedido
-- El pago reusa el flujo de Stripe Checkout (mismo que anticipos de cita).
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS products (
  id           UUID         PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id    UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name         VARCHAR(160) NOT NULL,
  description  TEXT,
  price_cents  INT          NOT NULL CHECK (price_cents >= 0),
  currency     VARCHAR(3)   NOT NULL DEFAULT 'mxn',
  image_url    TEXT,
  category     VARCHAR(80),
  sku          VARCHAR(80),
  stock        INT,                       -- NULL = inventario ilimitado
  is_active    BOOLEAN      NOT NULL DEFAULT true,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_products_tenant
  ON products (tenant_id, is_active, sort_order);
CREATE INDEX IF NOT EXISTS idx_products_category
  ON products (tenant_id, category) WHERE is_active = true;

-- Pedidos
CREATE TABLE IF NOT EXISTS orders (
  id              UUID         PRIMARY KEY DEFAULT uuid_generate_v4(),
  tenant_id       UUID         NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  conversation_id UUID         REFERENCES conversations(id) ON DELETE SET NULL,
  customer_name   VARCHAR(160),
  customer_phone  VARCHAR(30),
  channel         VARCHAR(20)  NOT NULL DEFAULT 'whatsapp',
  status          VARCHAR(20)  NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','paid','fulfilled','cancelled')),
  total_cents     INT          NOT NULL DEFAULT 0,
  currency        VARCHAR(3)   NOT NULL DEFAULT 'mxn',
  stripe_session  VARCHAR(255),
  payment_url     TEXT,
  paid_at         TIMESTAMPTZ,
  notes           TEXT,
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_orders_tenant ON orders (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_session ON orders (stripe_session);

-- Líneas del pedido (snapshot del producto al momento de comprar)
CREATE TABLE IF NOT EXISTS order_items (
  id           BIGSERIAL    PRIMARY KEY,
  order_id     UUID         NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id   UUID         REFERENCES products(id) ON DELETE SET NULL,
  name         VARCHAR(160) NOT NULL,        -- snapshot por si el producto cambia/borra
  unit_cents   INT          NOT NULL,
  quantity     INT          NOT NULL CHECK (quantity > 0),
  line_cents   INT          NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);
