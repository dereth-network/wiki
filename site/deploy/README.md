# Deploying wiki.dereth.network

A read-only MediaWiki 1.43 LTS on a VPS, published through the Cloudflare
Tunnel the VPS already runs for hub.dereth.network. The wiki is just one more
hostname on that tunnel: no new connector, no inbound ports, and the hub
itself isn't touched.

```
visitor ──HTTPS──▶ Cloudflare edge (TLS, cache, bot filtering)
                        │  the VPS's existing tunnel
                        ▼
      cloudflared (already on the VPS) ──┬──▶ hub (unchanged)
                                         └──▶ 127.0.0.1:8088
                                               web (Apache + MediaWiki,
                                               read-only, SELECT-only DB user)
                                   │  internal network, no internet
                                   ▼
                                  db (MariaDB 11.4)

              admin (read-write MediaWiki, started only when you need it,
                     127.0.0.1:8089, reached over an SSH tunnel)
```

Why this setup, briefly:
- **The public site can't change anything, even if it gets compromised.**
  MediaWiki runs with `$wgReadOnly` set, login and account creation are
  switched off entirely, and its database user only has `SELECT`.
- **Editing happens on the `admin` service**, which only listens on the VPS's
  loopback interface. Its login page never reaches the internet.
- **Code is baked into the image.** The only mounts are the uploaded images and
  a cache volume. Apache won't execute anything under `images/`.

## What you need

- Docker Engine with the compose plugin on the VPS
  (`curl -fsSL https://get.docker.com | sh` on Debian/Ubuntu).
- About **12 GB of disk**: 3.5 GB images, about 3 GB database, room for
  thumbnails and the images themselves. About **1.5 GB of free RAM**.
- dereth.network on Cloudflare (it already is).

## 1. Copy everything to the VPS

From the machine that has the upgraded wiki:

```sh
ssh vps 'sudo mkdir -p /srv/wiki && sudo chown $USER /srv/wiki'
rsync -a --info=progress2 site/ vps:/srv/wiki/site/
rsync -a --info=progress2 data/ vps:/srv/wiki/data/    # ~3.8 GB
```

`data/` must contain `mediawiki-1.43.sql.gz` and `images/`. The
`.secret_key` file in `site/` is only for local testing; production takes its
secret from `.env`.

Then set ownership on the VPS. The original images stay owned by root, so
even a compromised web process can't change them. Only the directories
MediaWiki writes thumbnails and scratch files to belong to the container's
`www-data` user (uid 33):

```sh
cd /srv/wiki/data/images
sudo chown -R root:root .
sudo mkdir -p thumb temp lockdir
sudo chown -R 33:33 thumb temp lockdir
```

If thumbnails show "Error creating thumbnail: Unable to save thumbnail to
destination", one of those three directories isn't owned by uid 33.

## 2. Configure and start

```sh
cd /srv/wiki/site/deploy
./gen-env.sh          # writes .env with random DB passwords and secret key
$EDITOR .env          # set DATA_DIR=/srv/wiki/data (leave CLOUDFLARE_TUNNEL_TOKEN empty)
docker compose build --pull   # --pull: always the latest PHP/MediaWiki patch build
docker compose up -d
docker compose logs -f db     # the first start imports the dump; wait for "ready for connections"
```

The first start imports the database, which takes roughly 10–30 minutes on
a small VPS. `web` waits until it's done. Later starts take seconds.
**Keep `.env`**: the DB passwords in it are written into the database on
that first start.

Check it locally on the VPS before going through Cloudflare:
`curl -sI http://127.0.0.1:8088/wiki/Home` should return `200`.

## 3. Add wiki.dereth.network to the existing tunnel

Don't create a new tunnel or install another connector. The hub's
cloudflared can route any number of hostnames. First find out how that
tunnel is managed:

```sh
systemctl cat cloudflared | grep ExecStart
```

**If `ExecStart` contains `--token`**, the tunnel is managed in the
dashboard. Go to **Zero Trust → Networks → Tunnels**, open the hub's
tunnel, and under **Public hostnames → Add a public hostname** enter:

- Subdomain `wiki`, domain `dereth.network`
- Service type `HTTP`, URL `127.0.0.1:8088`

Save it. Cloudflare creates the DNS record, and the running connector picks
up the change by itself, with no restart.

**If it points at a config file instead** (`--config /etc/cloudflared/config.yml`,
or `tunnel run` with no token), the tunnel is managed locally. Add a rule
to `ingress:` **above** the final catch-all (`- service: http_status:404`):

```yaml
ingress:
  - hostname: hub.dereth.network        # already there
    service: http://localhost:...
  - hostname: wiki.dereth.network       # add this
    service: http://127.0.0.1:8088
  - service: http_status:404            # must stay last
```

Then create the DNS record and restart:

```sh
sudo cloudflared --config /etc/cloudflared/config.yml ingress validate
sudo cloudflared tunnel route dns <tunnel-name-or-id> wiki.dereth.network
sudo systemctl restart cloudflared
```

`<tunnel-name-or-id>` is the `tunnel:` value at the top of `config.yml`.

Then open https://wiki.dereth.network/.

**If you already created a separate "wiki" tunnel in the dashboard** while
trying the old instructions, delete it, or at least remove its
`wiki.dereth.network` public hostname, and delete the `wiki` DNS record it
made. Otherwise Cloudflare refuses to add the hostname to the hub's tunnel,
because the name is already taken.

(The `cloudflared` service in `compose.yml` is only for a box *without* a
tunnel: `docker compose --profile tunnel up -d` with a token in `.env`.
You don't need it.)

## 4. Cloudflare settings (all available on the free plan)

In the dash for dereth.network:

- **SSL/TLS → Edge Certificates → Always Use HTTPS**: on.
- **Caching → Cache Rules → Create rule** ("wiki pages"):
  - If: `Hostname equals wiki.dereth.network` AND
    `Cookie does not contain mediawiki_session`
  - Then: **Eligible for cache**. Edge TTL: **use cache-control header if
    present**. Browser TTL: **respect origin**.

  MediaWiki sends `s-maxage=86400` on anonymous page views, so Cloudflare
  serves each page from its cache for up to a day and the VPS barely sees
  traffic.
- **Security → Bots**: turn on **Bot Fight Mode** and **Block AI bots**
  (AI crawlers are the main load on wikis these days).
- Optional, **Security → WAF → Rate limiting rules** (free plan allows one
  rule): if `Hostname equals wiki.dereth.network` and URI query contains
  `action=`, allow 30 requests per 10 seconds per IP, then block. This covers
  histories and diffs, which are the uncached, expensive pages.

## Editing (the admin instance)

```sh
# on the VPS
docker compose --profile admin up -d admin
# on your machine
ssh -N -L 8089:127.0.0.1:8089 vps
# then browse http://localhost:8089/wiki/ and log in
```

When you're done:

```sh
docker compose --profile admin stop admin
./backup.sh                                  # refreshes data/mediawiki-1.43.sql.gz
```

Then purge the Cloudflare cache (**Caching → Configuration → Purge
Everything**) so visitors see the edits right away. Otherwise they appear
within a day.

**Do this once, before anything else:** reset the passwords of the six
accounts that came with the old database. They still have their original
hashes.

```sh
docker compose --profile admin run --rm admin \
  php maintenance/run.php changePassword --user=<name> --password='<new password>'
# list accounts:
docker compose --profile admin run --rm admin \
  php maintenance/run.php sql --query 'SELECT user_name FROM user'
```

Run maintenance scripts through `admin`, because `web` is read-only and its
DB user can't write.

## Deploying changes

Every change to the setup itself (LocalSettings, extensions, skins, Apache
config, version bumps) goes the same way:

1. Get the new `site/` onto the VPS: `git pull` in `/srv/wiki/site`, or
   `rsync -a site/ vps:/srv/wiki/site/` from your machine. **No
   `--delete`**, or rsync would remove the VPS's `deploy/.env`.
2. Run `./update.sh` in `/srv/wiki/site/deploy`. It rebuilds the image,
   restarts `web`, and runs MediaWiki's schema updater (a no-op unless the
   version changed). The database, images and `.env` aren't touched.
3. Purge the Cloudflare cache if visitors should see the change right away.

Content edits are separate. They go through the admin instance (above) and
don't need a rebuild.

## Upgrading MediaWiki

MediaWiki 1.43 gets security point releases a few times a year
([announcements](https://lists.wikimedia.org/postorius/lists/mediawiki-announce.lists.wikimedia.org/)).
To apply one, change the tag in `Dockerfile`
(`FROM docker.io/library/mediawiki:1.43.N`) and in `compose.yml`
(`image: dereth-wiki:1.43.N`), then deploy as above.

1.43 is supported until about December 2027. Plan the move to the next LTS
(1.47) before then. When you do:

- Re-pin the three extensions in the `Dockerfile` to their `REL1_47` commits,
  and Citizen to a release that supports 1.47.
- Port MenuSidebar and YouTubeTag. By 1.46 MediaWiki had removed
  `wfUrlProtocols()` and the un-namespaced `Html`, `Parser` and `Title`
  classes. ACCPP's v1.1 release patched its copies for this, so use those as
  a reference.

Don't import ACCPP's databases from v1.1 onwards to do this. Their schema is
for 1.46 and can't go back to 1.43, and they'd overwrite any edits made here.
See `../UPGRADE-NOTES.md`.

MariaDB 11.4 LTS is supported until 2029. Bumping its patch version
(`mariadb:11.4.N`) is safe at any time, and `MARIADB_AUTO_UPGRADE` handles
the rest.

## Backups

`./backup.sh` writes a fresh `data/mediawiki-1.43.sql.gz`. After that,
`DATA_DIR` holds everything needed to rebuild the site from scratch, and it's
also what a new deploy imports. The wiki only changes when you edit it, so run
the script after editing sessions and copy `DATA_DIR` somewhere off the VPS:
your own machine, a Hetzner Storage Box, or an R2 bucket (10 GB free).

## Troubleshooting

- `docker compose ps`: `web` should show as healthy.
- `docker compose logs web` for Apache/PHP errors.
- If `curl http://127.0.0.1:8088/wiki/Home` works on the VPS but the public
  URL gives a Cloudflare 502/1033, the problem is the tunnel route. Check
  `journalctl -u cloudflared -n 50` and confirm the hostname's service is
  `http://127.0.0.1:8088`.
- If pages render without styling, `load.php` is failing. Check
  `docker compose logs web` for its errors.
- After fixing anything that made pages render wrong (for example
  thumbnail-directory ownership), run `docker compose restart web` and purge
  the Cloudflare cache. Both still hold the broken renders until they
  expire.
- If the import is slow or dies on a small box, raise `start_period` in
  `compose.yml`, or lower `--innodb-buffer-pool-size` if the VPS is short on
  RAM.
