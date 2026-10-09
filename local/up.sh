#!/bin/sh
# SPDX-License-Identifier: MIT
set -eu
cd "$(dirname "$0")/.."
umask 077
mkdir -p local/artifacts/database local/artifacts/sites
if [ ! -f local/.env ]; then
    printf 'LOCAL_DB_PASSWORD=%s\nLOCAL_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > local/.env
fi
docker compose -f local/compose.yaml up -d
echo 'Local instance starting at https://localhost:19443 (self-signed local certificate).'
echo 'After installation completes, run: sh local/check.sh'
