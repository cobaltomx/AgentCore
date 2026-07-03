# ============================================================
# AgentCore — Script de arranque del entorno de desarrollo
# Doble clic en start-dev.bat para ejecutar
# ============================================================

$ErrorActionPreference = 'Continue'
$ProjectRoot = "C:\Users\jorge\Documents\agentcore"

function Write-Step($msg) { Write-Host "`n$msg" -ForegroundColor Cyan }
function Write-OK($msg)   { Write-Host "  OK  $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "  !   $msg" -ForegroundColor Yellow }
function Write-Fail($msg) { Write-Host "  ERR $msg" -ForegroundColor Red }

Write-Host ""
Write-Host "================================================" -ForegroundColor Cyan
Write-Host "  AgentCore Dev — Arrancando entorno" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan

# ─── 1. Docker Desktop ───────────────────────────────────────
Write-Step "1/4  Docker Desktop..."

$dockerReady = $false
try { docker info 2>$null | Out-Null; if ($LASTEXITCODE -eq 0) { $dockerReady = $true } } catch {}

if (-not $dockerReady) {
    Write-Warn "Docker no esta corriendo — iniciando..."
    $dockerExe = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    if (Test-Path $dockerExe) {
        Start-Process $dockerExe
    } else {
        Write-Fail "Docker Desktop no encontrado en $dockerExe"
        pause; exit 1
    }

    Write-Host "  Esperando que Docker este listo" -NoNewline
    $waited = 0
    while ($waited -lt 90) {
        Start-Sleep -Seconds 4
        $waited += 4
        Write-Host "." -NoNewline
        try {
            docker info 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $dockerReady = $true; break }
        } catch {}
    }
    Write-Host ""
}

if ($dockerReady) { Write-OK "Docker listo" }
else { Write-Fail "Docker no respondio en 90s — abre Docker Desktop manualmente y vuelve a correr este script"; pause; exit 1 }

# ─── 2. Docker Compose (postgres + redis + backend) ──────────
Write-Step "2/4  Servicios Docker (postgres, redis, backend)..."

Set-Location "$ProjectRoot\docker"
docker compose up -d 2>&1 | ForEach-Object { Write-Host "  $_" }

if ($LASTEXITCODE -eq 0) { Write-OK "Contenedores levantados" }
else { Write-Warn "docker compose tuvo advertencias (revisa arriba)" }

# Esperar backend healthy
Write-Host "  Esperando backend" -NoNewline
$attempts = 0
while ($attempts -lt 20) {
    Start-Sleep -Seconds 3
    $attempts++
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:3001/health" -TimeoutSec 2 -UseBasicParsing -ErrorAction Stop
        if ($r.StatusCode -eq 200) { Write-Host " listo ($($attempts*3)s)"; break }
    } catch {}
    Write-Host "." -NoNewline
}

# ─── 3. ngrok ────────────────────────────────────────────────
Write-Step "3/4  ngrok (tunel publico)..."

$ngrokRunning = $false
try {
    $t = Invoke-RestMethod "http://localhost:4040/api/tunnels" -ErrorAction Stop
    if ($t.tunnels.Count -gt 0) { $ngrokRunning = $true }
} catch {}

if ($ngrokRunning) {
    Write-OK "ngrok ya estaba corriendo"
} else {
    Start-Process powershell -ArgumentList @(
        "-NoExit",
        "-Command",
        "& { `$host.UI.RawUI.WindowTitle='ngrok AgentCore'; ngrok http --domain=untapped-washcloth-starship.ngrok-free.dev 3001 }"
    ) -WindowStyle Minimized
    Start-Sleep -Seconds 3
    Write-OK "ngrok iniciado (ventana minimizada)"
}

# ─── 4. PHP Dashboard ────────────────────────────────────────
Write-Step "4/4  PHP Dashboard (localhost:8080)..."

$phpBusy = $false
try {
    $r = Invoke-WebRequest -Uri "http://localhost:8080" -TimeoutSec 2 -UseBasicParsing -ErrorAction Stop
    $phpBusy = $true
} catch {}

if ($phpBusy) {
    Write-OK "PHP dashboard ya estaba corriendo"
} else {
    Start-Process powershell -ArgumentList @(
        "-NoExit",
        "-Command",
        "& { `$host.UI.RawUI.WindowTitle='PHP AgentCore'; php -S localhost:8080 -t '$ProjectRoot\frontend' }"
    ) -WindowStyle Minimized
    Start-Sleep -Seconds 2
    Write-OK "PHP dashboard iniciado (ventana minimizada)"
}

# ─── Resumen ──────────────────────────────────────────────────
Write-Host ""
Write-Host "================================================" -ForegroundColor Green
Write-Host "  AgentCore Dev LISTO" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green
Write-Host "  Backend   ->  http://localhost:3001/health"
Write-Host "  Dashboard ->  http://localhost:8080"
Write-Host "  ngrok     ->  https://untapped-washcloth-starship.ngrok-free.dev"
Write-Host "  ngrok UI  ->  http://localhost:4040"
Write-Host ""
Write-Host "  Presiona cualquier tecla para cerrar esta ventana..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
