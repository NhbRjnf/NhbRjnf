#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

BRANCH="work"
REMOTE="origin"
MSG="${1:-sync: update}"

echo "==> checkout $BRANCH"
git checkout "$BRANCH"

echo "==> pull (ff-only)"
git pull --ff-only "$REMOTE" "$BRANCH" || true

# (опционально) собрать логи перед коммитом
if [ -x "./scripts/snapshot-logs.sh" ]; then
  echo "==> snapshot logs"
  ./scripts/snapshot-logs.sh || true
fi

echo "==> stage"
git add -A

if git diff --cached --quiet; then
  echo "==> nothing to commit"
else
  echo "==> commit: $MSG"
  git commit -m "$MSG"
fi

echo "==> push"
git push "$REMOTE" "$BRANCH"

echo "==> verify"
git rev-parse HEAD
git ls-remote "$REMOTE" "refs/heads/$BRANCH"
