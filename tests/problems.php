<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
// Included by auth.php while its disposable OAuth client is enabled.
if (PHP_SAPI !== 'cli' || !isset($token) || $GLOBALS['site_addr_oath'] !== 'https://localhost:19443') {
    exit(1);
}

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Gacl\GaclApi;
use Raport\OpenEmr\Bootstrap;

function problemsCall(string $token, string $patient, int $expected, string $label, string $query = ''): array
{
    [$status, $body] = callApi('/apis/default/fhir/Patient/' . $patient . '/$raport-problems' . $query, token: $token);
    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $shape = $expected === 200
        ? $result['parameter'][0] === ['name' => 'complete', 'valueBoolean' => true]
        : $result['resourceType'] === 'OperationOutcome' && !isset($result['parameter']);
    check($status === $expected && $shape, "$label: HTTP $status" . ($status !== $expected ? ' (' . ($result['issue'][0]['diagnostics'] ?? 'no diagnostics') . ')' : ''));
    return $result;
}
// uuid => part name => value, in response order; code and encounter parts stay lists.
function problemRows(array $result): array
{
    $rows = [];
    foreach (array_slice($result['parameter'], 1) as $parameter) {
        $values = ['code' => [], 'encounter' => []];
        foreach ($parameter['part'] as $part) {
            match ($part['name']) {
                'code' => $values['code'][] = array_column($part['part'], 'valueString', 'name'),
                'encounter' => $values['encounter'][] = $part['valueString'],
                default => $values[$part['name']] = $part['valueString'],
            };
        }
        $rows[$values['uuid']] = $values;
    }
    return $rows;
}

$p1 = '40000000-0000-4000-8000-000000000001';
$p2 = '40000000-0000-4000-8000-000000000002';
$unknownPatient = '40000000-0000-4000-8000-000000000003';
$fixtureRows = "SELECT pid FROM patient_data WHERE pid IN (930001, 930002) OR uuid IN (?, ?, ?)
    UNION ALL SELECT id FROM lists WHERE id BETWEEN 930001 AND 930010 OR pid IN (930001, 930002)
    UNION ALL SELECT id FROM issue_encounter WHERE pid IN (930001, 930002) OR list_id BETWEEN 930001 AND 930010
    UNION ALL SELECT id FROM form_encounter WHERE id IN (930001, 930002) OR encounter IN (930101, 930102)
    UNION ALL SELECT dx_id FROM icd10_dx_order_code WHERE formatted_dx_code IN ('U99.91', 'U99.92')";
$fixtureBinds = array_map(fn($uuid) => UuidRegistry::uuidToBytes($uuid), [$p1, $p2, $unknownPatient]);
check(!sqlQuery($fixtureRows, $fixtureBinds), 'problems fixtures are unused');
[$status, $problemsToken] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::PROBLEMS_SCOPE);
check($status === 200, 'PR0 problems token issued');
$problemsToken = $problemsToken['access_token'];
[$status] = callApi('/apis/default/fhir/Patient/' . $p1 . '/$raport-problems', token: $token);
check($status === 401, 'PR0 document scope does not grant problems operation');
$gacl = new GaclApi();
$groups = $gacl->get_object_groups($gacl->get_object_id('users', 'oe-system', 'ARO'), 'ARO', 'NO_RECURSE');
$testAcl = null;
$testGroup = null;
$apiLogOption = sqlQuery('SELECT gl_value FROM globals WHERE gl_name = ?', ['api_log_option'])['gl_value'];
try {
    foreach ([[930001, $p1, 'P1'], [930002, $p2, 'P2']] as [$pid, $uuid, $name]) {
        sqlStatement('INSERT INTO patient_data (pid, uuid, pubpid, fname, lname, DOB) VALUES (?, ?, ?, ?, ?, ?)', [$pid, UuidRegistry::uuidToBytes($uuid), 'RAPORT-PROBLEMS-' . $name, $name, 'Synthetic Patient', '2000-01-01']);
    }
    // Synthetic ICD-10 rows, so the host's description lookup has something to find.
    foreach ([['U99.91', 'Synthetic first code'], ['U99.92', 'Synthetic second code']] as [$code, $description]) {
        sqlStatement("INSERT INTO icd10_dx_order_code (dx_code, formatted_dx_code, valid_for_coding, short_desc, long_desc, active, revision) VALUES (?, ?, '1', ?, ?, 1, 1)", [str_replace('.', '', $code), $code, $description, $description]);
    }
    sqlStatement('INSERT INTO form_encounter (id, pid, encounter, date, reason) VALUES (930001, 930001, 930101, ?, ?), (930002, 930002, 930102, ?, ?)', ['2031-02-01 09:00:00', 'Synthetic P1 visit', '2031-02-01 10:00:00', 'Synthetic P2 visit']);
    // Problem UUIDs start NULL so the operation's backfill is exercised.
    $addProblem = fn(int $id, int $pid, string $title, ?string $diagnosis, ?string $begdate = null, ?string $enddate = null, int $activity = 1, string $type = 'medical_problem') => sqlStatement(
        'INSERT INTO lists (id, pid, type, title, diagnosis, begdate, enddate, activity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $pid, $type, $title, $diagnosis, $begdate, $enddate, $activity],
    );
    $addProblem(930001, 930001, 'Synthetic coded problem', 'ICD10:U99.92; ;ICD10:U99.91;ICD10:U99.92', '2020-01-01 00:00:00'); // C, repeat and blank item dropped
    $addProblem(930002, 930001, 'Synthetic local problem', 'RAPORTLOCAL:123'); // L, a type the host does not map
    $addProblem(930003, 930001, 'Synthetic text problem', null); // T
    $addProblem(930004, 930001, 'Synthetic resolved problem', '', '2019-01-01 00:00:00', '2021-06-30 00:00:00'); // R
    $addProblem(930005, 930001, 'Synthetic inactive problem', 'ICD10:U99.91', null, null, 0);
    $addProblem(930006, 930001, 'Synthetic allergy', null, null, null, 1, 'allergy');
    $addProblem(930007, 930002, 'Synthetic other patient problem', 'ICD10:U99.91');
    sqlStatement('INSERT INTO issue_encounter (pid, list_id, encounter, resolved) VALUES (930001, 930001, 930101, 0)');
    $nullUuids = fn(): int => (int) sqlQuery('SELECT COUNT(*) AS total FROM lists WHERE id BETWEEN 930001 AND 930007 AND uuid IS NULL')['total'];
    $nullBefore = $nullUuids();
    $uuidOf = fn(string $table, int $id): string => UuidRegistry::uuidToString(sqlQuery("SELECT uuid FROM $table WHERE id = ?", [$id])['uuid']);

    $rows = problemRows(problemsCall($problemsToken, $p1, 200, 'PR1 patient problem list'));
    [$c, $l, $t, $r, $e1] = [$uuidOf('lists', 930001), $uuidOf('lists', 930002), $uuidOf('lists', 930003), $uuidOf('lists', 930004), $uuidOf('form_encounter', 930001)];
    $sorted = [$c, $l, $t, $r];
    sort($sorted, SORT_STRING);
    check(array_keys($rows) === $sorted, 'PR1 only the active problem list rows of P1, in uuid order');
    check($nullBefore === 7 && $nullUuids() === 0, 'PR1 the 7 NULL fixture UUIDs were backfilled');
    $icd10 = 'http://hl7.org/fhir/sid/icd-10';
    check($rows[$c] === ['code' => [
        ['type' => 'ICD10', 'code' => 'U99.92', 'system' => $icd10, 'description' => 'Synthetic second code'],
        ['type' => 'ICD10', 'code' => 'U99.91', 'system' => $icd10, 'description' => 'Synthetic first code'],
    ], 'encounter' => [$e1], 'uuid' => $c, 'title' => 'Synthetic coded problem', 'begdate' => '2020-01-01 00:00:00'], 'PR2 two codes in source order with system and description, and the linked encounter');
    check($rows[$l]['code'] === [['type' => 'RAPORTLOCAL', 'code' => '123']], 'PR3 a type the host does not map has no system or description');
    check($rows[$t] === ['code' => [], 'encounter' => [], 'uuid' => $t, 'title' => 'Synthetic text problem'], 'PR4 a text-only problem has no code or dates');
    check([$rows[$r]['begdate'], $rows[$r]['enddate'], $rows[$r]['code']] === ['2019-01-01 00:00:00', '2021-06-30 00:00:00', []], 'PR5 a resolved problem is listed with its end date');
    sqlStatement("UPDATE lists SET begdate = '0000-00-00 00:00:00' WHERE id = 930003");
    check(!isset(problemRows(problemsCall($problemsToken, $p1, 200, 'PR5 zero begin date'))[$t]['begdate']), 'PR5 a zero date is omitted');

    sqlStatement('UPDATE issue_encounter SET encounter = 930102 WHERE list_id = 930001');
    $foreign = problemsCall($problemsToken, $p1, 409, 'PR6 link to another patient encounter');
    check($foreign['issue'][0]['diagnostics'] === "Problem $c has an unresolvable encounter link.", 'PR6 diagnostics name only the problem UUID');
    sqlStatement('UPDATE issue_encounter SET encounter = 999998 WHERE list_id = 930001');
    problemsCall($problemsToken, $p1, 409, 'PR6 link to a missing encounter');
    sqlStatement('UPDATE issue_encounter SET encounter = 930101 WHERE list_id = 930001');
    sqlStatement("UPDATE lists SET title = ' ' WHERE id = 930003");
    $blank = problemsCall($problemsToken, $p1, 409, 'PR7 blank title');
    check($blank['issue'][0]['diagnostics'] === "Problem $t has no title.", 'PR7 diagnostics name only the problem UUID');
    sqlStatement("UPDATE lists SET title = 'Synthetic text problem', diagnosis = 'U99.91' WHERE id = 930003");
    problemsCall($problemsToken, $p1, 409, 'PR7 diagnosis item without a type');
    sqlStatement('UPDATE lists SET diagnosis = NULL WHERE id = 930003');
    problemsCall($problemsToken, $unknownPatient, 404, 'PR8 unknown patient');
    problemsCall($problemsToken, 'not-a-uuid', 400, 'PR9 malformed patient UUID');
    problemsCall($problemsToken, $p1, 400, 'PR9 query parameter', '?x=1');
    $other = problemRows(problemsCall($problemsToken, $p2, 200, 'PR10 other patient'));
    check(array_keys($other) === [$uuidOf('lists', 930007)], 'PR10 P2 sees only its own problem');

    [$status, $metadata] = callApi('/apis/default/fhir/metadata');
    $advertised = $status === 200;
    foreach (json_decode($metadata, true, 512, JSON_THROW_ON_ERROR)['rest'] as $rest) {
        $patientResource = array_values(array_filter($rest['resource'], fn($resource) => $resource['type'] === 'Patient'))[0];
        $advertised = $advertised && in_array(['name' => 'raport-problems', 'definition' => 'urn:raport:openemr:OperationDefinition:raport-problems'], $patientResource['operation'] ?? [], true);
    }
    check($advertised, 'PR11 CapabilityStatement advertises the problems operation on Patient');
    [$status, $definitions] = callApi('/apis/default/fhir/OperationDefinition');
    $ids = array_count_values(array_map(fn($entry) => (string) ($entry['resource']['id'] ?? ''), json_decode($definitions, true, 512, JSON_THROW_ON_ERROR)['entry']));
    check($status === 200 && ($ids['raport-problems'] ?? 0) === 1, 'PR11 OperationDefinition list holds the problems definition once');

    sqlStatement('UPDATE globals SET gl_value = 2 WHERE gl_name = ?', ['api_log_option']);
    $lastLog = (int) sqlQuery('SELECT COALESCE(MAX(id), 0) AS id FROM api_log')['id'];
    problemsCall($problemsToken, $p1, 200, 'PR12 audited problem list');
    $logged = QueryUtils::fetchRecords('SELECT request, request_body, response, patient_id FROM api_log WHERE id > ?', [$lastLog]);
    check(count($logged) === 1 && $logged[0]['request'] === 'Patient.$raport-problems' && $logged[0]['request_body'] === '' && $logged[0]['response'] === ''
        && (int) $logged[0]['patient_id'] === 930001, 'PR12 one metadata-only audit row with the patient id');

    // Restrict the real OAuth system principal; any denial fails the whole response.
    foreach ($groups as $group) {
        $gacl->del_group_object($group, 'users', 'oe-system', 'ARO');
    }
    $gacl->clear_cache();
    problemsCall($problemsToken, $p1, 403, 'PR13 principal without ACL groups');
    $testGroup = $gacl->add_group('raport-problems-proof', 'RAPORT Problems Proof', $gacl->get_root_group_id(), 'ARO');
    $gacl->add_group_object($testGroup, 'users', 'oe-system', 'ARO');
    $testAcl = $gacl->add_acl(['patients' => ['med']], null, [$testGroup], null, null, 1, 1, 'view', 'Disposable RAPORT test grant');
    check($testAcl !== false, 'PR13 grant only patients|med');
    $gacl->clear_cache();
    problemsCall($problemsToken, $p1, 200, 'PR13 restricted principal reads the problem list');
    sqlStatement('UPDATE patient_data SET squad = ? WHERE pid = 930001', ['raport-restricted']);
    problemsCall($problemsToken, $p1, 403, 'PR13 patient squad');
} finally {
    if ($testAcl !== null && $testAcl !== false) {
        $gacl->del_acl($testAcl);
    }
    if ($testGroup !== null) {
        $gacl->del_group($testGroup, true, 'ARO');
    }
    foreach ($groups as $group) {
        $gacl->add_group_object($group, 'users', 'oe-system', 'ARO');
    }
    $gacl->clear_cache();
    sqlStatement('UPDATE globals SET gl_value = ? WHERE gl_name = ?', [$apiLogOption, 'api_log_option']);
    sqlStatement('DELETE FROM issue_encounter WHERE pid IN (930001, 930002) OR list_id BETWEEN 930001 AND 930010');
    sqlStatement('DELETE FROM lists WHERE id BETWEEN 930001 AND 930010 OR pid IN (930001, 930002)');
    sqlStatement('DELETE FROM form_encounter WHERE pid IN (930001, 930002)');
    sqlStatement('DELETE FROM patient_data WHERE pid IN (930001, 930002)');
    sqlStatement("DELETE FROM icd10_dx_order_code WHERE formatted_dx_code IN ('U99.91', 'U99.92')");
}
check(!sqlQuery($fixtureRows, $fixtureBinds), 'problems fixtures removed');
