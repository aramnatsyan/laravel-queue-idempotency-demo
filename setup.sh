#!/usr/bin/env bash
# Builds the demo: latest Laravel skeleton in ./app via Docker, then overlays the demo files and runs the tests.
set -euo pipefail
cd "$(dirname "$0")"
[ -d app ] || docker run --rm -v "$PWD":/work -w /work composer:2 create-project laravel/laravel app --prefer-dist --no-interaction
cp -R overlay/. app/
docker run --rm -v "$PWD/app":/app -w /app php:8.4-cli php artisan test
