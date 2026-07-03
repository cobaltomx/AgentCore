# Setup Frontend PHP — Fase 4

## Desarrollo local (opción más simple)

### Opción A: PHP built-in server (sin instalar nada extra)

```powershell
# Requiere PHP instalado en Windows
# Descargar: https://windows.php.net/download/ (Thread Safe ZIP)
# Extraer en C:\php\ y agregar al PATH

# Levantar servidor PHP local
cd C:\Users\jorge\Documents\Impresion3D\Xpertek\agentcore\frontend
php -S localhost:8080

# Acceder en: http://localhost:8080/login.php
```

### Opción B: XAMPP (más fácil)
1. Descargar XAMPP: https://www.apachefriends.org/
2. Copiar carpeta `frontend/` a `C:\xampp\htdocs\agentcore\`
3. Iniciar Apache en XAMPP Control Panel
4. Acceder: `http://localhost/agentcore/login.php`

---

## Variables de entorno para PHP

Crear archivo `.env.php` en `frontend/` (o configurar en el servidor):

```php
<?php
// frontend/.env.php — NO subir a git
putenv('API_BASE_URL=http://localhost:3000/api/v1');
putenv('APP_ENV=development');
```

O agregar al inicio de `includes/config.php`:
```php
putenv('API_BASE_URL=http://localhost:3000/api/v1');
```

---

## Flujo completo local

```
Browser → http://localhost:8080
  → login.php → POST http://localhost:3000/api/v1/auth/login
  → JWT guardado en $_SESSION
  → index.php → GET http://localhost:3000/api/v1/* (con JWT)
```

Para que funcione:
1. Docker Compose corriendo (backend + postgres + redis) en puerto 3000
2. PHP server corriendo en puerto 8080
3. `API_BASE` en config.php apuntando a `http://localhost:3000/api/v1`

---

## Producción en HostGator

### Subir archivos
1. cPanel → File Manager → `public_html/dashboard/`
2. Subir toda la carpeta `frontend/`
3. O usar FTP: `ftp.tudominio.com`

### Configurar dominio
- `dashboard.tudominio.com` → `public_html/dashboard/`
- O subdominios por tenant: `dental.agentcore.io` → `public_html/tenants/dental/`

### .htaccess para HostGator
```apache
# frontend/.htaccess
Options -Indexes
RewriteEngine On

# Redirigir root a login si no está logueado
# (lo maneja PHP directamente)

# Proteger includes
RewriteRule ^includes/ - [F,L]

# URLs limpias (opcional)
# RewriteRule ^dashboard$ /index.php [L]
```

### Variables en HostGator
En cPanel → Software → PHP Config → Environment Variables:
```
API_BASE_URL = https://tudominio.com:3000/api/v1
APP_ENV      = production
```

O en `.htaccess`:
```apache
SetEnv API_BASE_URL https://tudominio.com:3000/api/v1
SetEnv APP_ENV production
```

---

## Credenciales de prueba (tenant demo)

```
URL:      http://localhost:8080/login.php
Email:    admin@demo-dental.com
Password: Admin123!
```

---

## Estructura de archivos

```
frontend/
├── login.php              ← Página de login
├── logout.php             ← Cierra sesión
├── index.php              ← Dashboard principal
├── includes/
│   ├── config.php         ← Config central + API client
│   ├── head.php           ← HTML <head> + Sneat CSS
│   ├── sidebar.php        ← Menú lateral
│   ├── navbar.php         ← Barra superior
│   └── footer.php         ← Scripts + cierre HTML
├── pages/
│   ├── agents.php         ← Gestión de agentes IA
│   ├── conversations.php  ← Historial de conversaciones
│   ├── leads.php          ← CRM de leads
│   ├── appointments.php   ← Gestión de citas
│   ├── reports.php        ← Reportes (Fase siguiente)
│   ├── billing.php        ← Planes y uso
│   └── settings.php       ← Configuración del tenant
├── api/
│   ├── agent-save.php     ← Proxy: crear/editar agente
│   ├── lead-status.php    ← Proxy: cambiar estado de lead
│   └── settings-save.php  ← Proxy: guardar config tenant
└── assets/
    └── css/dashboard.css  ← Estilos custom sobre Sneat
```
