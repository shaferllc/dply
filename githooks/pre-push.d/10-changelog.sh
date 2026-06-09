#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 10-changelog.sh — generate a changelog entry for the commits being pushed.
#
# Analyzes the diff of $DPLY_PUSH_RANGE with the `claude` CLI and prepends an
# entry to resources/views/changelog.blade.php ($entries array) and CHANGELOG.md.
# Writes files only — the pre-push orchestrator commits + ships them.
#
# No-ops (exit 0) when: claude is unavailable, the range is empty, or the model
# returns nothing. Never fails the push for changelog reasons.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

RANGE="${DPLY_PUSH_RANGE:-}"
[ -z "$RANGE" ] && exit 0
command -v claude >/dev/null 2>&1 || { echo "[changelog] claude CLI not found — skipping."; exit 0; }

# Diff of the commits being pushed (stat + patch), truncated for arg-size safety.
diff="$(git diff --stat "$RANGE" 2>/dev/null; printf '\n---\n'; git diff "$RANGE" 2>/dev/null)"
[ -z "${diff// }" ] && exit 0
if [ "${#diff}" -gt 14000 ]; then diff="${diff:0:14000}"$'\n... [truncated]'; fi

prompt="Analyze this git diff and respond with EXACTLY three lines, no markdown, no extra text:
TYPE: <Added|Changed|Fixed|Removed|Security|Deprecated>
TITLE: <short 3-6 word public-facing changelog title, title case>
CHANGELOG: <one concise sentence describing the user-visible change>

${diff}"

output="$(claude -p "$prompt" 2>/dev/null)" || { echo "[changelog] claude failed — skipping."; exit 0; }
TYPE="$(printf '%s' "$output"  | grep '^TYPE:'      | sed 's/^TYPE: *//')"
TITLE="$(printf '%s' "$output" | grep '^TITLE:'     | sed 's/^TITLE: *//')"
ENTRY="$(printf '%s' "$output" | grep '^CHANGELOG:' | sed 's/^CHANGELOG: *//')"
[ -z "$ENTRY" ] && { echo "[changelog] no entry produced — skipping."; exit 0; }

echo "[changelog] [${TYPE:-Changed}] ${TITLE}"

export _DPLY_CL_TYPE="${TYPE:-Changed}" _DPLY_CL_TITLE="$TITLE" _DPLY_CL_ENTRY="$ENTRY"
python3 << 'PYEOF'
import os, re, sys
from datetime import date

type_  = os.environ["_DPLY_CL_TYPE"]
title  = os.environ["_DPLY_CL_TITLE"].strip()
entry  = os.environ["_DPLY_CL_ENTRY"].lstrip("- ").strip()

def php_escape(s):
    return s.replace("\\", "\\\\").replace("'", "\\'")

TAG_MAP = {
    "Added": "new", "Changed": "improved", "Fixed": "fixed",
    "Removed": "improved", "Security": "security", "Deprecated": "improved",
}
tag   = TAG_MAP.get(type_, "improved")
today = date.today()
date_str = f"{today.strftime('%B')} {today.day}, {today.year}"

# 1) changelog.blade.php — prepend into the $entries array.
blade_path = "resources/views/changelog.blade.php"
blade_entry = (
    "\n"
    "                [\n"
    f"                    'date'    => '{date_str}',\n"
    f"                    'tags'    => ['{tag}'],\n"
    f"                    'title'   => '{php_escape(title)}',\n"
    f"                    'summary' => '{php_escape(entry)}',\n"
    "                    'items'   => [],\n"
    "                ],"
)
marker = "$entries = ["
try:
    with open(blade_path) as f:
        blade = f.read()
    if marker in blade:
        idx = blade.index(marker) + len(marker)
        with open(blade_path, "w") as f:
            f.write(blade[:idx] + blade_entry + blade[idx:])
        print(f"  changelog.blade.php: [{tag}] {title}")
    else:
        print(f"  WARNING: $entries not found in {blade_path}", file=sys.stderr)
except FileNotFoundError:
    print(f"  WARNING: {blade_path} not found", file=sys.stderr)

# 2) CHANGELOG.md — Keep a Changelog format under [Unreleased].
md_path = "CHANGELOG.md"
md_line = f"- {entry}"
try:
    with open(md_path) as f:
        md = f.read()
except FileNotFoundError:
    with open(md_path, "w") as f:
        f.write(f"# Changelog\n\n## [Unreleased]\n### {type_}\n{md_line}\n")
    sys.exit(0)

if "## [Unreleased]" in md:
    idx = md.index("## [Unreleased]") + len("## [Unreleased]")
    md = md[:idx] + f"\n### {type_}\n{md_line}" + md[idx:]
else:
    m = re.search(r"\n## ", md)
    pos = m.start() if m else len(md)
    md = md[:pos] + f"\n\n## [Unreleased]\n### {type_}\n{md_line}" + md[pos:]
with open(md_path, "w") as f:
    f.write(md)
print(f"  CHANGELOG.md: [{type_}] {md_line}")
PYEOF

exit 0
