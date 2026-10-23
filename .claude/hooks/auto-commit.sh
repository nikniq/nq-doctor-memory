#!/usr/bin/env bash
# Claude Code Stop hook: commit and push anything left uncommitted at the end
# of a turn, so every change reaches GitHub. Claude normally commits each
# change itself with a descriptive message; this is the safety net.
#
# Pause it (e.g. while a background workflow is mid-edit):
#   touch .git/auto-commit.pause      # resume: rm .git/auto-commit.pause

cd "$(dirname "$0")/../.." || exit 0
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0
[ -f .git/auto-commit.pause ] && exit 0
[ -z "$(git status --porcelain)" ] && exit 0

# The server has no Node, so committed assets must match the sources.
if git status --porcelain | grep -qE '^.. "?resources/(js|css)/'; then
    npm run build --silent >/dev/null 2>&1 || true
fi

git add -A
count=$(git diff --cached --name-only | wc -l | tr -d ' ')
files=$(git diff --cached --name-only | head -4 | paste -sd ',' - | sed 's/,/, /g')
[ "$count" -gt 4 ] && files="$files, …"

git commit -q -m "Auto-commit: $count file(s) changed" -m "$files" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" || exit 0

if git push -q origin HEAD >/dev/null 2>&1; then
    echo '{"systemMessage":"Auto-committed and pushed leftover changes to GitHub."}'
else
    echo '{"systemMessage":"Auto-committed leftover changes, but git push failed. Run git push."}'
fi
