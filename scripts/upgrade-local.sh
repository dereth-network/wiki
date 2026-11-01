#!/bin/bash
# Reproduces the MediaWiki 1.41.0 -> 1.43 LTS upgrade of the ACCPP wiki
# release (accppwiki-docker-mediawiki-1.4x-offline v1.0) on an Ubuntu 24.04
# host, without Docker. Produces:
#   $DATA/images/               uploaded files (no thumbnails)
#   $DATA/mediawiki-1.43.sql.gz upgraded database dump (mediawiki schema only)
#
# Usage: SEED_DIR=<dir with db-data.tar.gz + wiki-data.tar.gz> scripts/upgrade-local.sh
# (SEED_DIR is docker/seed/ from the extracted release.)
set -euo pipefail

SITE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="${ROOT:-$(dirname "$SITE")}"
WORK="${WORK:-$ROOT/work}"
DATA="${DATA:-$ROOT/data}"
SEED_DIR="${SEED_DIR:?set SEED_DIR to the release docker/seed directory}"

MW_VERSION=1.43.11
MW_SHA256=86a5b8e2fb7ca10228d66c8b2659a0a692958e5c520fd023c78bd713a68142f0
# REL1_43 heads at the time of the upgrade
declare -A EXT_COMMITS=(
  [Variables]=12ad832a68e1a002c821f5d1c127b04ac047181e
  [LabeledSectionTransclusion]=923701b44703cfcd639d8d399141b194c62c802a
  [DarkMode]=6322944164fc2c15ab0c87d4539514e22fbbb3e7
)
# Credentials baked into the release's MariaDB data directory.
ROOT_PW=build_time_only_not_kept

mkdir -p "$WORK" "$DATA"
cd "$WORK"

echo "== 1. packages"
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  php8.3-cli php8.3-mysql php8.3-intl php8.3-mbstring php8.3-xml php8.3-apcu \
  php8.3-curl php8.3-gd imagemagick librsvg2-bin mariadb-server-core mariadb-client

echo "== 2. database: unpack the 10.6 datadir and upgrade it"
rm -rf db && mkdir db
tar --no-same-owner -xzf "$SEED_DIR/db-data.tar.gz" -C db
# Leftovers from the original build host (Rosetta cache dir breaks
# mariadb-upgrade; the other two are not needed).
rm -rf db/.cache db/multi-master.info db/.my-healthcheck.cnf db/.accpp-seeded
# Unix socket paths are limited to ~107 bytes.
SOCK="$WORK/mysqld.sock"
[ ${#SOCK} -lt 100 ] || SOCK="/tmp/accppwiki-mysqld-$UID.sock"
cat > my.cnf <<EOF
[mariadbd]
datadir=$WORK/db
socket=$SOCK
pid-file=$WORK/mysqld.pid
port=33306
bind-address=127.0.0.1
log-error=$WORK/mysqld.err
innodb_buffer_pool_size=4G
max_allowed_packet=256M
[client]
socket=$SOCK
port=33306
host=127.0.0.1
EOF
mariadbd --defaults-file="$WORK/my.cnf" > mysqld.out 2>&1 &
until mariadb-admin --defaults-file="$WORK/my.cnf" -uroot -p"$ROOT_PW" ping >/dev/null 2>&1; do sleep 1; done
M=(mariadb --defaults-file="$WORK/my.cnf" -uroot -p"$ROOT_PW")
mariadb-upgrade --defaults-file="$WORK/my.cnf" -uroot -p"$ROOT_PW" --force
"${M[@]}" mediawiki -e "TRUNCATE job; TRUNCATE objectcache; TRUNCATE l10n_cache;
  TRUNCATE querycache; TRUNCATE querycachetwo; TRUNCATE querycache_info;"

echo "== 3. images"
rm -rf "$DATA/images"
tar --no-same-owner -xzf "$SEED_DIR/wiki-data.tar.gz" -C "$DATA" --strip-components=2 ./wiki/images

echo "== 4. MediaWiki $MW_VERSION + extensions"
curl -sfO "https://releases.wikimedia.org/mediawiki/1.43/mediawiki-$MW_VERSION.tar.gz"
echo "$MW_SHA256  mediawiki-$MW_VERSION.tar.gz" | sha256sum -c -
rm -rf mw && tar -xzf "mediawiki-$MW_VERSION.tar.gz" && mv "mediawiki-$MW_VERSION" mw
for e in "${!EXT_COMMITS[@]}"; do
  git clone -q -b REL1_43 "https://gerrit.wikimedia.org/r/mediawiki/extensions/$e" "mw/extensions/$e"
  git -C "mw/extensions/$e" checkout -q "${EXT_COMMITS[$e]}"
done
for e in "$SITE"/extensions-custom/*; do ln -sfn "$e" "mw/extensions/$(basename "$e")"; done
ln -sfn "$SITE/LocalSettings.php" mw/LocalSettings.php
ln -sfn "$SITE/assets/ACC1x.png" mw/resources/assets/ACC1x.png
rm -rf mw/images && ln -sfn "$DATA/images" mw/images
mkdir -p docroot && ln -sfn "$WORK/mw" docroot/wiki && ln -sfn "$SITE/assets/favicon.ico" docroot/favicon.ico
[ -s "$SITE/.secret_key" ] || (umask 077; openssl rand -hex 32 > "$SITE/.secret_key")

echo "== 5. schema update"
# update.php's pagelinks migration (migrateLinksTable.php) updates one title
# at a time -- ~3 hours for this wiki's 670k links. The same result as two
# set-based statements, then marked done so update.php skips it.
"${M[@]}" mediawiki -e "
  INSERT IGNORE INTO linktarget (lt_namespace, lt_title)
    SELECT DISTINCT pl_namespace, pl_title FROM pagelinks WHERE pl_target_id IS NULL;
  UPDATE pagelinks JOIN linktarget ON lt_namespace = pl_namespace AND lt_title = pl_title
    SET pl_target_id = lt_id WHERE pl_target_id IS NULL;
  INSERT IGNORE INTO updatelog (ul_key, ul_value) VALUES ('MigrateLinksTablepagelinks', NULL);"
(cd mw && php maintenance/run.php update --quick)
(cd mw && php maintenance/run.php runJobs)

echo "== 6. dump"
"${M[@]}" mediawiki -e "TRUNCATE objectcache; TRUNCATE l10n_cache;"
rm -rf "$DATA/images/thumb"
mariadb-dump --defaults-file="$WORK/my.cnf" -uroot -p"$ROOT_PW" --single-transaction \
  --default-character-set=binary --hex-blob --routines --triggers --no-tablespaces mediawiki \
  | gzip -6 > "$DATA/mediawiki-1.43.sql.gz"

cat <<EOF
Done. To browse it locally (MariaDB is still running on 127.0.0.1:33306):
  php -d apc.enable_cli=1 -S 127.0.0.1:8099 -t $WORK/docroot $SITE/scripts/dev-router.php
  $SITE/scripts/check-pages.sh
Stop MariaDB with: mariadb-admin --defaults-file=$WORK/my.cnf -uroot -p$ROOT_PW shutdown
EOF
