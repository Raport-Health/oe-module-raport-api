<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
// Included by auth.php while its disposable OAuth client is enabled.

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Gacl\GaclApi;

function visitsCall(string $token, array $query, int $expected, string $label): array
{
    return operationCall('/apis/default/fhir/$raport-visits' . ($query === [] ? '' : '?' . http_build_query($query)), $token, $expected, $label);
}
// Parameter name => uuid => part name => value, in response order.
function visitRows(array $result): array
{
    $rows = ['provider' => [], 'encounter' => [], 'appointment' => []];
    foreach (array_slice($result['parameter'], 1) as $parameter) {
        $values = [];
        foreach ($parameter['part'] as $part) {
            $name = $part['name'];
            unset($part['name']);
            $values[$name] = reset($part);
        }
        $rows[$parameter['name']][$values['uuid']] = $values;
    }
    return $rows;
}
function inUuidOrder(array $rows): bool
{
    $sorted = array_keys($rows);
    sort($sorted, SORT_STRING);
    return array_keys($rows) === $sorted;
}
function fixtureUuid(string $table, string $column, int $id): string
{
    return UuidRegistry::uuidToString(sqlQuery("SELECT uuid FROM $table WHERE $column = ?", [$id])['uuid']);
}

// V1, the scope gate: one token can hold all four module scopes.
[$status, $granted] = tokenFor($clientId, $key, $moduleScopes);
$missing = array_diff(array_filter(explode(' ', $moduleScopes), fn($scope) => !str_starts_with($scope, 'api:')), explode(' ', $granted['scope'] ?? ''));
check($status === 200 && $missing === [], 'V1 token issued with every module scope (HTTP ' . $status . ')');
[$status] = callApi('/apis/default/fhir/$raport-visits', token: $token);
check($status === 401, 'V2 document scope does not grant visits operation');
$moduleToken = $granted['access_token'];

$p1 = '30000000-0000-4000-8000-000000000001';
$p2 = '30000000-0000-4000-8000-000000000002';
$unknownPatient = '30000000-0000-4000-8000-000000000003';
$fixtureRows = "SELECT pid FROM patient_data WHERE pid IN (920001, 920002) OR uuid IN (?, ?, ?)
    UNION ALL SELECT pid FROM patient_tracker WHERE pid IN (920001, 920002)
    UNION ALL SELECT pc_eid FROM openemr_postcalendar_events WHERE pc_eid BETWEEN 920001 AND 920020
    UNION ALL SELECT id FROM form_encounter WHERE id BETWEEN 920001 AND 920010 OR encounter BETWEEN 920101 AND 920110 OR encounter = 999999
    UNION ALL SELECT id FROM users WHERE username LIKE 'raport-visits-%'";
$fixtureBinds = array_map(fn($uuid) => UuidRegistry::uuidToBytes($uuid), [$p1, $p2, $unknownPatient]);
check(!sqlQuery($fixtureRows, $fixtureBinds), 'visits fixtures are unused');
$gacl = new GaclApi();
$groups = $gacl->get_object_groups($gacl->get_object_id('users', 'oe-system', 'ARO'), 'ARO', 'NO_RECURSE');
$testAcl = null;
$testGroup = null;
$apiLogOption = sqlQuery('SELECT gl_value FROM globals WHERE gl_name = ?', ['api_log_option'])['gl_value'];
$category = sqlQuery('SELECT aco_spec FROM openemr_postcalendar_categories WHERE pc_catid = 5')['aco_spec'];
try {
    $users = [];
    foreach ([['x', 1, 1, 1, '1234567893'], ['y', 1, 1, 0, null], ['staff', 0, 0, 1, null]] as [$name, $authorized, $calendar, $active, $npi]) {
        $users[$name] = (int) sqlInsert('INSERT INTO users (username, fname, lname, authorized, calendar, active, npi) VALUES (?, ?, ?, ?, ?, ?, ?)', ['raport-visits-' . $name, 'Synthetic', 'Provider ' . strtoupper($name), $authorized, $calendar, $active, $npi]);
    }
    foreach ([[920001, $p1, 'P1'], [920002, $p2, 'P2']] as [$pid, $uuid, $name]) {
        sqlStatement('INSERT INTO patient_data (pid, uuid, pubpid, fname, lname, DOB) VALUES (?, ?, ?, ?, ?, ?)', [$pid, UuidRegistry::uuidToBytes($uuid), 'RAPORT-VISITS-' . $name, $name, 'Synthetic Patient', '2000-01-01']);
    }
    // Appointment and encounter UUIDs start NULL so the operation's backfill is exercised.
    $addAppointment = fn(int $eid, int $pid, string $user, string $date, string $time, int $recurrence = 0) => sqlStatement('INSERT INTO openemr_postcalendar_events (pc_eid, pc_catid, pc_pid, pc_aid, pc_eventDate, pc_startTime, pc_recurrtype) VALUES (?, 5, ?, ?, ?, ?, ?)', [$eid, $pid, $users[$user], $date, $time, $recurrence]);
    $addEncounter = fn(int $id, int $pid, string $user, string $date, string $reason) => sqlStatement('INSERT INTO form_encounter (id, pid, encounter, date, reason, provider_id) VALUES (?, ?, ?, ?, ?, ?)', [$id, $pid, $id + 100, $date, $reason, $users[$user]]);
    // A current tracker row copies the appointment's patient, date, time and id, as the Flow Board writes it.
    $addTracker = fn(int $eid, int $number): int => (int) sqlInsert('INSERT INTO patient_tracker (date, apptdate, appttime, eid, pid, original_user, encounter, lastseq) SELECT NOW(), pc_eventDate, pc_startTime, pc_eid, pc_pid, ?, ?, 1 FROM openemr_postcalendar_events WHERE pc_eid = ?', ['admin', $number, $eid]);
    $event = fn(int $eid): string => fixtureUuid('openemr_postcalendar_events', 'pc_eid', $eid);
    $visit = fn(int $id): string => fixtureUuid('form_encounter', 'id', $id);
    $nullUuids = fn(): int => (int) sqlQuery("SELECT (SELECT COUNT(*) FROM openemr_postcalendar_events WHERE pc_eid BETWEEN 920001 AND 920020 AND uuid IS NULL) + (SELECT COUNT(*) FROM form_encounter WHERE id BETWEEN 920001 AND 920010 AND uuid IS NULL) + (SELECT COUNT(*) FROM users WHERE username LIKE 'raport-visits-%' AND uuid IS NULL) AS total")['total'];
    $sorted = function (array $uuids): array {
        sort($uuids, SORT_STRING);
        return $uuids;
    };
    $addAppointment(920001, 920001, 'x', '2031-01-06', '09:00:00'); // A
    $addAppointment(920002, 920001, 'staff', '2031-01-06', '14:00:00'); // B, booked with a provider who is not a calendar user
    $addAppointment(920003, 920001, 'x', '2030-12-01', '08:00:00', 1); // R, also the open-ended series S of V6
    $addEncounter(920001, 920001, 'x', '2031-01-06 09:05:00', 'Synthetic linked visit'); // E1
    $addEncounter(920002, 920001, 'y', '2031-01-07 10:00:00', 'Synthetic walk-in'); // W
    $addTracker(920001, 920101);
    $trackerB = $addTracker(920002, 0);
    $nullBefore = $nullUuids();

    $roster = visitRows(visitsCall($moduleToken, [], 200, 'V3 roster'));
    [$x, $y, $staff] = [fixtureUuid('users', 'id', $users['x']), fixtureUuid('users', 'id', $users['y']), fixtureUuid('users', 'id', $users['staff'])];
    check(isset($roster['provider'][$x], $roster['provider'][$y]) && !isset($roster['provider'][$staff]) && inUuidOrder($roster['provider']) && $roster['encounter'] === [] && $roster['appointment'] === [], 'V3 roster holds calendar owners x and y but not staff, in uuid order');
    check($roster['provider'][$x] === ['uuid' => $x, 'id' => $users['x'], 'username' => 'raport-visits-x', 'given' => 'Synthetic', 'family' => 'Provider X', 'npi' => '1234567893', 'active' => true, 'calendarOwner' => true]
        && $roster['provider'][$y]['active'] === false && $roster['provider'][$y]['calendarOwner'] === true, 'V3 provider parts carry native id, names, NPI, active and calendar owner');

    [$a, $b, $r, $e1, $w] = [$event(920001), $event(920002), $event(920003), $visit(920001), $visit(920002)];
    $history = visitRows(visitsCall($moduleToken, ['patient' => $p1], 200, 'V4 patient history'));
    check(array_keys($history['appointment']) === $sorted([$a, $b, $r]) && array_keys($history['encounter']) === $sorted([$e1, $w]) && array_keys($history['provider']) === $sorted([$x, $y, $staff]), 'V4 patient history holds A, B, R, E1, W and their providers, in uuid order');
    check($history['appointment'][$b]['provider'] === $staff && $history['provider'][$staff] === ['uuid' => $staff, 'id' => $users['staff'], 'username' => 'raport-visits-staff', 'given' => 'Synthetic', 'family' => 'Provider STAFF', 'active' => true, 'calendarOwner' => false], 'V4 B references staff, an active provider who is not a calendar owner');
    check($history['appointment'][$a] === ['uuid' => $a, 'patient' => $p1, 'date' => '2031-01-06', 'time' => '09:00:00', 'provider' => $x, 'status' => 'proposed', 'typeCode' => 'office_visit', 'typeDisplay' => 'Office Visit', 'recurring' => false, 'encounter' => $e1], 'V4 A carries its status, category and linked encounter E1');
    check(!isset($history['appointment'][$b]['encounter']) && $history['appointment'][$r]['recurring'] === true, 'V4 blank tracker encounter leaves B unlinked and R is flagged recurring');
    check($history['encounter'][$w] === ['uuid' => $w, 'patient' => $p1, 'date' => '2031-01-07 10:00:00', 'provider' => $y, 'reason' => 'Synthetic walk-in'], 'V4 walk-in W keeps its wall-clock date and provider');
    check(array_filter([...$history['appointment'], ...$history['encounter']], fn($row) => $row['patient'] !== $p1) === [], 'V4 every row belongs to P1');
    check($nullBefore === 8 && $nullUuids() === 0, 'V5 the 8 NULL fixture UUIDs were backfilled, and V3 and V4 returned the stored values');

    $facility = sqlQuery("SELECT f.id, m.uuid FROM facility f JOIN uuid_mapping m ON m.target_uuid = f.uuid AND m.resource = 'Location' ORDER BY f.id LIMIT 1");
    sqlStatement("UPDATE openemr_postcalendar_events SET pc_apptstatus = '@', pc_endTime = '24:30:00', pc_facility = ? WHERE pc_eid = 920001", [$facility['id']]);
    sqlStatement("UPDATE openemr_postcalendar_events SET pc_apptstatus = 'unknown' WHERE pc_eid = 920002");
    $unknown = visitsCall($moduleToken, ['patient' => $p1], 409, 'V21 a status OpenEMR does not define');
    check($unknown['issue'][0]['diagnostics'] === "Appointment $b has an unsupported status.", 'V21 diagnostics name only the appointment UUID');
    sqlStatement("UPDATE openemr_postcalendar_events SET pc_apptstatus = '^' WHERE pc_eid = 920002");
    $native = visitRows(visitsCall($moduleToken, ['patient' => $p1], 200, 'V21 native appointment fields'))['appointment'];
    check([$native[$a]['status'], $native[$a]['end'], $native[$a]['location'], $native[$b]['status']] === ['arrived', '2031-01-07 00:30:00', UuidRegistry::uuidToString($facility['uuid']), 'pending'],
        'V21 status, an end past midnight and the facility Location UUID follow native FHIR Appointment');

    $addAppointment(920004, 920001, 'x', '2031-02-01', '10:00:00'); // L, dated outside the window
    $addEncounter(920003, 920001, 'x', '2031-01-07 15:00:00', 'Synthetic visit linked from February'); // E2
    $addTracker(920004, 920103);
    $addEncounter(920004, 920001, 'x', '2031-03-01 10:00:00', 'Synthetic later visit'); // E3
    $addAppointment(920005, 920002, 'y', '2031-01-05', '00:00:00');
    $addAppointment(920006, 920002, 'y', '2031-01-11', '23:30:00');
    $addAppointment(920007, 920002, 'y', '2031-01-04', '23:30:00');
    $addAppointment(920008, 920002, 'y', '2031-01-12', '00:00:00');
    $addEncounter(920005, 920002, 'y', '2031-01-11 23:59:59', 'Synthetic last second'); // P2's encounter number for V9
    $addEncounter(920006, 920002, 'y', '2031-01-04 23:59:59', 'Synthetic day before');
    $addEncounter(920007, 920002, 'y', '2031-01-12 00:00:00', 'Synthetic day after');
    // The 23:30 appointment on the last day is checked in after midnight, so its encounter is dated after the window.
    $addEncounter(920009, 920002, 'y', '2031-01-12 00:10:00', 'Synthetic check-in after midnight');
    $addTracker(920006, 920109);
    // A series ended with Future from its first occurrence keeps its row with pc_endDate the day before pc_eventDate.
    $addAppointment(920010, 920002, 'y', '2031-01-05', '08:00:00', 1);
    sqlStatement('UPDATE openemr_postcalendar_events SET pc_endDate = ? WHERE pc_eid = 920010', ['2031-01-04']);
    $window = visitRows(visitsCall($moduleToken, ['start' => '2031-01-05', 'end' => '2031-01-11'], 200, 'V6 window'));
    check(array_diff([$a, $b, $event(920005), $event(920006)], array_keys($window['appointment'])) === [] && array_diff([$e1, $w, $visit(920005)], array_keys($window['encounter'])) === []
        && inUuidOrder($window['appointment']) && inUuidOrder($window['encounter']) && inUuidOrder($window['provider']), 'V6 window holds A, B, W, E1 and rows at 00:00, 23:30 and 23:59:59 on its boundary days');
    check(array_intersect([$event(920007), $event(920008)], array_keys($window['appointment'])) === [] && array_intersect([$visit(920004), $visit(920006), $visit(920007)], array_keys($window['encounter'])) === [], 'V6 window excludes unlinked rows dated 2031-01-04 and 2031-01-12');
    check(($window['appointment'][$event(920004)]['encounter'] ?? null) === $visit(920003) && isset($window['encounter'][$visit(920003)]) && ($window['appointment'][$r]['recurring'] ?? null) === true, 'V6 closure adds L with its encounter E2, and the open-ended series is present');
    check(($window['appointment'][$event(920006)]['encounter'] ?? null) === $visit(920009) && isset($window['encounter'][$visit(920009)]), 'V6 an in-window appointment carries its linked encounter dated after the window');
    check(($window['appointment'][$event(920010)]['recurring'] ?? null) === true, 'V6 a recurring row dated on the window start and ending the day before is present and flagged');
    check(($window['appointment'][$event(920005)]['patient'] ?? null) === $p2, 'V7 another patient appointment in the window carries that patient');
    // Linked one-off appointments later edited in place to repeat: in-window 920006 and L, dated after the window.
    sqlStatement('UPDATE openemr_postcalendar_events SET pc_recurrtype = 1 WHERE pc_eid IN (920004, 920006)');
    $repeating = visitRows(visitsCall($moduleToken, ['start' => '2031-01-05', 'end' => '2031-01-11'], 200, 'V20 window after linked appointments became recurring'));
    check(($repeating['appointment'][$event(920006)]['recurring'] ?? null) === true && !isset($repeating['appointment'][$event(920006)]['encounter']) && !isset($repeating['appointment'][$event(920004)]),
        'V20 a recurring appointment has no encounter part and is not added by the closure');
    sqlStatement('UPDATE openemr_postcalendar_events SET pc_recurrtype = 0 WHERE pc_eid IN (920004, 920006)');

    $extra = $addTracker(920001, 920104);
    $conflict = visitsCall($moduleToken, ['patient' => $p1], 409, 'V8 appointment with two current links');
    check($conflict['issue'][0]['diagnostics'] === "Appointment $a has conflicting encounter links.", 'V8 diagnostics name only the appointment UUID');
    sqlStatement('DELETE FROM patient_tracker WHERE id = ?', [$extra]);
    sqlStatement('UPDATE patient_tracker SET encounter = 920105 WHERE id = ?', [$trackerB]);
    visitsCall($moduleToken, ['patient' => $p1], 409, 'V9 link to another patient encounter');
    sqlStatement('UPDATE patient_tracker SET encounter = 999999 WHERE id = ?', [$trackerB]);
    $dangling = visitsCall($moduleToken, ['patient' => $p1], 409, 'V10 link to a missing encounter');
    check($dangling['issue'][0]['diagnostics'] === "Appointment $b has an unresolvable encounter link.", 'V10 diagnostics name only the appointment UUID');
    sqlStatement('UPDATE patient_tracker SET encounter = 0 WHERE id = ?', [$trackerB]);
    sqlStatement('INSERT INTO form_encounter (id, pid, encounter, date, reason) VALUES (920008, 920001, 920101, ?, ?)', ['2031-01-06 12:00:00', 'Synthetic duplicate number']);
    visitsCall($moduleToken, ['patient' => $p1], 409, 'V11 duplicate encounter number');
    sqlStatement('DELETE FROM form_encounter WHERE id = 920008');

    foreach ([
        'unknown parameter' => ['x' => '1'],
        'array value' => ['patient' => [$p1]],
        'patient with start' => ['patient' => $p1, 'start' => '2031-01-05'],
        'start without end' => ['start' => '2031-01-05'],
        'impossible date' => ['start' => '2031-02-30', 'end' => '2031-03-01'],
        'reversed window' => ['start' => '2031-01-11', 'end' => '2031-01-05'],
        '32-day window' => ['start' => '2031-01-01', 'end' => '2031-02-01'],
        'malformed patient UUID' => ['patient' => 'not-a-uuid'],
    ] as $label => $query) {
        visitsCall($moduleToken, $query, 400, "V12 $label");
    }
    visitsCall($moduleToken, ['start' => '2031-01-01', 'end' => '2031-01-31'], 200, 'V12 31-day window accepted');
    visitsCall($moduleToken, ['patient' => $unknownPatient], 404, 'V13 unknown patient');

    $addAppointment(920009, 920001, 'x', '2031-01-06', '16:00:00'); // A2
    $addTracker(920009, 920101);
    $shared = visitRows(visitsCall($moduleToken, ['patient' => $p1], 200, 'V18 two appointments link one encounter'));
    check($shared['appointment'][$a]['encounter'] === $e1 && $shared['appointment'][$event(920009)]['encounter'] === $e1, 'V18 A and A2 both carry E1');
    sqlStatement('UPDATE form_encounter SET date = NULL WHERE id = 920002');
    $undated = visitsCall($moduleToken, ['patient' => $p1], 409, 'V19 encounter without a date');
    check($undated['issue'][0]['diagnostics'] === "Visit $w has no usable date.", 'V19 diagnostics name only the visit UUID');
    sqlStatement('UPDATE form_encounter SET date = ? WHERE id = 920002', ['0000-00-00 00:00:00']);
    $zeroDate = visitsCall($moduleToken, ['patient' => $p1], 409, 'V19 encounter with a zero date');
    check($zeroDate['issue'][0]['diagnostics'] === "Visit $w has no usable date.", 'V19 zero-date diagnostics name only the visit UUID');
    sqlStatement('UPDATE form_encounter SET date = ? WHERE id = 920002', ['2031-01-07 10:00:00']);

    sqlStatement('UPDATE globals SET gl_value = 2 WHERE gl_name = ?', ['api_log_option']);
    $lastLog = (int) sqlQuery('SELECT COALESCE(MAX(id), 0) AS id FROM api_log')['id'];
    visitsCall($moduleToken, ['patient' => $p1], 200, 'V17 audited patient history');
    $logged = QueryUtils::fetchRecords('SELECT request, request_url, request_body, response, patient_id FROM api_log WHERE id > ?', [$lastLog]);
    check(count($logged) === 1 && $logged[0]['request'] === '$raport-visits' && $logged[0]['request_body'] === '' && $logged[0]['response'] === ''
        && !str_contains($logged[0]['request_url'], '?') && (int) $logged[0]['patient_id'] === 920001, 'V17 one metadata-only audit row without the query string');

    // Restrict the real OAuth system principal; any denial fails the whole response.
    foreach ($groups as $group) {
        $gacl->del_group_object($group, 'users', 'oe-system', 'ARO');
    }
    $gacl->clear_cache();
    visitsCall($moduleToken, [], 403, 'V15 principal without ACL groups');
    $testGroup = $gacl->add_group('raport-visits-proof', 'RAPORT Visits Proof', $gacl->get_root_group_id(), 'ARO');
    $gacl->add_group_object($testGroup, 'users', 'oe-system', 'ARO');
    // encounters|notes is the host's default appointment category ACO, which every encounter row is checked against.
    // Each mode ACO is withheld once before the full grant; edit_acl keeps the id the finally block deletes.
    $testAcl = $gacl->add_acl(['admin' => ['users'], 'encounters' => ['auth_a', 'notes']], null, [$testGroup], null, null, 1, 1, 'view', 'Disposable RAPORT test grant');
    check($testAcl !== false, 'V15 grant user and encounter reads without patients|appt');
    $gacl->clear_cache();
    visitsCall($moduleToken, [], 200, 'V15 restricted principal reads the roster');
    visitsCall($moduleToken, ['patient' => $p1], 403, 'V15 patient mode without patients|appt');
    check($gacl->edit_acl($testAcl, ['admin' => ['users'], 'patients' => ['appt'], 'encounters' => ['notes']], [], [$testGroup], null, null, 1, 1, 'view', 'Disposable RAPORT test grant'), 'V15 grant user and appointment reads without encounters|auth_a');
    $gacl->clear_cache();
    visitsCall($moduleToken, ['start' => '2031-01-05', 'end' => '2031-01-11'], 403, 'V15 window mode without encounters|auth_a');
    check($gacl->edit_acl($testAcl, ['admin' => ['users'], 'patients' => ['appt'], 'encounters' => ['auth_a', 'notes']], [], [$testGroup], null, null, 1, 1, 'view', 'Disposable RAPORT test grant'), 'V15 grant only user, appointment and encounter reads');
    $gacl->clear_cache();
    visitsCall($moduleToken, ['patient' => $p1], 200, 'V15 restricted principal reads patient history');
    sqlStatement('UPDATE form_encounter SET sensitivity = ? WHERE id = 920001', ['raport-restricted']);
    visitsCall($moduleToken, ['patient' => $p1], 403, 'V15 sensitive encounter');
    sqlStatement('UPDATE form_encounter SET sensitivity = NULL WHERE id = 920001');
    sqlStatement('UPDATE patient_data SET squad = ? WHERE pid = 920001', ['raport-restricted']);
    visitsCall($moduleToken, ['patient' => $p1], 403, 'V15 patient squad');
    sqlStatement('UPDATE patient_data SET squad = ? WHERE pid = 920001', ['']);
    sqlStatement('UPDATE openemr_postcalendar_categories SET aco_spec = ? WHERE pc_catid = 5', ['admin|super']);
    visitsCall($moduleToken, ['patient' => $p1], 403, 'V15 encounter category ACO');
} finally {
    restoreSystemAcl($gacl, $groups, $testAcl, $testGroup);
    sqlStatement('UPDATE globals SET gl_value = ? WHERE gl_name = ?', [$apiLogOption, 'api_log_option']);
    sqlStatement('UPDATE openemr_postcalendar_categories SET aco_spec = ? WHERE pc_catid = 5', [$category]);
    sqlStatement('DELETE FROM patient_tracker WHERE pid IN (920001, 920002)');
    sqlStatement('DELETE FROM openemr_postcalendar_events WHERE pc_eid BETWEEN 920001 AND 920020');
    sqlStatement('DELETE FROM form_encounter WHERE pid IN (920001, 920002)');
    sqlStatement('DELETE FROM patient_data WHERE pid IN (920001, 920002)');
    sqlStatement("DELETE FROM users WHERE username LIKE 'raport-visits-%'");
}
check(!sqlQuery($fixtureRows, $fixtureBinds), 'visits fixtures removed');
