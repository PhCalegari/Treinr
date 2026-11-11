#!/usr/bin/env bash
# Backup diário do Treinr no servidor: banco de dados + arquivos enviados (fotos de evolução).
# Roda pelo cron do usuário (03:30). Guarda 14 dias em ~/backups/treinr.
# Restaurar o banco:  gunzip -c db-AAAA-MM-DD.sql.gz | mariadb -u treinr -p treinr
set -euo pipefail

APP=/var/www/treinr
DEST="$HOME/backups/treinr"
KEEP_DAYS=14
mkdir -p "$DEST"
chmod 700 "$HOME/backups" "$DEST"
STAMP=$(date +%F)

# Credenciais lidas do .env do app (não ficam neste arquivo).
env_get() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB=$(env_get DB_DATABASE)
DB_USER=$(env_get DB_USERNAME)
DB_HOST=$(env_get DB_HOST)
DB_PORT=$(env_get DB_PORT)

MYSQL_PWD="$(env_get DB_PASSWORD)" mysqldump --single-transaction --quick --routines \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB" | gzip -9 > "$DEST/db-$STAMP.sql.gz.part"
mv "$DEST/db-$STAMP.sql.gz.part" "$DEST/db-$STAMP.sql.gz"

tar -czf "$DEST/storage-$STAMP.tar.gz" -C "$APP/storage" app

chmod 600 "$DEST"/*
find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete
echo "$(date '+%F %T') backup ok: $(du -sh "$DEST" | cut -f1)" >> "$DEST/backup.log"
