#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

ddev exec ./vendor/bin/phpcs --standard=phpcs.xml.dist "$@"
