#!/bin/sh
# SPDX-License-Identifier: MIT
set -eu
cd "$(dirname "$0")/.."
compose="docker compose -f local/compose.yaml"
$compose exec -T openemr mkdir /tmp/raport-harness.lock || { echo 'harness busy: /tmp/raport-harness.lock exists in the openemr container' >&2; exit 1; }
trap "$compose exec -T openemr rmdir /tmp/raport-harness.lock" EXIT
$compose exec -T openemr sh -eu -c '
module=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-raport-api
composer validate --strict --no-check-publish "$module/composer.json"
for file in "$module"/openemr.bootstrap.php "$module"/src/*.php "$module"/tests/*.php "$module"/local/*.php; do
    php -l "$file"
done
php "$module/local/enable.php"
php "$module/tests/auth.php"
'
