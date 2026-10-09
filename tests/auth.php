<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
// Run only inside local/compose.yaml; uses the actual host and OAuth HTTP endpoints.
if (PHP_SAPI !== 'cli' || getenv('MYSQL_HOST') !== 'mysql') {
    exit(1);
}
$_GET['site'] = 'default';
$ignoreAuth = true;
require '/var/www/localhost/htdocs/openemr/interface/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Http\HttpRestParsedRoute;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use Raport\OpenEmr\Bootstrap;
use Raport\OpenEmr\OperationController;
use Symfony\Component\EventDispatcher\EventDispatcher;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
check($GLOBALS['site_addr_oath'] === 'https://localhost:19443', 'disposable instance guard');
require $GLOBALS['fileroot'] . '/version.php';
check([$v_major, $v_minor, $v_patch, $v_realpatch] === ['8', '0', '0', '3'], 'OpenEMR 8.0.0.3');
// Every system client runs as oe-system, so this grant reaches every one of them (README, Permissions and audit).
check(AclMain::aclCheckCore('admin', 'super', 'oe-system'), 'fresh install grants oe-system admin|super');

$dispatcher = new EventDispatcher();
(new Bootstrap())->subscribe($dispatcher);
foreach ([[false, 'fhir', false], [true, 'standard', false], [true, 'fhir', true]] as [$enabled, $api, $expected]) {
    $event = (new RestApiScopeEvent())->setApiType($api)->setSystemScopesEnabled($enabled);
    $dispatcher->dispatch($event, RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES);
    check(in_array(Bootstrap::SCOPE, $event->getScopes(), true) === $expected, "scope registration: $api / system=" . (int) $enabled);
    check(in_array(Bootstrap::VISITS_SCOPE, $event->getScopes(), true) === $expected, "visits scope registration: $api / system=" . (int) $enabled);
    check(in_array(Bootstrap::PROBLEMS_SCOPE, $event->getScopes(), true) === $expected, "problems scope registration: $api / system=" . (int) $enabled);
    check(in_array(Bootstrap::LABS_SCOPE, $event->getScopes(), true) === $expected, "labs scope registration: $api / system=" . (int) $enabled);
}
$encounter = '00000000-0000-4000-8000-000000000001';
$route = new HttpRestParsedRoute('GET', '/fhir/Encounter/' . $encounter . '/$raport-document', Bootstrap::ROUTE);
check($route->isValid() && $route->getResource() === 'Encounter' && $route->getOperation() === '$raport-document' && $route->getInstanceIdentifier() === $encounter, 'host parses instance operation');
// No resource, so the host checks the operation against scope resource '*'.
$route = new HttpRestParsedRoute('GET', '/fhir/$raport-visits', Bootstrap::VISITS_ROUTE);
check($route->isValid() && $route->getResource() === null && $route->getOperation() === '$raport-visits' && $route->getInstanceIdentifier() === null, 'host parses system operation');
$route = new HttpRestParsedRoute('GET', '/fhir/Patient/' . $encounter . '/$raport-problems', Bootstrap::PROBLEMS_ROUTE);
check($route->isValid() && $route->getResource() === 'Patient' && $route->getOperation() === '$raport-problems' && $route->getInstanceIdentifier() === $encounter, 'host parses Patient instance operation');
$request = new HttpRestRequest();
$request->setRequestUserRole('users');
check((new OperationController())->document($encounter, $request)->getStatusCode() === 403, 'controller rejects user context');
$request->setRequestUserRole('patient');
check((new OperationController())->document($encounter, $request)->getStatusCode() === 403, 'controller rejects patient context');
$request->setRequestUserRole('system');
$request->setIsLocalApi(true);
check((new OperationController())->document($encounter, $request)->getStatusCode() === 403, 'controller rejects browser-session local API');
$request = new HttpRestRequest();
$request->setRequestUserRole('users');
check((new OperationController())->visits($request)->getStatusCode() === 403, 'visits controller rejects user context');
$request->setRequestUserRole('patient');
check((new OperationController())->visits($request)->getStatusCode() === 403, 'visits controller rejects patient context');
$request->setRequestUserRole('system');
$request->setIsLocalApi(true);
check((new OperationController())->visits($request)->getStatusCode() === 403, 'visits controller rejects browser-session local API');
$request = new HttpRestRequest();
$request->setRequestUserRole('users');
check((new OperationController())->problems($encounter, $request)->getStatusCode() === 403, 'problems controller rejects user context');
$request->setRequestUserRole('patient');
check((new OperationController())->problems($encounter, $request)->getStatusCode() === 403, 'problems controller rejects patient context');
$request->setRequestUserRole('system');
$request->setIsLocalApi(true);
check((new OperationController())->problems($encounter, $request)->getStatusCode() === 403, 'problems controller rejects browser-session local API');
foreach (['users', 'patient', 'system'] as $role) {
    $request = new HttpRestRequest();
    $request->setRequestUserRole($role);
    $request->setIsLocalApi($role === 'system');
    check((new OperationController())->labs($encounter, $request)->getStatusCode() === 403, 'labs controller rejects ' . $role . ' non-OAuth context');
}

function callApi(string $path, ?string $body = null, string $contentType = 'application/json', ?string $token = null): array
{
    $curl = curl_init('https://localhost:19443' . $path);
    $headers = ['Accept: application/fhir+json', 'Content-Type: ' . $contentType];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECT_TO => ['localhost:19443:127.0.0.1:443'],
        CURLOPT_CAINFO => '/etc/ssl/certs/selfsigned.cert.pem',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($curl);
    if ($result === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    return [$status, $result];
}
function b64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function tokenFor(string $clientId, OpenSSLAsymmetricKey $key, string $scope, int $expiry = 60): array
{
    $header = b64(json_encode(['alg' => 'RS384', 'typ' => 'JWT', 'kid' => 'local-proof'], JSON_THROW_ON_ERROR));
    $claims = b64(json_encode(['iss' => $clientId, 'sub' => $clientId,
        'aud' => 'https://localhost:19443/oauth2/default/token', 'iat' => time() - 1,
        'exp' => time() + $expiry, 'jti' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
    if (!openssl_sign("$header.$claims", $signature, $key, OPENSSL_ALGO_SHA384)) {
        throw new RuntimeException('Could not sign local client assertion.');
    }
    [$status, $body] = callApi('/oauth2/default/token', http_build_query([
        'grant_type' => 'client_credentials', 'client_id' => $clientId, 'scope' => $scope,
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => "$header.$claims." . b64($signature),
    ]), 'application/x-www-form-urlencoded');
    return [$status, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$public = openssl_pkey_get_details($key)['rsa'];
$moduleScopes = implode(' ', ['api:fhir', Bootstrap::SCOPE, Bootstrap::VISITS_SCOPE, Bootstrap::PROBLEMS_SCOPE, Bootstrap::LABS_SCOPE]);
[$status, $body] = callApi('/oauth2/default/registration', json_encode([
    'application_type' => 'private', 'client_name' => 'RAPORT disposable module check',
    'redirect_uris' => ['https://localhost:19443/unused'], 'contacts' => [],
    'grant_types' => ['client_credentials'], 'response_types' => [],
    'token_endpoint_auth_method' => 'private_key_jwt',
    // The module scopes plus the built-in Encounter read scope used below.
    'scope' => 'system/Encounter.rs ' . $moduleScopes,
    'jwks' => ['keys' => [['kty' => 'RSA', 'alg' => 'RS384', 'use' => 'sig', 'kid' => 'local-proof',
        'n' => b64($public['n']), 'e' => b64($public['e'])]]],
], JSON_THROW_ON_ERROR));
check($status === 200, 'dynamic client registration accepts module scope (HTTP ' . $status . ')');
$clientId = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['client_id'];
try {
    // Test-only equivalent of an administrator enabling this newly registered app.
    sqlStatement('UPDATE oauth_clients SET is_enabled = 1 WHERE client_id = ?', [$clientId]);
    [$status, $tokenResult] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::SCOPE);
    check($status === 200, 'private-key JWT token issued (HTTP ' . $status . ')');
    $token = $tokenResult['access_token'];
    $path = '/apis/default/fhir/Encounter/' . $encounter . '/$raport-document';
    [$status, $body] = callApi($path, token: $token);
    $outcome = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check($status === 404 && $outcome['resourceType'] === 'OperationOutcome' && $outcome['issue'][0]['code'] === 'not-found', 'authorized request reports unknown encounter');
    [$status] = callApi('/apis/default/fhir/Encounter/not-a-uuid/$raport-document', token: $token);
    check($status === 400, 'invalid encounter UUID rejected');
    [$status] = callApi($path);
    check($status === 401, 'missing token rejected');
    [$status] = callApi($path, token: 'invalid');
    check($status === 401, 'malformed token rejected');
    [$status, $readToken] = tokenFor($clientId, $key, 'api:fhir system/Encounter.rs');
    check($status === 200, 'ordinary Encounter read token issued');
    [$status] = callApi($path, token: $readToken['access_token']);
    check($status === 401, 'Encounter read scope does not grant document operation');
    [$status, $combined] = tokenFor($clientId, $key, 'api:fhir system/Encounter.rs ' . Bootstrap::SCOPE);
    check($status === 200, 'combined encounter read and export token issued');
    [$status] = callApi($path, token: $combined['access_token']);
    check($status === 404, 'combined scope token reaches document operation');
    [$status] = callApi('/apis/default/fhir/Encounter', token: $combined['access_token']);
    check($status === 200, 'combined scope token retains built-in Encounter search');
    require __DIR__ . '/export.php';
    require __DIR__ . '/visits.php';
    require __DIR__ . '/problems.php';
    require __DIR__ . '/labs.php';
    [$status] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::SCOPE, -60);
    check(in_array($status, [400, 401], true), 'expired client assertion rejected (HTTP ' . $status . ')');
    [$status] = callApi('/apis/nonexistent-site/fhir/Encounter/' . $encounter . '/$raport-document', token: $token);
    check($status >= 400 && $status < 500, 'unknown site rejected');
    // A disabled module no longer grants its scope, so this token must be issued first.
    [$status, $visitsToken] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::VISITS_SCOPE);
    check($status === 200, 'visits token issued before disabling module');
    [$status, $labsToken] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::LABS_SCOPE);
    check($status === 200, 'labs token issued before disabling module');
    sqlStatement('UPDATE modules SET mod_active = 0 WHERE mod_directory = ?', ['oe-module-raport-api']);
    [$status] = callApi($path, token: $token);
    check($status === 404, 'disabling module removes operation');
    [$status] = callApi('/apis/default/fhir/$raport-visits', token: $visitsToken['access_token']);
    check($status === 404, 'disabling module removes visits operation');
    [$status] = callApi('/apis/default/fhir/Patient/' . $encounter . '/$raport-labs', token: $labsToken['access_token']);
    check($status === 404, 'disabling module removes labs operation');
    [$status, $refused] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::LABS_SCOPE);
    check($status === 400 && $refused['error'] === 'invalid_scope', 'disabled module labs scope refused');
    [$status, $refused] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::VISITS_SCOPE);
    check($status === 400 && $refused['error'] === 'invalid_scope', 'disabled module visits scope refused for a new token (HTTP ' . $status . ')');
    [$status] = callApi('/apis/default/fhir/metadata');
    check($status === 200, 'built-in FHIR metadata still works');
    sqlStatement('UPDATE modules SET mod_active = 1 WHERE mod_directory = ?', ['oe-module-raport-api']);
    sqlStatement('UPDATE api_token SET expiry = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE client_id = ?', [$clientId]);
    [$status] = callApi($path, token: $token);
    check($status === 401, 'server-expired access token rejected');
    [$status] = callApi('/interface/modules/custom_modules/oe-module-raport-api/local/.env');
    check($status === 403, 'module files cannot be downloaded through Apache');
} finally {
    sqlStatement('UPDATE modules SET mod_active = 1 WHERE mod_directory = ?', ['oe-module-raport-api']);
    // Leave audit records; revoke the disposable client and its tokens.
    sqlStatement('UPDATE oauth_clients SET is_enabled = 0 WHERE client_id = ?', [$clientId]);
    sqlStatement('UPDATE api_token SET revoked = 1 WHERE client_id = ?', [$clientId]);
}
