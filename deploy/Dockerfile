# Read-only ACCPP Wiki on the official MediaWiki 1.43 LTS image (Debian,
# PHP 8.3, Apache, ImageMagick + rsvg already included).
#
# Build from the repo root (site/):  docker compose -f deploy/compose.yml build
#
# Code is baked into the image and never written at runtime; the only mounts
# are the uploaded images (data/images) and a scratch cache volume.
FROM docker.io/library/mediawiki:1.43.11

# Non-bundled extensions, pinned to the exact REL1_43 commits the upgrade was
# tested with (GitHub mirrors of gerrit.wikimedia.org).
ARG VARIABLES_SHA=12ad832a68e1a002c821f5d1c127b04ac047181e
ARG LST_SHA=923701b44703cfcd639d8d399141b194c62c802a
ARG DARKMODE_SHA=6322944164fc2c15ab0c87d4539514e22fbbb3e7
# Citizen skin v3.24.0 (requires MediaWiki >= 1.43).
ARG CITIZEN_SHA=f137342f65b36ad13d3a60d902aa5e6ac13ce646

# The official image installs MediaWiki at the web root; this wiki has always
# lived at /wiki (with articles at /wiki/Title), so move it down a level.
RUN set -eux; \
    mv /var/www/html /var/www/wiki; \
    mkdir /var/www/html; \
    mv /var/www/wiki /var/www/html/wiki; \
    cd /var/www/html/wiki; \
    # Not needed on a live site, and the top-level text files (FAQ, INSTALL,
    # README, ...) would otherwise shadow articles of the same name.
    rm -rf mw-config tests docs \
        CODE_OF_CONDUCT.md COPYING CREDITS FAQ HISTORY INSTALL README.md \
        RELEASE-NOTES-* SECURITY UPGRADE composer.local.json-sample \
        docker-compose.yml jsduck.json; \
    cd extensions; \
    fetch() { \
        mkdir "$1"; \
        curl -fsSL "https://github.com/wikimedia/mediawiki-extensions-$1/archive/$2.tar.gz" \
            | tar -xz --strip-components=1 -C "$1"; \
    }; \
    fetch Variables "$VARIABLES_SHA"; \
    fetch LabeledSectionTransclusion "$LST_SHA"; \
    fetch DarkMode "$DARKMODE_SHA"; \
    mkdir ../skins/Citizen; \
    curl -fsSL "https://github.com/StarCitizenTools/mediawiki-skins-Citizen/archive/$CITIZEN_SHA.tar.gz" \
        | tar -xz --strip-components=1 -C ../skins/Citizen; \
    mkdir -p /var/cache/mediawiki; \
    chown www-data:www-data /var/cache/mediawiki

COPY extensions-custom/ /var/www/html/wiki/extensions/
COPY LocalSettings.php /var/www/html/wiki/LocalSettings.php
COPY assets/ACC1x.png /var/www/html/wiki/resources/assets/ACC1x.png
COPY assets/favicon.ico /var/www/html/favicon.ico
COPY deploy/robots.txt /var/www/html/robots.txt
COPY deploy/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/apache-global.conf /etc/apache2/conf-available/wiki-global.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-wiki.ini

# The official image ships a catch-all short-URL rewrite for a wiki at the web
# root; ours is in apache-site.conf instead.
RUN set -eux; \
    a2disconf short-url 2>/dev/null || true; \
    a2enmod rewrite headers remoteip; \
    a2enconf wiki-global

# Maintenance commands (`php maintenance/run.php ...`) run relative to this.
WORKDIR /var/www/html/wiki

ENV MW_CACHE_DIR=/var/cache/mediawiki \
    MW_UPLOAD_DIR=/var/www/html/wiki/images

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s \
    CMD curl -fsS -o /dev/null "http://localhost/wiki/api.php?action=query&meta=siteinfo&format=json" || exit 1
