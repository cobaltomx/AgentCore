#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
#  Backup de la base de datos AgentCore (Postgres) — pg_dump comprimido.
#
#  Uso:
#    ./scripts/backup-db.sh [dir_destino]      (default: ./backups)
#
#  Programar diario con cron (en el host de producción):
#    0 3 * * * cd /ruta/agentcore && ./scripts/backup-db.sh >> /var/log/agentcore-backup.log 2>&1
#
#  Retención: conserva los últimos $KEEP backups (default 14) y borra el resto.
#  Restaurar:
#    gunzip -c backups/agentcore-YYYYmmdd-HHMMSS.sql.gz | \
#      docker compose -f docker/docker-compose.prod.yml exec -T postgres \
#      psql -U agentcore -d agentcore
# ════════════════════════════════════════════════════════════════════
set -euo pipefail

DEST="${1:-./backups}"
KEEP="${BACKUP_KEEP:-14}"
CONTAINER="${PG_CONTAINER:-agentcore_postgres}"
DB="${POSTGRES_DB:-agentcore}"
USER="${POSTGRES_USER:-agentcore}"

mkdir -p "$DEST"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$DEST/agentcore-$STAMP.sql.gz"

echo "[backup] $(date) → $OUT"
docker exec -t "$CONTAINER" pg_dump -U "$USER" -d "$DB" --clean --if-exists \
  | gzip > "$OUT"

SIZE="$(du -h "$OUT" | cut -f1)"
echo "[backup] OK ($SIZE)"

# Retención: borrar los backups más viejos que sobren.
COUNT="$(ls -1 "$DEST"/agentcore-*.sql.gz 2>/dev/null | wc -l | tr -d ' ')"
if [ "$COUNT" -gt "$KEEP" ]; then
  ls -1t "$DEST"/agentcore-*.sql.gz | tail -n +$((KEEP + 1)) | while read -r old; do
    echo "[backup] retención: borrando $old"
    rm -f "$old"
  done
fi
echo "[backup] hecho. Backups conservados: $(ls -1 "$DEST"/agentcore-*.sql.gz 2>/dev/null | wc -l | tr -d ' ')"
