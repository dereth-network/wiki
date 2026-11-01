#!/usr/bin/env python3
"""Generate resources/dark.css: Citizen dark-mode overrides for the colours
this wiki's templates hard-code.

Templates set their colours inline (style="background:#f9f9f9",
bgcolor="#d0d0d0"), so CSS can only match them by the literal text. This
writes every spelling of every colour below, for both of Citizen's dark
states: the explicit dark theme, and "auto" while the OS is dark.

Only colours that are clearly page furniture are listed. Dye swatches and
other colours that carry meaning are left alone; resources/contrast.js keeps
any text on them readable instead.

After editing, regenerate with:
    python3 build-dark-css.py > resources/dark.css
"""

# Neutral light backgrounds -> Citizen surface tokens, lightest first.
NEUTRAL = {
    "--color-surface-1": ["#fff", "#ffffff", "white", "#fefefe", "#fafafa", "#f9f9f9", "#f8f9fa", "#f8f8f8"],
    "--color-surface-2": ["#f5f5f5", "#f3f3f3", "#f0f0f0", "#eeeeee", "#eee", "#ebebeb", "#e0e0e0"],
    "--color-surface-3": ["#d0d0d0", "#cccccc", "#ccc"],
}

# Tinted light backgrounds (section headers, notes) -> a dark tint of the
# same hue, so e.g. the Quest Summary's red/green/blue bands stay distinct.
TINTED = [
    "#f2cece", "#d1f2ce", "#cedff2",  # Quest Summary / location boxes
    "#dccef2", "#f2f1ce", "#cee1f2",  # NPC boxes
    "#eee8aa",                        # notes and letters
    "#ffffcc",                        # highlighted rows
    # Category header bands (Home's "Popular Topics", item-type sections)
    "#cca3a3", "#ccc5a3", "#b1cca3", "#a3becc", "#c5a3cc", "#ccb6a3",
]

# Inline text colours that are only readable on a light background.
DARK_TEXT = ["#000", "#000000", "black", "#111", "#111111", "#222", "#222222", "#333", "#333333"]

# Classes styled in MediaWiki:Common.css (and MenuSidebar.css), as
# (selectors, declarations). Common.css isn't !important, and with the
# dark-state prefix these selectors are more specific, so plain
# declarations win.
CLASS_RULES = [
    (["table.wikitable", "table.prettytable", ".creaturetooltip"],
     "background-color: var(--color-surface-1); border-color: var(--border-color-base);"),
    (["table.wikitable > * > tr > th", "table.wikitable > * > tr > td",
      "table.prettytable > * > tr > th", "table.prettytable > * > tr > td"],
     "border-color: var(--border-color-base);"),
    (["table.wikitable th", "table.wikitable .hl3", "table.prettytable th", "table.prettytable .hl3"],
     "background-color: color-mix(in srgb, #d6e0f5 18%, var(--color-surface-2));"),
    (["table.wikitable .hl1", "table.prettytable .hl1"],
     "background-color: color-mix(in srgb, #c5d8fc 22%, var(--color-surface-2));"),
    (["table.wikitable .hl2", "table.prettytable .hl2"],
     "background-color: color-mix(in srgb, #a7c1f2 26%, var(--color-surface-2));"),
    ([".template-documentation"],
     "background-color: color-mix(in srgb, #ecfcf4 12%, var(--color-surface-1)); border-color: var(--border-color-base);"),
    ([".yui-navset .yui-nav li"], "background-color: var(--color-surface-2);"),
    ([".yui-content", ".yui-navset .yui-nav li.selected"], "background-color: var(--color-surface-1);"),
    (["ol.references > li:target", "sup.reference:target", "cite:target"],
     "background-color: color-mix(in srgb, var(--color-progressive) 25%, transparent);"),
    ([".item_textbox"],
     "background-color: color-mix(in srgb, #e6dd80 18%, var(--color-surface-1)); color: var(--color-base);"),
    ([".contentsbar"], "background-color: color-mix(in srgb, #d9c7a3 22%, var(--color-surface-1));"),
    # Template:Dialog chat colours, and buffed/debuffed stats
    (["span.say"], "color: var(--color-base);"),
    (["span.event"], "color: #6ccf6c;"),
    (["span.buffed"], "color: #7fd94f;"),
    (["span.debuffed"], "color: #ff7070;"),
    # MenuSidebar flyouts
    ([".menuSidebar ul ul"], "background-color: var(--color-surface-1); border-color: var(--border-color-base);"),
    ([".menuSidebar a"], "color: var(--color-progressive);"),
    ([".menuSidebar li:hover", ".menuSidebar a:hover"], "background: var(--color-surface-3);"),
    ([".menuSidebar li.separator a:hover"], "background: transparent;"),
]

# "prop:value" and "prop: value". The space is written as a CSS escape
# because MediaWiki's CSS minifier strips spaces after ':' even inside
# quoted strings. (Six hex digits, so the next character can't be read as
# part of the escape.)
SEPARATORS = (":", ":\\000020")

DARK_STATES = [
    (":root.skin-theme-clientpref-night", None),
    (":root.skin-theme-clientpref-os", "screen and (prefers-color-scheme: dark)"),
]


def background_selectors(colours):
    sels = []
    for c in colours:
        for prop in ("background", "background-color"):
            for sep in SEPARATORS:
                sels.append(f'[style*="{prop}{sep}{c}" i]')
        sels.append(f'[bgcolor="{c}" i]')
    return sels


def text_selectors(colours):
    sels = []
    for c in colours:
        for sep in SEPARATORS:
            # Anchored on ';' or end-of-attribute so "color:#000" doesn't
            # also catch "color:#000080".
            sels.append(f'[style*="color{sep}{c};" i]')
            sels.append(f'[style$="color{sep}{c}" i]')
        sels.append(f'font[color="{c}" i]')
    return sels


def rule(prefix, selectors, body):
    joined = ",\n\t".join(selectors)
    return f"{prefix} :is(\n\t{joined}\n) {{\n\t{body}\n}}\n"


def emit_state(prefix):
    out = []
    for token, colours in NEUTRAL.items():
        out.append(rule(prefix, background_selectors(colours),
                        f"background-color: var({token}) !important;"))
    for c in TINTED:
        out.append(rule(prefix, background_selectors([c]),
                        f"background-color: color-mix(in srgb, {c} 15%, var(--color-surface-1)) !important;"))
    out.append(rule(prefix, text_selectors(DARK_TEXT), "color: var(--color-base) !important;"))
    # <font color="#f9f9f9">___</font> and friends: spacer text coloured to
    # match its box, i.e. meant to be invisible. Keep it matching the box.
    out.append(rule(prefix, ['font[color="#f9f9f9" i]'], "color: var(--color-surface-1) !important;"))
    # Set by theme.js on text whose own colour is too dark for the dark
    # theme: a lighter version of the same hue.
    # (The never-present #dereth-boost lifts this to ID specificity, so it
    # beats the !important text rules above.)
    out.append(f"{prefix} :is(.dereth-lighten, #dereth-boost) {{\n\tcolor: color-mix(in srgb, var(--dereth-orig-color) 40%, white) !important;\n}}\n")
    for selectors, body in CLASS_RULES:
        sels = ",\n".join(f"{prefix} {sel}" for sel in selectors)
        out.append(f"{sels} {{\n\t{body}\n}}\n")
    return "\n".join(out)


def main():
    print("/* Generated by build-dark-css.py -- edit that, not this. */\n")
    for prefix, media in DARK_STATES:
        body = emit_state(prefix)
        if media:
            indented = "\n".join(("\t" + line) if line else line for line in body.split("\n"))
            print(f"@media {media} {{\n{indented}\n}}\n")
        else:
            print(body)


if __name__ == "__main__":
    main()
