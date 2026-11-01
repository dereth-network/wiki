#!/bin/bash
# Smoke-test a running wiki: fetch sample pages and flag render problems.
#   scripts/check-pages.sh [base-url] [title ...]
set -u
BASE="${1:-http://127.0.0.1:8099}"
shift || true
PAGES=("$@")
if [ ${#PAGES[@]} -eq 0 ]; then
  PAGES=(
    Home
    "50-100_Contracts_Quest"            # Template:Quest Summary
    "Canon_of_the_Eldrytch_Web"         # Template:Dialog
    "A_Society_Axe"                     # Template:Melee Weapon
    "A'mun_Blight_Lord"                 # Template:Creature
    "Clothing"                          # #expr + PAGESINCATEGORY
    "Category:Axe"
    "File:0062.png"
    "Lore_Videos/Shedding_Skin"         # <youtube>
    "Special:Version"
    "Special:RecentChanges"
  )
fi

fail=0
for p in "${PAGES[@]}"; do
  out=$(mktemp)
  code=$(curl -s -o "$out" -w '%{http_code} %{time_total}' "$BASE/wiki/$(printf %s "$p" | sed 's/ /_/g; s/?/%3F/g')")
  issues=()
  grep -q 'Expression error' "$out" && issues+=("expression-error")
  grep -q 'class="scribunto-error' "$out" && issues+=("lua-error")
  grep -qiE 'Fatal error|MWException|Internal error|\[[0-9a-f]{20,}\]' "$out" && issues+=("exception")
  grep -q '{{#' "$out" && issues+=("raw-parserfunction")
  n=$(grep -oE 'class="new" title="Template:[^"]*' "$out" | sort -u | wc -l)
  [ "$n" -gt 0 ] && issues+=("template-redlinks:$n")
  grep -q 'menuSidebar' "$out" || issues+=("no-menusidebar")
  [ "${code%% *}" = 200 ] || issues+=("http-${code%% *}")
  printf '%-34s %s  %s\n' "$p" "$code" "${issues[*]:-ok}"
  [ ${#issues[@]} -gt 0 ] && fail=1
  rm -f "$out"
done
exit $fail
