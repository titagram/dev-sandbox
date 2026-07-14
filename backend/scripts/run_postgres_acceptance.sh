#!/usr/bin/env bash
set -euo pipefail

php tests/Support/assert_postgres_acceptance_database.php
php artisan config:clear --ansi
php tests/Support/assert_postgres_acceptance_database.php
php artisan migrate:fresh --force --ansi
vendor/bin/pest --configuration=phpunit.postgres.xml --display-warnings "$@"
