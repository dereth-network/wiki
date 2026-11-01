#!/bin/sh
# Creates deploy/.env from .env.example with fresh random secrets.
# Refuses to overwrite an existing .env (the DB passwords are baked into the
# database on first start, so regenerating them later would lock you out).
set -eu
cd "$(dirname "$0")"

if [ -e .env ]; then
	echo ".env already exists; not touching it." >&2
	exit 1
fi

rand() { openssl rand -hex 32; }
sed \
	-e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand)|" \
	-e "s|^DB_RW_PASSWORD=.*|DB_RW_PASSWORD=$(rand)|" \
	-e "s|^DB_RO_PASSWORD=.*|DB_RO_PASSWORD=$(rand)|" \
	-e "s|^MW_SECRET_KEY=.*|MW_SECRET_KEY=$(rand)|" \
	.env.example > .env
chmod 600 .env
echo "Wrote deploy/.env -- now set DATA_DIR and CLOUDFLARE_TUNNEL_TOKEN in it."
