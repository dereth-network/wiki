#!/bin/bash
# Brings the wiki's content up to ACCPP's offline release v1.1. The release's
# only content change from v1.0 is three links in Template:News (see
# ../UPGRADE-NOTES.md), so this makes that one edit instead of importing
# v1.1's database, which is on MediaWiki 1.46's schema and can't run on 1.43.
#
# Run it on the VPS, from this directory. It checks hashes before and after,
# stops if the page isn't exactly what it expects, and does nothing if the
# edit is already in.
set -euo pipefail
cd "$(dirname "$0")"

TITLE=Template:News
OLD_SHA1=5c06d448ab4807431623db056db35b25ac5f4b10
NEW_SHA1=1e1920fc9957409a427be529818bc510ddf43be9
SUMMARY="News: add Search the Archive link (search.accpp.net), fix the upload file-request link (from ACCPP offline release v1.1)"

mw() { docker compose --profile admin run --rm -T admin php maintenance/run.php "$@"; }
sha1() { printf '%s' "$1" | sha1sum | cut -d' ' -f1; }

# $(...) drops getText's trailing newline, if any; MediaWiki trims it on save.
current=$(mw getText "$TITLE")
case "$(sha1 "$current")" in
	"$NEW_SHA1")
		echo "$TITLE already has the v1.1 text; nothing to do."
		exit 0
		;;
	"$OLD_SHA1") ;;
	*)
		echo "$TITLE isn't the v1.0 text (it may have been edited here); not touching it." >&2
		exit 1
		;;
esac

updated=$(printf '%s' "$current" | sed \
	-e 's|^\* \[https://mega\.nz/#F!L1MniCKJ!1dQCCFPc2ddcFILa_JGeZw Browse the Archive\]$|* [https://search.accpp.net/ Search the Archive]\n* [https://mega.nz/#F!L1MniCKJ!1dQCCFPc2ddcFILa_JGeZw Browse the Archive on MEGA]|' \
	-e 's|^\* \[https://mega\.nz/filerequest/qd76QLk0U6 Upload|* [https://mega.nz/filerequest/qd76QLk0U68 Upload|')
if [ "$(sha1 "$updated")" != "$NEW_SHA1" ]; then
	echo "The patched text doesn't match the v1.1 hash; not saving it." >&2
	exit 1
fi

printf '%s' "$updated" | mw edit --summary="$SUMMARY" "$TITLE"
# $wgJobRunRate is 0, so pages using the template only get marked for
# re-rendering when the jobs run.
mw runJobs
# Drops the public instance's parser cache, which lives in APCu.
docker compose restart web

saved=$(mw getText "$TITLE")
if [ "$(sha1 "$saved")" != "$NEW_SHA1" ]; then
	echo "Saved, but the page's hash isn't the expected one; check $TITLE." >&2
	exit 1
fi
echo "Done. Now run ./backup.sh and purge the Cloudflare cache."
