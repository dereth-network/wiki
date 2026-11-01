#!/bin/sh
# Apply changes to site/ (LocalSettings, extensions, Dockerfile, Apache
# config) or a MediaWiki/MariaDB version bump. Content, images and .env are
# never touched. Get the new site/ onto the box first (git pull, or rsync
# without --delete), then run this.
set -eu
cd "$(dirname "$0")"

docker compose build --pull
docker compose up -d
# Database schema changes; a no-op unless the MediaWiki version changed.
docker compose --profile admin run --rm admin php maintenance/run.php update --quick

echo "Done. Purge the Cloudflare cache if visitors should see the change right away."
