#!/bin/sh
# SPDX-License-Identifier: MIT
# Disposable, localhost-only harness. Never use this entrypoint for a clinic.
set -eu
cd /var/www/localhost/htdocs/openemr
if [ ! -d sites/default ]; then
    cp -R /swarm-pieces/sites/. sites/
fi
. /root/devtoolsLibrary.source
prepareVariables
if [ "$(php -r 'require "sites/default/sqlconf.php"; echo $config;')" = 0 ]; then
    # Host installer, without the release entrypoint's whole-tree chmod/copy-up.
    php auto_configure.php -f $CONFIGURATION > /module-local/artifacts/install.log 2>&1
fi
setGlobalSettings
chown -R apache:apache sites
find sites -type d -exec chmod 700 {} +
find sites -type f -exec chmod 600 {} +
sh ssl.sh > /module-local/artifacts/tls.log 2>&1
exec /usr/sbin/httpd -D FOREGROUND
