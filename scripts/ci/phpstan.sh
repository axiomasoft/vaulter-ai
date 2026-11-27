#!/usr/bin/env bash
# Acceptance runs several `composer phpstan` processes at once. They share
# build/phpstan/resultCache.php, and PHPStan exits 1 when that file changes
# while it is being read. Hold one exclusive lock for the whole analyse.
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p build
exec flock -w 900 build/phpstan.lock vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G --no-progress
