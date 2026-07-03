# deploy-backend.ps1
# Copia archivos JS del backend al container y lo reinicia
# Uso: .\scripts\deploy-backend.ps1

param(
    [string]$Container = "agentcore_backend",
    [switch]$NoRestart
)

$BackendPath = "$PSScriptRoot\..\backend\src"
$ContainerApp = "/app/src"

Write-Host "📦 Copiando backend/src al container $Container..." -ForegroundColor Cyan

# Copiar todo el directorio src
docker cp "$BackendPath" "${Container}:${ContainerApp}/.."

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Error al copiar archivos" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Archivos copiados" -ForegroundColor Green

if (-not $NoRestart) {
    Write-Host "🔄 Reiniciando container..." -ForegroundColor Cyan
    docker restart $Container | Out-Null
    Start-Sleep -Seconds 3

    $health = curl -s http://localhost:3001/health 2>$null
    if ($health -match '"status":"ok"') {
        Write-Host "✅ Backend listo: $health" -ForegroundColor Green
    } else {
        Write-Host "⚠️  Health check falló. Logs:" -ForegroundColor Yellow
        docker logs $Container --tail=20
    }
}
