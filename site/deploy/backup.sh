#!/bin/sh
# Dumps the wiki database next to the images, so DATA_DIR always holds a
# complete, restorable copy (it's also exactly what a fresh deploy imports).
# The wiki is read-only, so this only needs running after admin edits; copy
# DATA_DIR somewhere off the VPS afterwards (rsync, restic, a Storage Box...).
set -eu
cd "$(dirname "$0")"
. ./.env

out="$DATA_DIR/mediawiki-1.43.sql.gz"
docker compose exec -T db sh -c \
	'mariadb-dump --single-transaction --default-character-set=binary -uroot -p"$MARIADB_ROOT_PASSWORD" mediawiki' \
	| gzip > "$out.tmp"
mv "$out.tmp" "$out"
ls -lh "$out"
