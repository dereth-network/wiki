# MediaWiki 1.41.0 → 1.43 LTS upgrade

Source: `accppwiki-docker-mediawiki-1.4x-offline` v1.0 (MediaWiki 1.41.0, PHP
7.4.33, MariaDB 10.6.28). Done 2026-10-31 with `scripts/upgrade-local.sh`.

| | Before | After |
|---|---|---|
| MediaWiki | 1.41.0 (EOL, unpatched) | **1.43.11** LTS (GPG signature verified, sha256 pinned in the script) |
| PHP | 7.4.33 | 8.3 (tested with 8.3.6, Ubuntu 24.04) |
| Database | MariaDB 10.6.28 | dump tested on MariaDB 10.11.14; any 10.11/11.4 LTS server will do |
| Content | 123,867 pages / 345,340 revisions / 60,391 files | unchanged |

## Outputs

- `data/mediawiki-1.43.sql.gz`: `mariadb-dump` of the `mediawiki` database only.
  It contains **no MariaDB system tables, users or passwords**: create your own DB
  user wherever you import it. Restore was tested into a scratch database, and the
  row counts and text bytes match.
- `data/images/`: uploaded files, without `thumb/`. Thumbnails are now generated
  server-side by ImageMagick on first view. Don't copy thumbnails from a local
  test run: ImageMagick writes the local `$wgServer` URL into each JPEG
  comment.
- `site/`: everything that should go into git (this file, `LocalSettings.php`,
  `extensions-custom/`, `assets/`, `scripts/`).

## What changed

### Software
- Core: 1.41.0 → 1.43.11. Bundled extensions and skins come from the 1.43 tarball.
- Non-bundled extensions are re-fetched on their `REL1_43` branches and pinned to
  commits in `scripts/upgrade-local.sh`: Variables (2.6.0-beta), LabeledSectionTransclusion,
  DarkMode (0.2.1).
- **Removed:**
  - `MaintenanceShell` (ran maintenance scripts from the browser)
  - `ImportDump`, `DataDump` (Miraheze wiki-farm tools, not needed here)
  - `CollapsibleVector` (abandoned upstream; it only collapsed sidebar portals)
- **MenuSidebar** ported to 0.6 (`extensions-custom/MenuSidebar`). It is
  registered via `extension.json` (no more `require_once`) and uses injected
  services instead of the `$wgParser`/`$wgTitle`/`$wgUser` globals. It uses a
  fresh Parser instance, so menu parsing can't re-enter the page parse. The
  built menu is cached per `MediaWiki:MenuSidebar` revision instead of being
  re-parsed on every page view, and saving that page purges it. Rendered output
  is unchanged (same HTML and classes; 64 flyout menus on Home). One small
  difference: parsed items are parsed as `MediaWiki:MenuSidebar`, not as the
  current page, so the menu can be cached.
- **YouTubeTag** copied unchanged. It was reviewed: all output goes through
  `Html::element` (escaped), and width and height are cast to int.
- Branding (`ACC1x.png` logo and `favicon.ico`) now lives in `site/assets/`
  instead of being dropped into the core tree.

### Configuration (`LocalSettings.php`)
- All deployment-specific values come from `MW_*` environment variables:
  `MW_SERVER`, `MW_DB_HOST/NAME/USER/PASS`, `MW_SECRET_KEY` (or a gitignored
  `.secret_key` file), `MW_UPLOAD_DIR`, `MW_CACHE_DIR`, `MW_FORCE_HTTPS`,
  `MW_READ_ONLY`, `MW_SITENAME`. The defaults only suit the local test setup.
- **Read-only for the public:** anonymous users and logged-in non-admins can't
  edit, create pages, upload, move, purge, or register. Only sysops can edit
  and upload. Setting `MW_READ_ONLY="<message>"` freezes the database
  entirely, sysops included.
- `$wgShowExceptionDetails = false` (was true), `$wgShowDBErrorBacktrace = false`.
- License footer: CC BY-SA 3.0, linking `ACCPP_Wiki:Copyrights`, which is the
  license the wiki's own Copyrights page states. Before this the footer was
  empty.
- Thumbnails: ImageMagick (`/usr/bin/convert`); SVG via `rsvg-convert`.
- Caching: APCu main cache; the parser cache and sessions are in the database
  (`CACHE_DB`); the localisation cache is in files under `$wgCacheDirectory`.
- `$wgJobRunRate = 0`: run `maintenance/run.php runJobs` from cron or a worker.
- `$wgCategoryPagingLimit = 5000`: category pages list all members on one page.
- Email is off (`$wgEnableEmail = false`) until an SMTP relay is configured.
  Reset admin passwords with `maintenance/run.php changePassword`.
- Dropped the meaningless `$wgSharedTables[] = "actor"` and the `$wgDiff3` path.
- **When `MW_READ_ONLY` is set** (the public instance in `deploy/`, which also
  connects with a SELECT-only DB user):
  - The parser cache, sessions and main stash move to APCu, since nothing may
    write to the database.
  - `$wgUseCdn` is on with `$wgCdnMaxAge = 86400`, so anonymous views send
    `s-maxage` and Cloudflare caches them.
  - `local-backend` is declared explicitly with `readOnly => false`. Read-only
    mode would otherwise also freeze file storage, and every thumbnail would
    fail with "Unable to save thumbnail to destination". Uploads still fail,
    because they need the database.
- `$wgCookieSecure` follows `MW_SERVER` (TLS ends at Cloudflare, so Apache
  only sees HTTP). `MW_FORCE_HTTPS` is left off in the deployment for the
  same reason: Cloudflare's "Always Use HTTPS" does the redirect.

### Database
- `mariadb-upgrade` from 10.6 to 10.11 system tables. A stray `.cache/rosetta`
  directory (left by the original Apple-Silicon build) had to be removed from the
  data directory first. MariaDB treated it as a database and failed on it.
- Cleared stale cache tables (`objectcache`, `l10n_cache`, `querycache*`).
  `job` was already empty.
- `update.php` (1.43.11): block/block_target migration (`ipblocks` dropped),
  `pagelinks` moved to `linktarget` (`pl_title` and `pl_namespace` dropped),
  `rev_id`/`rc_id` widened, `user_is_temp` populated, and smaller index changes.
- The `pagelinks` → `linktarget` backfill was done with two set-based SQL
  statements instead of `migrateLinksTable.php`, which goes one title at a time
  and would have taken about 3 hours here. After the backfill, 0 rows were left
  NULL and 0 rows mismatched.
- The `user` table is unchanged: 6 accounts (ACCPPAdmin, Accppadmin, FandomImport,
  Maintenance script, MediaWiki default, Owner), with pbkdf2 hashes and no
  email addresses. **Reset the admin passwords after deploying.**

## Verification (local, `php -S` + `scripts/check-pages.sh`)

- HTTP 200 and no render errors for: Home, a Quest Summary page, a Dialog page,
  a Melee Weapon item, a Creature, Clothing (`#expr` + `PAGESINCATEGORY`),
  Category:Axe, a File page, and a `<youtube>` page.
- 400 random pages (300 articles, 50 categories, 50 files): 0 exceptions, 0 raw
  `{{#...}}`, 0 missing templates, and MenuSidebar present on every page.
  One `Expression error` was found on *Killagurg's Arena*: a map-marker template
  is called without coordinates. That is a content issue that predates the
  upgrade.
- Thumbnails generate correctly (PNG and JPEG), and load.php modules load
  without errors.
- Anonymous users get "View source" instead of an edit form, and CreateAccount
  and Upload return permission errors. Anonymous API rights are `read` plus the
  self-preference rights only.
- **Not verified:** client-side JavaScript in a real browser, i.e. the
  `MediaWiki:Common.js` dark-mode toggle relocation. DarkMode 0.2.1 still uses
  the same `.ext-darkmode-link` and `client-darkmode` hooks that script relies on.

## Known content issues carried over (not upgrade-related)
- About 765–900 wanted files exist on neither source wiki (per the release
  CHANGELOG).
- Map-marker templates show `Expression error` when coordinates are missing
  (for example *Killagurg's Arena*).
- `Special:RecentChanges` is empty, because the import didn't populate
  `recentchanges`, and it returns HTTP 404 for that reason.

# ACCPP offline release v1.1 (November 2026)

v1.1 is `accppwiki-docker-mediawiki-1.46-offline-release-v1.1.7z`, the
successor to the v1.0 this wiki was built from. It's mainly a software upgrade
(MediaWiki 1.46.2, PHP 8.5.12, MariaDB 12.3.3). This wiki stays on 1.43 LTS and
takes only the content change and one web server fix.

## Content

The only change from v1.0 is three links in `Template:News`, which
`deploy/apply-content-v1.1.sh` applies. This was checked against the live wiki
rather than taken from v1.1's changelog:

- **Pages:** all 123,867 have the same id, title, latest revision and length,
  except `Template:News`. v1.1 adds one revision, 345342.
- **Files:** all 60,391 database rows match on name, size and SHA-1, and every
  current file in v1.1's archive hashes to those values, with the three
  exceptions below.
- **Not compared:** the 57,828 archived file versions and tables other than
  `page`, `revision` and `image`. v1.1's build script copies images from the
  previous release, and its only new revision is the one above.

Three files that disagree with their database rows, the same way on the live
wiki and in v1.1, so these aren't v1.1 changes:

- `File:Egg?.jpg` is stored on disk as `images/9/94/Egg_.jpg`, so the wiki
  can't find it. To fix it on the VPS:
  `sudo cp -n /srv/wiki/data/images/9/94/Egg_.jpg '/srv/wiki/data/images/9/94/Egg?.jpg'`
- `File:Corsair's Arc Live.jpg` and
  `File:Niarltah (Live Event Creature) Live.jpg` have the wrong size and hash
  in the `image` table. The files serve fine, so these are harmless.

## Software

- **Taken:** v1.1's explicit list of what skips the clean-URL rewrite
  (`deploy/apache-site.conf`).
- **Not taken, already handled here:** the fixes for titles containing `?` and
  for `/wiki/FAQ` showing MediaWiki's own FAQ file, and server-side thumbnails.
- **Not taken, MediaWiki 1.46:** 1.46 is supported only to July 2027, while
  1.43 LTS runs to December 2027. The next step is 1.47 LTS, due around
  November 2026. See "Upgrading MediaWiki" in `deploy/README.md`.
- **Not taken, on purpose:** `MaintenanceShell`, `DataDump`, `ImportDump` and
  `CollapsibleVector`, which v1.1 keeps. v1.1 is also configured for
  localhost: it publishes port 8080 on all interfaces, shows exception details
  and uses a fixed database password. Don't run its stack on the VPS.

`scripts/upgrade-local.sh` still needs v1.0's `docker/seed/`. v1.1's seed
can't replace it, because its database is already on the 1.46 schema. To
rebuild from v1.0, run the script, then make the `Template:News` edit.
