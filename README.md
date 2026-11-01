# wiki.dereth.network

A read-only MediaWiki 1.43 LTS hosting of the Asheron's Call community wiki
(ACCPP Wiki): 30,109 articles, 60,391 images. The text is CC BY-SA 3.0
(see the wiki's Copyrights page); the game images belong to their owners.

This repo is the setup only. The wiki's content (database dump and images,
~3.8 GB) lives outside git, in `data/`. See `deploy/README.md` for where it goes.

- `deploy/`: Dockerfile, compose, Apache/PHP config, and the deployment guide
  (**start with `deploy/README.md`**)
- `LocalSettings.php`: MediaWiki configuration; everything site-specific
  comes from `MW_*` environment variables
- `extensions-custom/`: MenuSidebar (the flyout sidebar menu) and YouTubeTag
- `assets/`: logo and favicon
- `scripts/`: the 1.41 → 1.43 upgrade and a page smoke-test
  (`scripts/check-pages.sh <base-url>`)
- `UPGRADE-NOTES.md`: what changed from the original 1.41 Docker package, and why

Skins: legacy Vector is the default. Citizen (light/dark/auto) is installed
for preview. Add `?useskin=citizen` to any page URL to try it.
