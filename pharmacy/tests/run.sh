#!/usr/bin/env bash
# Builds the extension, installs it on a clean EspoCRM and MariaDB, and runs every test inside the
# EspoCRM container. Needs Docker, zip and curl. KEEP=1 leaves the stack running afterwards.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
phar_version=12.5.43
phar_sha256=55350db3736f8d42170d9f7989ccd8b8e19ab4ba6be96e8c1c9cbcad430cb6d5

export DB_ROOT_PASSWORD=test-root DB_PASSWORD=test-db ADMIN_PASSWORD=test-admin
export SITE_URL=http://localhost:18491 PORT=18491

# This script runs `down -v`. Its own project name keeps that away from a deployment on this host.
export COMPOSE_PROJECT_NAME=hiresync-test

cd "$root/deploy"

finish() {
    if [[ "${KEEP:-0}" != 1 ]]; then
        docker compose down -v --remove-orphans
    fi
}
trap finish EXIT

docker compose down -v --remove-orphans
docker compose up -d --wait --wait-timeout 300

phar="$root/tests/.phpunit-$phar_version.phar"
if [[ ! -f "$phar" ]]; then
    curl -fsSL -o "$phar" "https://phar.phpunit.de/phpunit-$phar_version.phar"
fi
if [[ "$(sha256sum "$phar" | cut -d' ' -f1)" != "$phar_sha256" ]]; then
    echo "phpunit.phar failed its checksum" >&2
    exit 1
fi

package="$(mktemp -d)/pharmacy-recruitment.zip"
(cd "$root/extension" && zip -qr "$package" manifest.json scripts files)

container="$(docker compose ps -q espocrm)"
docker cp "$package" "$container:/tmp/pharmacy-recruitment.zip"
docker cp "$phar" "$container:/tmp/phpunit.phar"
docker cp "$root/tests" "$container:/tmp/pharmacy-tests"
docker compose exec -T espocrm chown -R www-data:www-data /tmp/pharmacy-recruitment.zip /tmp/pharmacy-tests

in_app() {
    docker compose exec -T -u www-data -e ADMIN_PASSWORD espocrm "$@"
}

in_app php command.php extension --file=/tmp/pharmacy-recruitment.zip
in_app php /tmp/phpunit.phar -c /tmp/pharmacy-tests/phpunit.xml "$@"
