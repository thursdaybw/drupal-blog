#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
git config core.hooksPath scripts/git-hooks
echo "Configured git hooks path: $(git config --get core.hooksPath)"
