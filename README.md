# wiki.dereth.network

A read-only MediaWiki 1.43 LTS hosting of the Asheron's Call community wiki
(ACCPP Wiki): 30,109 articles, 60,391 images. The text is CC BY-SA 3.0
(see the wiki's Copyrights page); the game images belong to their owners.

```
site/   the setup, in git
data/   the wiki's content: mediawiki-1.43.sql.gz + images/ (~3.8 GB),
        gitignored and backed up separately
```

On the VPS this repo is cloned to `/srv/wiki`, with the content in
`/srv/wiki/data`. **Start with `site/deploy/README.md`.**

- `site/deploy/`: Dockerfile, compose, Apache/PHP config, and the deployment guide
- `site/LocalSettings.php`: MediaWiki configuration; everything site-specific
  comes from `MW_*` environment variables
- `site/extensions-custom/`: MenuSidebar (the flyout sidebar menu), YouTubeTag,
  and DerethTheme (the dereth.network look for Citizen, plus dark-mode
  colours for the templates, wide-table scrolling and footer text;
  regenerate its `dark.css` with `build-dark-css.py`)
- `site/assets/`: logo and favicon
- `site/scripts/`: the 1.41 → 1.43 upgrade and a page smoke-test
  (`site/scripts/check-pages.sh <base-url>`)
- `site/UPGRADE-NOTES.md`: what changed from the original 1.41 Docker
  package, and why

Skin: Citizen, styled after dereth.network (dark by default, with a
parchment light theme in Citizen's preferences menu). The old look (legacy
Vector) is still available by adding `?useskin=vector` to any page URL.

## License

Everything in this repo is public domain ([Unlicense](LICENSE)): do
whatever you want with it, no credit needed. Except:

- `site/extensions-custom/MenuSidebar/`: a port of Wolfgang Stöttinger's
  CSS MenuSidebar extension, GPL-2.0-or-later.
- `site/extensions-custom/YouTubeTag/`: from the ACCPP release,
  GPL-2.0-or-later.
- `site/assets/`: the ACCPP logo and favicon, which belong to ACCPP.
- `site/extensions-custom/DerethTheme/resources/fonts/`: Cinzel and
  EB Garamond, SIL Open Font License (see the `OFL-*.txt` files there).
- `site/extensions-custom/DerethTheme/resources/art/`: artwork from
  dereth.network.
- The wiki's content (not in this repo) is CC BY-SA 3.0, and the game's
  images belong to their owners.
