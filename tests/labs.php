<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
// Included by auth.php while its disposable OAuth client is enabled.

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Gacl\GaclApi;
use Raport\OpenEmr\Bootstrap;

function labsCall(string $token, string $patient, int $expected = 200, string $query = ''): array
{
    return operationCall('/apis/default/fhir/Patient/' . $patient . '/$raport-labs' . $query, $token, $expected, "labs expected $expected");
}
function labRows(array $result, string $kind): array
{
    return array_map(fn($entry) => array_column(array_map(fn($part) => ['name' => $part['name'], 'value' => $part['valueString'] ?? $part['valueInteger']], $entry['part']), 'value', 'name'), array_values(array_filter($result['parameter'], fn($entry) => $entry['name'] === $kind)));
}

$labPatient = '50000000-0000-4000-8000-000000000001';
$labOther = '50000000-0000-4000-8000-000000000002';
$labEmpty = '50000000-0000-4000-8000-000000000003';
$labEncounter = '60000000-0000-4000-8000-000000000001';
$otherEncounter = '60000000-0000-4000-8000-000000000002';
$fixtureQuery = 'SELECT pid FROM patient_data WHERE pid BETWEEN 940001 AND 940003 UNION ALL SELECT encounter FROM form_encounter WHERE encounter BETWEEN 940001 AND 940003 UNION ALL SELECT procedure_order_id FROM procedure_order WHERE procedure_order_id BETWEEN 940001 AND 940004 UNION ALL SELECT procedure_report_id FROM procedure_report WHERE procedure_report_id BETWEEN 940001 AND 940003 UNION ALL SELECT procedure_result_id FROM procedure_result WHERE procedure_result_id BETWEEN 940001 AND 940007 UNION ALL SELECT id FROM forms WHERE pid BETWEEN 940001 AND 940003 UNION ALL SELECT id FROM documents WHERE id=940001';
check(!sqlQuery($fixtureQuery), 'lab fixture IDs are unused');
[$status, $labsToken] = tokenFor($clientId, $key, 'api:fhir ' . Bootstrap::LABS_SCOPE);
check($status === 200, 'labs-only operation token issued');
$labsToken = $labsToken['access_token'];
labsCall($token, $labPatient, 401);
labsCall('invalid', $labPatient, 401);
labsCall($labsToken, 'not-a-uuid', 400);
labsCall($labsToken, $labPatient, 404);
$gacl = new GaclApi();
$groups = $gacl->get_object_groups($gacl->get_object_id('users', 'oe-system', 'ARO'), 'ARO', 'NO_RECURSE');
$testAcl = null;
$testGroup = null;
$apiLogOption = sqlQuery('SELECT gl_value FROM globals WHERE gl_name = ?', ['api_log_option'])['gl_value'];
try {
    foreach ([[940001, $labPatient], [940002, $labOther], [940003, $labEmpty]] as [$pid, $uuid]) {
        sqlStatement('INSERT INTO patient_data (pid, uuid, pubpid, fname, lname, DOB) VALUES (?, ?, ?, ?, ?, ?)', [$pid, UuidRegistry::uuidToBytes($uuid), 'RAPORT-LABS-' . $pid, 'Synthetic', 'Labs', '2000-01-01']);
    }
    foreach ([[940001, $labEncounter], [940002, $otherEncounter]] as [$pid, $uuid]) {
        sqlStatement('INSERT INTO form_encounter (pid, encounter, uuid, date, reason, pc_catid) VALUES (?, ?, ?, ?, ?, 5)', [$pid, $pid, UuidRegistry::uuidToBytes($uuid), '2026-09-30 11:00:00', 'Synthetic labs']);
    }
    foreach ([[940001, 940001, 940001, 1, 'laboratory_test'], [940002, 940001, 0, 1, 'procedure'], [940003, 940001, 940001, 0, 'laboratory_test'], [940004, 940002, 940002, 1, 'laboratory_test']] as [$order, $pid, $encounter, $active, $type]) {
        sqlStatement("INSERT INTO procedure_order (procedure_order_id, patient_id, encounter_id, activity, procedure_order_type, order_status, order_priority, clinical_hx, patient_instructions, date_ordered) VALUES (?, ?, ?, ?, ?, 'pending', 'normal', ?, ?, ?)", [$order, $pid, $encounter, $active, $type, 'HISTORY marker Ω <script>plain text</script>', "INSTRUCTIONS marker\nSynthetic only", '2026-09-30 11:15:00']);
        sqlStatement("INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, diagnoses, reason_description) VALUES (?, 1, 'VENDOR-GLUCOSE', 'Synthetic glucose panel', 'ICD10:U99.91', 'REASON marker')", [$order]);
    }
    sqlStatement("INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name) VALUES (940001, 2, 'VENDOR-TEXT', 'Synthetic textual result')");
    sqlStatement("INSERT INTO procedure_answers (procedure_order_id, procedure_order_seq, question_code, answer_seq, answer) VALUES (940001, 1, 'FASTING', 1, 'ANSWER marker 0')");
    require_once $GLOBALS['srcdir'] . '/forms.inc.php';
    $labRegistration = (int) addForm(940001, 'Synthetic Procedure Order', 940001, 'procedure_order', 940001, '1', 'NOW()', 'oe-system');
    $pending = labsCall($labsToken, $labPatient);
    check(count(labRows($pending, 'order')) === 2 && count(labRows($pending, 'test')) === 3 && labRows($pending, 'report') === [] && labRows($pending, 'result') === [], 'pending and legacy orders survive; inactive and other-patient orders excluded');
    $orderRows = array_column(labRows($pending, 'order'), null, 'uuid');
    $orderUuid = UuidRegistry::uuidToString(sqlQuery('SELECT uuid FROM procedure_order WHERE procedure_order_id=940001')['uuid']);
    $legacyUuid = UuidRegistry::uuidToString(sqlQuery('SELECT uuid FROM procedure_order WHERE procedure_order_id=940002')['uuid']);
    check($orderRows[$orderUuid]['encounter'] === $labEncounter && $orderRows[$orderUuid]['clinicalHistory'] === 'HISTORY marker Ω <script>plain text</script>' && $orderRows[$orderUuid]['patientInstructions'] === "INSTRUCTIONS marker\nSynthetic only" && !isset($orderRows[$legacyUuid]['encounter']), 'native order text and authoritative optional encounter link');
    check(labsCall($labsToken, strtoupper($labPatient)) === $pending && labsCall($labsToken, $labPatient) === $pending, 'unchanged reads and uppercase UUID input have stable content');
    $empty = labsCall($labsToken, $labEmpty);
    check(count($empty['parameter']) === 1, 'known empty patient has explicit complete snapshot');
    file_put_contents('/module-local/artifacts/labs-empty.json', json_encode($empty, JSON_THROW_ON_ERROR));

    foreach ([[940001, 1, 'prelim'], [940002, 1, 'final'], [940003, 2, 'prelim']] as [$report, $sequence, $state]) {
        sqlStatement('INSERT INTO procedure_report (procedure_report_id, procedure_order_id, procedure_order_seq, report_status, review_status, date_report, date_report_tz, date_collected, date_collected_tz, report_notes) VALUES (?, 940001, ?, ?, ?, ?, ?, ?, ?, ?)', [$report, $sequence, $state, 'received', '2026-09-30 12:00:00', '-0500', '2026-09-30 11:30:00', '-0500', 'REPORT NOTES marker']);
    }
    foreach ([[940001, 940001, 'N', 'ZERO', '0', '0-5'], [940002, 940001, 'N', 'VALUE', '43', '10-50'], [940003, 940002, 'S', 'DETECTED', 'Detected', 'Negative'], [940004, 940003, 'N', 'COMPARATOR', '<5', '-5-0'], [940005, 940003, 'S', 'EMPTY', '', ''], [940006, 940003, 'S', 'DNR', 'DNR', ''], [940007, 940003, 'L', 'LONG', '', '']] as [$id, $report, $type, $code, $value, $range]) {
        sqlStatement('INSERT INTO procedure_result (procedure_result_id, procedure_report_id, result_data_type, result_code, result_text, result, units, `range`, abnormal, result_status, comments, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $report, $type, $code, 'Synthetic ' . $code, $value, 'MG/DL', $range, 'no', 'prelim', "COMMENTS marker line~two\r\nnotes Ω", '2026-09-30 12:00:00']);
    }
    $preliminary = labsCall($labsToken, $labPatient);
    $reports = array_column(labRows($preliminary, 'report'), null, 'uuid');
    $reportUuid = UuidRegistry::uuidToString(sqlQuery('SELECT uuid FROM procedure_report WHERE procedure_report_id=940001')['uuid']);
    $results = array_column(labRows($preliminary, 'result'), null, 'code');
    check(count($reports) === 3 && count($results) === 7, 'all reports per test and all result types survive');
    check($results['ZERO']['value'] === '0' && $results['ZERO']['referenceRange'] === '0-5' && $results['ZERO']['status'] === 'prelim', 'zero, zero-bound range and preliminary status retained');
    check($results['COMPARATOR']['value'] === '<5' && $results['COMPARATOR']['referenceRange'] === '-5-0' && $results['DETECTED']['value'] === 'Detected' && $results['DNR']['value'] === 'DNR' && !isset($results['EMPTY']['value']), 'comparator, negative/text range, text/DNR and genuinely absent value stay distinct');
    check($reports[$reportUuid]['status'] === 'prelim' && $reports[$reportUuid]['reportedOffset'] === '-0500' && $results['LONG']['comments'] === "COMMENTS marker line~two\r\nnotes Ω", 'report statuses, stored offset and long comments are verbatim');
    file_put_contents('/module-local/artifacts/labs-preliminary.json', json_encode($preliminary, JSON_THROW_ON_ERROR));
    $export = exported($labEncounter, $token);
    check(outputValue($export, 'noteCount', 'valueUnsignedInt') === 1 && notePart($export, $labRegistration, 'formType', 'valueString') === 'procedure_order', 'ordinary registered order exports as encounter content');
    file_put_contents('/module-local/artifacts/export-labs.pdf', base64_decode(outputValue($export, 'document', 'resource')['content'][0]['attachment']['data'], true));
    file_put_contents('/module-local/artifacts/export-labs.json', json_encode($export, JSON_THROW_ON_ERROR));
    check(revisionOf(exported($labEncounter, $token)) === revisionOf($export), 'unchanged order rendering has stable note revision');
    // Removing an order's last test deactivates it and leaves its form registered (native handle_deletions.php).
    sqlStatement('UPDATE procedure_order SET activity=0 WHERE procedure_order_id=940001');
    $inactive = exported($labEncounter, $token);
    $excludedIds = array_map(fn($entry) => (int) array_column($entry['part'], 'valueString', 'name')['registrationId'], array_values(array_filter($inactive['parameter'], fn($entry) => $entry['name'] === 'excludedForm')));
    check(outputValue($inactive, 'noteCount', 'valueUnsignedInt') === 0 && $excludedIds === [$labRegistration], 'inactive registered order is excluded, not a failure');
    sqlStatement('UPDATE procedure_order SET activity=1 WHERE procedure_order_id=940001');
    sqlStatement("UPDATE procedure_report SET report_status='correct' WHERE procedure_report_id=940001");
    sqlStatement("UPDATE procedure_result SET result='7', abnormal='high', result_status='correct', comments='CORRECTED marker' WHERE procedure_result_id=940001");
    $corrected = labsCall($labsToken, $labPatient);
    $correctedZero = array_column(labRows($corrected, 'result'), null, 'code')['ZERO'];
    check($correctedZero['uuid'] === $results['ZERO']['uuid'] && $correctedZero['value'] === '7' && $correctedZero['status'] === 'correct' && $correctedZero['abnormal'] === 'high', 'correction retains identity and native status without changing dates');
    check(revisionOf(exported($labEncounter, $token)) !== revisionOf($export), 'result correction advances encounter document revision too');
    file_put_contents('/module-local/artifacts/labs-corrected.json', json_encode($corrected, JSON_THROW_ON_ERROR));
    foreach (['cancel', 'error', 'incomplete', 'CUSTOM'] as $state) {
        sqlStatement('UPDATE procedure_report SET report_status=? WHERE procedure_report_id=940001', [$state]);
        sqlStatement('UPDATE procedure_result SET result_status=? WHERE procedure_result_id=940001', [$state]);
        $response = labsCall($labsToken, $labPatient);
        check(array_column(labRows($response, 'report'), null, 'uuid')[$reportUuid]['status'] === $state && array_column(labRows($response, 'result'), null, 'code')['ZERO']['status'] === $state, "native $state status is never changed to final/unknown");
    }
    foreach (['?patient=' . $labOther, '?_count=1', '?status=final', '?x[]=1'] as $query) { labsCall($labsToken, $labPatient, 400, $query); }
    check(count(labRows(labsCall($labsToken, $labOther), 'order')) === 1, 'other patient gets only its own order');
    sqlStatement('UPDATE procedure_report SET procedure_order_seq=999 WHERE procedure_report_id=940001');
    labsCall($labsToken, $labPatient, 409);
    sqlStatement('UPDATE procedure_report SET procedure_order_seq=1 WHERE procedure_report_id=940001');
    sqlStatement('UPDATE procedure_order SET encounter_id=940002 WHERE procedure_order_id=940001');
    labsCall($labsToken, $labPatient, 409);
    exported($labEncounter, $token, 409);
    sqlStatement('UPDATE procedure_order SET encounter_id=940001 WHERE procedure_order_id=940001');
    sqlStatement('INSERT INTO form_encounter (pid, encounter, uuid, date) VALUES (940002, 940001, ?, NOW())', [UuidRegistry::uuidToBytes('60000000-0000-4000-8000-000000000003')]);
    labsCall($labsToken, $labPatient, 409);
    sqlStatement('DELETE FROM form_encounter WHERE pid=940002 AND encounter=940001');
    sqlStatement('INSERT INTO documents (id, uuid, foreign_id, url) VALUES (940001, ?, 940002, ?)', [UuidRegistry::uuidToBytes('70000000-0000-4000-8000-000000000001'), 'file:///tmp/unused-synthetic-lab']);
    sqlStatement('UPDATE procedure_result SET document_id=940001 WHERE procedure_result_id=940001');
    labsCall($labsToken, $labPatient, 409);
    sqlStatement('UPDATE documents SET foreign_id=940001 WHERE id=940001');
    check(array_column(labRows(labsCall($labsToken, $labPatient), 'result'), null, 'code')['ZERO']['document'] === '70000000-0000-4000-8000-000000000001', 'same-patient attachment reference retained without reading bytes');

    foreach ($groups as $group) { $gacl->del_group_object($group, 'users', 'oe-system', 'ARO'); }
    $testGroup = $gacl->add_group('raport-labs-proof', 'RAPORT labs proof', $gacl->get_root_group_id(), 'ARO');
    $gacl->add_group_object($testGroup, 'users', 'oe-system', 'ARO');
    $gacl->clear_cache();
    labsCall($labsToken, $labPatient, 403);
    $testAcl = $gacl->add_acl(['patients' => ['med'], 'encounters' => ['notes']], null, [$testGroup], null, null, 1, 1, 'view', 'Disposable lab grant');
    check($testAcl !== false, 'restricted lab grant created');
    $gacl->clear_cache();
    labsCall($labsToken, $labPatient, 403); // result document needs patients|docs too
    sqlStatement('UPDATE procedure_result SET document_id=0 WHERE procedure_result_id=940001');
    labsCall($labsToken, $labPatient);
    sqlStatement("UPDATE patient_data SET squad='raport-restricted' WHERE pid=940001");
    labsCall($labsToken, $labPatient, 403);
    sqlStatement("UPDATE patient_data SET squad='' WHERE pid=940001");
    sqlStatement("UPDATE form_encounter SET sensitivity='raport-restricted' WHERE encounter=940001");
    labsCall($labsToken, $labPatient, 403);
    sqlStatement('UPDATE form_encounter SET sensitivity=NULL WHERE encounter=940001');
    sqlStatement('UPDATE globals SET gl_value=2 WHERE gl_name=?', ['api_log_option']);
    labsCall($labsToken, $labPatient);
    $audit = sqlQuery('SELECT COUNT(*) AS total, SUM(request_body <> ? OR response <> ?) AS bodies FROM api_log WHERE request=? AND patient_id=940001', ['', '', 'Patient.$raport-labs']);
    check((int) $audit['total'] > 0 && (int) $audit['bodies'] === 0, 'lab audit retains metadata only, including with body logging enabled');
} finally {
    if ($testAcl !== null && $testAcl !== false) { $gacl->del_acl($testAcl); }
    if ($testGroup !== null) { $gacl->del_group($testGroup, true, 'ARO'); }
    foreach ($groups as $group) { $gacl->add_group_object($group, 'users', 'oe-system', 'ARO'); }
    $gacl->clear_cache();
    sqlStatement('UPDATE globals SET gl_value=? WHERE gl_name=?', [$apiLogOption, 'api_log_option']);
    // Exactly the fixture IDs: rows created later through the UI get auto-increment IDs just above them.
    foreach (['procedure_result' => ['procedure_result_id', 940007], 'procedure_report' => ['procedure_report_id', 940003], 'procedure_order' => ['procedure_order_id', 940004]] as $table => [$id, $last]) { sqlStatement("DELETE FROM $table WHERE $id BETWEEN 940001 AND $last"); }
    foreach (['procedure_answers', 'procedure_order_code'] as $table) { sqlStatement("DELETE FROM $table WHERE procedure_order_id BETWEEN 940001 AND 940004"); }
    sqlStatement('DELETE FROM documents WHERE id=940001');
    foreach (['forms', 'form_encounter', 'patient_data'] as $table) { sqlStatement("DELETE FROM $table WHERE pid BETWEEN 940001 AND 940003"); }
}
check(!sqlQuery($fixtureQuery), 'lab fixtures removed');
