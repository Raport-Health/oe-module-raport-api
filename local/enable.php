<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
if (PHP_SAPI !== 'cli' || getenv('MYSQL_HOST') !== 'mysql') {
    exit(1);
}
$_GET['site'] = 'default';
$ignoreAuth = true;
require '/var/www/localhost/htdocs/openemr/interface/globals.php';
if ($GLOBALS['site_addr_oath'] !== 'https://localhost:19443') {
    throw new RuntimeException('This harness may only modify its disposable localhost instance.');
}
$module = 'oe-module-raport-api';
// Fresh installations leave user UUIDs empty; the host's first system-token request otherwise fails.
\OpenEMR\Common\Uuid\UuidRegistry::createMissingUuidsForTables(['users']);
if (!sqlQuery('SELECT mod_id FROM modules WHERE mod_directory = ?', [$module])) {
    sqlStatement('INSERT INTO modules (mod_name, mod_directory, mod_active, mod_ui_name, directory, date, sql_version, acl_version, type) VALUES (?, ?, 1, ?, ?, NOW(), ?, ?, 0)', [
        $module, $module, 'RAPORT API', $module, '', '',
    ]);
} else {
    sqlStatement('UPDATE modules SET mod_active = 1 WHERE mod_directory = ?', [$module]);
}
echo "Local module enabled.\n";
