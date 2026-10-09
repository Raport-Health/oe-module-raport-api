<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT
// Included by auth.php while its disposable OAuth client is enabled.

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Gacl\GaclApi;

function exported(string $uuid, string $token, int $expected = 200, string $query = ''): array
{
    return operationCall('/apis/default/fhir/Encounter/' . $uuid . '/$raport-document' . $query, $token, $expected, "export expected $expected");
}
function outputValue(array $result, string $name, string $key)
{
    $values = array_values(array_filter($result['parameter'], fn($parameter) => $parameter['name'] === $name));
    check(count($values) === 1, 'exactly one ' . $name);
    return $values[0][$key];
}
function revisionOf(array $result): string
{
    return outputValue($result, 'revision', 'valueString');
}
function excludedTypes(array $result): array
{
    $types = array_map(fn($p) => array_column($p['part'], 'valueString', 'name')['formType'], array_filter($result['parameter'], fn($p) => $p['name'] === 'excludedForm'));
    sort($types);
    return $types;
}
function notePart(array $result, int $registration, string $name, string $key)
{
    foreach ($result['parameter'] as $parameter) {
        if ($parameter['name'] !== 'note') {
            continue;
        }
        $parts = array_column($parameter['part'], null, 'name');
        if ((int) $parts['registrationId']['valueString'] === $registration) {
            return $parts[$name][$key];
        }
    }
    throw new RuntimeException('Missing note registration.');
}

$patientA = '10000000-0000-4000-8000-000000000001';
$patientB = '10000000-0000-4000-8000-000000000002';
$encounterA = '20000000-0000-4000-8000-000000000001';
$encounterB = '20000000-0000-4000-8000-000000000002';
$encounterC = '20000000-0000-4000-8000-000000000003';
$layout = 'LBFraportexport';
$fixtures = [];
$lbfId = null;
$secondLbfId = null;
$gacl = new GaclApi();
$aro = $gacl->get_object_id('users', 'oe-system', 'ARO');
$groups = $gacl->get_object_groups($aro, 'ARO', 'NO_RECURSE');
$testAcl = null;
$testGroup = null;
$globalsBefore = [];
foreach (['lock_esign_individual', 'lock_esign_all', 'api_log_option'] as $name) {
    $globalsBefore[$name] = sqlQuery('SELECT gl_value FROM globals WHERE gl_name = ?', [$name])['gl_value'];
}
check(!sqlQuery('SELECT pid FROM patient_data WHERE pid IN (910001,910002)'), 'HTTP fixture patients are unused');
check(!sqlQuery('SELECT encounter FROM form_encounter WHERE encounter IN (910001,910002,910003)'), 'HTTP fixture encounters are unused');
check(!sqlQuery('SELECT form_id FROM layout_options WHERE form_id = ?', [$layout]), 'HTTP fixture layout is unused');
try {
    foreach ([[910001, $patientA, 'Alpha'], [910002, $patientB, 'Beta']] as [$pid, $uuid, $name]) {
        sqlStatement('INSERT INTO patient_data (pid, uuid, pubpid, fname, lname, DOB) VALUES (?, ?, ?, ?, ?, ?)', [$pid, UuidRegistry::uuidToBytes($uuid), 'RAPORT-EXPORT-' . $name, $name, 'Synthetic Patient', '2000-01-01']);
    }
    foreach ([[910001, 910001, $encounterA], [910002, 910002, $encounterB], [910001, 910003, $encounterC]] as [$pid, $eid, $uuid]) {
        sqlStatement('INSERT INTO form_encounter (pid, encounter, uuid, date, reason) VALUES (?, ?, ?, NOW(), ?)', [$pid, $eid, UuidRegistry::uuidToBytes($uuid), 'Synthetic encounter export']);
    }
    $soapId = (int) sqlInsert('INSERT INTO form_soap (pid, date, user, activity, subjective, objective, assessment, plan) VALUES (910001, NOW(), ?, 1, ?, ?, ?, ?)', ['admin', 'ALPHA SOAP SUBJECTIVE', 'ALPHA SOAP OBJECTIVE', 'ALPHA SOAP ASSESSMENT', 'ALPHA SOAP PLAN']);
    foreach ([[910001, 910001, 910001, 'ALPHA CLINICAL NOTE'], [910002, 910002, 910002, 'BETA CLINICAL NOTE']] as [$pid, $eid, $fid, $description]) {
        sqlStatement('INSERT INTO form_clinical_notes (form_id, pid, encounter, date, user, activity, description) VALUES (?, ?, ?, CURDATE(), ?, 1, ?)', [$fid, $pid, $eid, 'admin', $description]);
    }
    sqlStatement('INSERT INTO layout_group_properties (grp_form_id, grp_group_id, grp_title, grp_aco_spec) VALUES (?, ?, ?, ?)', [$layout, '', 'Synthetic Layout Note', 'encounters|notes']);
    sqlStatement('INSERT INTO layout_group_properties (grp_form_id, grp_group_id, grp_title) VALUES (?, ?, ?)', [$layout, '1', 'Clinical narrative']);
    // The field types of a real clinic layout: text area, provider, static text divider and template text.
    foreach ([['narrative', 'Narrative', 1, 3, 'F'], ['fname', 'Current first name', 2, 2, 'D'], ['signer', 'Provider', 3, 10, 'F'], ['divider', '', 4, 31, 'F'], ['template', 'Template', 5, 34, 'F']] as [$field, $title, $seq, $type, $source]) {
        sqlStatement('INSERT INTO layout_options (form_id, field_id, group_id, title, seq, data_type, uor, source) VALUES (?, ?, ?, ?, ?, ?, 1, ?)', [$layout, $field, '1', $title, $seq, $type, $source]);
    }
    $lbfId = (int) sqlInsert('INSERT INTO lbf_data (field_id, field_value) VALUES (?, ?)', ['narrative', 'ALPHA LBF NOTE']);
    // Template text is stored as the editor's HTML.
    foreach (['signer' => sqlQuery('SELECT id FROM users WHERE username = ?', ['oe-system'])['id'], 'template' => '<p>ALPHA <strong>TEMPLATE</strong> NOTE &amp; more</p>'] as $field => $value) {
        sqlStatement('INSERT INTO lbf_data (form_id, field_id, field_value) VALUES (?, ?, ?)', [$lbfId, $field, $value]);
    }
    foreach ([[910001,910001,'soap',$soapId], [910001,910001,'clinical_notes',910001], [910001,910001,$layout,$lbfId], [910002,910002,'clinical_notes',910002]] as [$pid,$eid,$type,$fid]) {
        $displayName = ['soap'=>'SOAP','clinical_notes'=>'Clinical Notes',$layout=>'Layout Note'][$type];
        $fixtures[$type . '/' . $pid] = (int) sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name, user, date, authorized) VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)', [$pid,$eid,$fid,$type,$displayName,'admin']);
    }
    sqlStatement('UPDATE globals SET gl_value = 1 WHERE gl_name IN (?, ?)', ['lock_esign_individual', 'lock_esign_all']);
    sqlStatement('UPDATE globals SET gl_value = 2 WHERE gl_name = ?', ['api_log_option']);
    $first = exported($encounterA, $token);
    check(outputValue($first, 'complete', 'valueBoolean') === true && outputValue($first, 'noteCount', 'valueUnsignedInt') === 3, 'all three note types included');
    $doc = outputValue($first, 'document', 'resource');
    check($doc['subject']['reference'] === 'Patient/' . $patientA && $doc['context']['encounter'][0]['reference'] === 'Encounter/' . $encounterA, 'document belongs to requested patient and encounter');
    check(!isset($doc['docStatus']) && !isset($doc['id']), 'no invented signed status or persisted resource ID');
    $bytes = base64_decode($doc['content'][0]['attachment']['data'], true);
    check(str_starts_with($bytes, '%PDF-') && strlen($bytes) === $doc['content'][0]['attachment']['size'], 'HTTP attachment is a complete PDF');
    file_put_contents('/module-local/artifacts/export-alpha.pdf', $bytes);
    file_put_contents('/module-local/artifacts/export-alpha.json', json_encode($first, JSON_THROW_ON_ERROR));
    $again = exported($encounterA, $token);
    check(revisionOf($first) === revisionOf($again), 'unchanged export has stable revision');
    check(outputValue($again, 'document', 'resource')['identifier'] === $doc['identifier'], 'unchanged logical document identity');
    $beta = exported($encounterB, $token);
    check(outputValue($beta, 'noteCount', 'valueUnsignedInt') === 1, 'other patient has only its own note');
    file_put_contents('/module-local/artifacts/export-beta.pdf', base64_decode(outputValue($beta, 'document', 'resource')['content'][0]['attachment']['data'], true));
    // A layout note saved blank stores no lbf_data rows. Removed again: it would bound the history test's first note.
    $blankLbf = sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name) VALUES (910001, 910003, 910004, ?, ?)', [$layout, 'Layout Note']);
    $empty = exported($encounterC, $token);
    sqlStatement('DELETE FROM forms WHERE id = ?', [$blankLbf]);
    file_put_contents('/module-local/artifacts/export-empty.json', json_encode($empty, JSON_THROW_ON_ERROR));
    check(outputValue($empty, 'noteCount', 'valueUnsignedInt') === 0 && !in_array('document', array_column($empty['parameter'], 'name'), true) && excludedTypes($empty) === [$layout], 'a blank layout note is excluded, leaving an explicit empty inventory and no PDF');
    foreach ([
        ['form_soap', 'UPDATE form_soap SET subjective = ? WHERE id = ?', ['ALPHA EDITED SOAP', $soapId]],
        ['form_clinical_notes', 'UPDATE form_clinical_notes SET description = ? WHERE form_id = 910001', ['ALPHA EDITED CLINICAL']],
        ['lbf_data', 'UPDATE lbf_data SET field_value = ? WHERE form_id = ? AND field_id = ?', ['ALPHA EDITED LBF', $lbfId, 'narrative']],
    ] as [$table, $sql, $binds]) {
        sqlStatement($sql, $binds);
        $changed = exported($encounterA, $token);
        check(revisionOf($changed) !== revisionOf($again), "$table edit changes revision");
        check(outputValue($changed, 'document', 'resource')['identifier'] === $doc['identifier'], 'edit preserves logical identity');
        $again = $changed;
    }
    sqlStatement('UPDATE patient_data SET fname = ? WHERE pid = 910001', ['AlphaChanged']);
    $changed = exported($encounterA, $token);
    check(revisionOf($changed) !== revisionOf($again), 'dynamic LBF demographic change changes revision');
    $again = $changed;
    sqlStatement('UPDATE layout_options SET title = ? WHERE form_id = ? AND field_id = ?', ['Revised narrative heading', $layout, 'narrative']);
    $changed = exported($encounterA, $token);
    check(revisionOf($changed) !== revisionOf($again), 'layout change changes revision');
    $again = $changed;
    require_once $GLOBALS['srcdir'] . '/ESign/Form/Factory.php';
    $lbfRegistration = $fixtures[$layout . '/910001'];
    $signatureId = (new \ESign\Form_Factory($lbfRegistration, $layout, 910001))->createSignable()->sign(1, true, 'Synthetic signing proof');
    $signed = exported($encounterA, $token);
    file_put_contents('/module-local/artifacts/export-signed.json', json_encode($signed, JSON_THROW_ON_ERROR));
    check(revisionOf($signed) !== revisionOf($again), 'recorded signature changes export revision');
    check(notePart($signed,$lbfRegistration,'signatureRecorded','valueBoolean') === true && notePart($signed,$lbfRegistration,'locked','valueBoolean') === true, 'signature evidence uses forms registration ID and native lock state');
    $parts = array_column(notePart($signed,$lbfRegistration,'signature','part'), null, 'name');
    check($parts['id']['valueString'] === (string)$signatureId && $parts['verification']['valueCode'] === 'not-performed', 'signature ID retained without claiming cryptographic verification');
    file_put_contents('/module-local/artifacts/export-signed.pdf', base64_decode(outputValue($signed, 'document', 'resource')['content'][0]['attachment']['data'], true));
    require_once $GLOBALS['srcdir'] . '/ESign/Encounter/Signable.php';
    (new \ESign\Encounter_Signable(910001))->sign(1,true,'Synthetic encounter sign-off');
    $encounterSigned = exported($encounterA,$token);
    check(revisionOf($encounterSigned)!==revisionOf($signed) && count(array_filter($encounterSigned['parameter'],fn($p)=>$p['name']==='encounterSignature'))===1,'encounter signature is separate evidence and changes revision');
    check(notePart($encounterSigned,$fixtures['soap/910001'],'locked','valueBoolean')===true && notePart($encounterSigned,$fixtures['soap/910001'],'signatureRecorded','valueBoolean')===false,'encounter lock does not invent an individual form signature');
    sqlStatement('DELETE FROM esign_signatures WHERE `table` = ? AND tid = 910001',['form_encounter']);
    sqlStatement('UPDATE forms SET deleted = 1 WHERE id = ?', [$fixtures['soap/910001']]);
    $removed = exported($encounterA, $token);
    check(outputValue($removed,'noteCount','valueUnsignedInt') === 2 && revisionOf($removed) !== revisionOf($signed), 'removing a note changes membership and revision');
    sqlStatement('UPDATE forms SET deleted = 0 WHERE id = ?', [$fixtures['soap/910001']]);
    $unknown = sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name) VALUES (910001, 910001, 1, ?, ?)', ['unimplemented_form','Unknown form']);
    check(str_contains(exported($encounterA, $token, 422)['issue'][0]['diagnostics'], 'unimplemented_form'), 'an unsupported form is named in the error');
    // The native layout lookup would read LBF_A as a LIKE pattern, also matching LBF1A.
    sqlStatement('UPDATE forms SET formdir = ? WHERE id = ?', ['LBF_A', $unknown]);
    check(str_contains(exported($encounterA, $token, 422)['issue'][0]['diagnostics'], 'LBF_A'), 'a layout name with an underscore is unsupported');
    sqlStatement('DELETE FROM forms WHERE id = ?', [$unknown]);
    $billing = sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name) VALUES (910001, 910001, 1, ?, ?)', ['misc_billing_options','Misc Billing Options']);
    $withBilling = exported($encounterA, $token);
    check(in_array('misc_billing_options', excludedTypes($withBilling), true) && outputValue($withBilling, 'noteCount', 'valueUnsignedInt') === 3, 'Misc Billing Options is excluded, not a note');
    sqlStatement('DELETE FROM forms WHERE id = ?', [$billing]);
    $duplicate = sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name) VALUES (910002, 910002, ?, ?, ?)', [$lbfId,$layout,'Ambiguous note']);
    exported($encounterA, $token, 409);
    sqlStatement('UPDATE forms SET formdir = ? WHERE id = ?', ['LBFdifferent', $duplicate]);
    exported($encounterA, $token, 409);
    sqlStatement('DELETE FROM forms WHERE id = ?', [$duplicate]);
    sqlStatement('UPDATE form_soap SET pid = 910002 WHERE id = ?', [$soapId]);
    exported($encounterA, $token, 409);
    sqlStatement('UPDATE form_soap SET pid = 910001 WHERE id = ?', [$soapId]);
    sqlStatement('UPDATE layout_options SET uor = 0 WHERE form_id = ? AND field_id = ?', [$layout,'narrative']);
    exported($encounterA, $token, 422);
    sqlStatement('UPDATE layout_options SET uor = 1 WHERE form_id = ? AND field_id = ?', [$layout,'narrative']);
    sqlStatement('UPDATE lbf_data SET field_value = ? WHERE form_id = ? AND field_id = ?', ['0',$lbfId,'narrative']);
    exported($encounterA,$token,422);
    sqlStatement('UPDATE lbf_data SET field_value = ? WHERE form_id = ? AND field_id = ?', ['ALPHA EDITED LBF',$lbfId,'narrative']);
    sqlStatement('UPDATE layout_options SET data_type = 25 WHERE form_id = ? AND field_id = ?', [$layout,'narrative']);
    exported($encounterA,$token,422);
    sqlStatement('UPDATE layout_options SET data_type = 3 WHERE form_id = ? AND field_id = ?', [$layout,'narrative']);
    // A history-sourced field whose column history_data lacks.
    sqlStatement('UPDATE layout_options SET source = ? WHERE form_id = ? AND field_id = ?', ['H',$layout,'narrative']);
    exported($encounterA,$token,422);
    sqlStatement('UPDATE layout_options SET source = ? WHERE form_id = ? AND field_id = ?', ['F',$layout,'narrative']);
    // An unclosed comment in template HTML ends with its own note, and an image inside it never prints. The forms test
    // below checks later notes still print.
    sqlStatement('UPDATE lbf_data SET field_value = ? WHERE form_id = ? AND field_id = ?', ['<p>open <!-- <img src="old-logo.png"></p>',$lbfId,'template']);
    exported($encounterA,$token);
    sqlStatement('UPDATE lbf_data SET field_value = ? WHERE form_id = ? AND field_id = ?', ['<p>ALPHA <strong>TEMPLATE</strong> NOTE &amp; more</p>',$lbfId,'template']);
    $renderer = $GLOBALS['fileroot'] . '/interface/forms/soap/report.php';
    check(rename($renderer, $renderer . '.raport-test'), 'temporarily remove local SOAP renderer');
    try { exported($encounterA, $token, 422); } finally { rename($renderer . '.raport-test', $renderer); }
    // Long source content must survive page breaks, including the final paragraphs.
    $longText = implode("\n", array_map(fn($n) => sprintf('LONG-LINE-%03d Synthetic clinical narrative for complete page-break verification.', $n), range(1, 120)));
    sqlStatement('UPDATE form_soap SET subjective = ? WHERE id = ?', [$longText,$soapId]);
    $longExport = exported($encounterA,$token);
    file_put_contents('/module-local/artifacts/export-long.pdf',base64_decode(outputValue($longExport,'document','resource')['content'][0]['attachment']['data'],true));
    sqlStatement('UPDATE form_soap SET subjective = ? WHERE id = ?', ['ALPHA EDITED SOAP',$soapId]);
    // A second active clinical entry shares one form registration but has its own author/date.
    $extraClinical = sqlInsert('INSERT INTO form_clinical_notes (form_id,pid,encounter,date,user,activity,description) VALUES (910001,910001,910001,CURDATE(),?,1,?)',['oe-system','SECOND CLINICAL ENTRY']);
    $multiple = exported($encounterA,$token);
    $clinicalParts = array_values(array_filter($multiple['parameter'],fn($p) => $p['name']==='note' && (int)array_column($p['part'],null,'name')['registrationId']['valueString']===$fixtures['clinical_notes/910001']))[0]['part'];
    check(count(array_filter($clinicalParts,fn($p)=>$p['name']==='sourceRecord'))===2,'multiple clinical entries preserve per-record provenance');
    file_put_contents('/module-local/artifacts/export-multiple.pdf',base64_decode(outputValue($multiple,'document','resource')['content'][0]['attachment']['data'],true));
    sqlStatement('DELETE FROM form_clinical_notes WHERE id = ?',[$extraClinical]);
    [$status] = callApi('/apis/default/fhir/Encounter/'.$encounterA.'/$raport-document?patient='.$patientB,token:$token);
    check($status===400,'unsupported patient query is rejected rather than pretending to constrain export');
    // A known revision skips the PDF only while it still matches.
    $current = exported($encounterA, $token);
    $known = exported($encounterA, $token, 200, '?knownRevision=' . revisionOf($current));
    check($known['parameter'] === array_values(array_filter($current['parameter'], fn($p) => $p['name'] !== 'document')), 'matching knownRevision returns the same inventory without the PDF');
    $stale = exported($encounterA, $token, 200, '?knownRevision=' . str_repeat('0', 64));
    check(in_array('document', array_column($stale['parameter'], 'name'), true), 'stale knownRevision returns the PDF');
    exported($encounterA, $token, 400, '?knownRevision=ABC');
    $legacy = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getWrapper();
    $legacy->set('pid',910002);
    $GLOBALS['pid']=910002;
    $nativeBefore=$_SESSION;
    $globalKeys=array_flip(['pid','encounter','attendant_type','CPR','item_count','cell_count','last_group']);
    $globalBefore=array_intersect_key($GLOBALS,$globalKeys);
    $directRequest=new \OpenEMR\Common\Http\HttpRestRequest();
    $directRequest->setRequestSite('default');
    $directRequest->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
    $directRequest->getSession()->set('authUser','oe-system');
    $directRequest->getSession()->set('authUserID',sqlQuery('SELECT id FROM users WHERE username = ?',['oe-system'])['id']);
    (new \Raport\OpenEmr\EncounterDocument())->export($encounterA,null,$directRequest);
    check($_SESSION===$nativeBefore && array_intersect_key($GLOBALS,$globalKeys)===$globalBefore,'native identity and patient context restored after success');
    $calls=0;
    $mutation=function($event) use (&$calls,$soapId): void {
        if ($event->getFormName()==='soap' && ++$calls===2) {
            sqlStatement('UPDATE form_soap SET subjective = ? WHERE id = ?',['CONCURRENT EDIT',$soapId]);
        }
    };
    $eventName=\OpenEMR\Events\Encounter\LoadEncounterFormFilterEvent::EVENT_NAME;
    $GLOBALS['kernel']->getEventDispatcher()->addListener($eventName,$mutation);
    try {
        try {
            (new \Raport\OpenEmr\EncounterDocument())->export($encounterA,null,$directRequest);
            throw new RuntimeException('Expected concurrent edit rejection.');
        } catch (\Raport\OpenEmr\OperationProblem $problem) {
            check($problem->status===409,'edit during rendering rejects mixed export');
        }
    } finally {
        $GLOBALS['kernel']->getEventDispatcher()->removeListener($eventName,$mutation);
        sqlStatement('UPDATE form_soap SET subjective = ? WHERE id = ?',['ALPHA EDITED SOAP',$soapId]);
    }
    check($_SESSION===$nativeBefore && array_intersect_key($GLOBALS,$globalKeys)===$globalBefore,'native context restored after interrupted export');
    // A history-sourced field (the clinic's HPI) shows the value saved while its note was the patient's newest.
    sqlStatement('INSERT INTO layout_options (form_id, field_id, group_id, title, seq, data_type, uor, source) VALUES (?, ?, ?, ?, ?, ?, 1, ?)', [$layout, 'additional_history', '1', 'History of present illness', 6, 3, 'H']);
    sqlStatement('UPDATE forms SET date = ? WHERE id = ?', ['2026-01-01 09:00:00', $fixtures[$layout . '/910001']]);
    $secondLbfId = (int) sqlInsert('INSERT INTO lbf_data (field_id, field_value) VALUES (?, ?)', ['narrative', 'ALPHA SECOND VISIT']);
    sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name, user, date, authorized) VALUES (910001, 910003, ?, ?, ?, ?, ?, 1)', [$secondLbfId, $layout, 'Layout Note', 'admin', '2026-02-01 09:00:00']);
    // The second note's own row lands in the second its form is created, so the boundary is strict.
    foreach (['2026-01-01 09:00:01' => 'FIRST VISIT HPI', '2026-02-01 09:00:00' => 'SECOND VISIT HPI'] as $date => $hpi) {
        sqlStatement('INSERT INTO history_data (pid, date, additional_history) VALUES (910001, ?, ?)', [$date, $hpi]);
    }
    $firstVisit = exported($encounterA, $token);
    $secondVisit = exported($encounterC, $token);
    file_put_contents('/module-local/artifacts/export-hpi-first.pdf', base64_decode(outputValue($firstVisit, 'document', 'resource')['content'][0]['attachment']['data'], true));
    file_put_contents('/module-local/artifacts/export-hpi-second.pdf', base64_decode(outputValue($secondVisit, 'document', 'resource')['content'][0]['attachment']['data'], true));
    sqlStatement('INSERT INTO history_data (pid, date, additional_history) VALUES (910001, ?, ?)', ['2026-03-01 09:00:00', 'LATER HPI']);
    check(revisionOf(exported($encounterA, $token)) === revisionOf($firstVisit), 'a later history save leaves an earlier note unchanged');
    check(revisionOf(exported($encounterC, $token)) !== revisionOf($secondVisit), 'a later history save changes the newest note');
    // A note whose only content is its history value has no lbf_data rows.
    sqlStatement('DELETE FROM lbf_data WHERE form_id = ?', [$secondLbfId]);
    check(outputValue(exported($encounterC, $token), 'noteCount', 'valueUnsignedInt') === 1, 'a note with only a history value exports');
    // Every field of the four one-row forms: text gets a marker, Review of Systems choices are YES, checkboxes are on.
    // The layout note before them ends in an unclosed tag, which must not hide them.
    sqlStatement('INSERT INTO lbf_data (form_id, field_id, field_value) VALUES (?, ?, ?)', [$secondLbfId, 'template', '<p>OPEN TAG NOTE</p><title>']);
    $expected = ['markers' => ['OPEN TAG NOTE', 'DICTATION MARKER', 'DICTATION NOTES MARKER', 'ROS CHECKS NOTES MARKER', 'INSTRUCTIONS MARKER'], 'ros' => 0, 'reviewofs' => 0];
    $rowForms = [
        'dictation' => ['dictation' => 'DICTATION MARKER', 'additional_notes' => 'DICTATION NOTES MARKER'],
        'reviewofs' => ['additional_notes' => 'ROS CHECKS NOTES MARKER'],
        'ros' => [],
        'clinical_instructions' => ['instruction' => 'INSTRUCTIONS MARKER', 'encounter' => '910003'],
    ];
    $answerColumns = fn(string $formdir) => array_diff(array_column(\OpenEMR\Common\Database\QueryUtils::fetchRecords('SHOW COLUMNS FROM form_' . $formdir), 'Field'), ['id', 'date', 'pid', 'user', 'groupname', 'authorized', 'activity', 'encounter']);
    $fileForm = function (string $formdir, array $values): void {
        $id = (int) sqlInsert('INSERT INTO form_' . $formdir . ' SET pid = 910001, activity = 1, ' . implode(', ', array_map(fn($column) => "`$column` = ?", array_keys($values))), array_values($values));
        sqlInsert('INSERT INTO forms (pid, encounter, form_id, formdir, form_name, user, date, authorized) VALUES (910001, 910003, ?, ?, ?, ?, NOW(), 1)', [$id, $formdir, $formdir, 'admin']);
    };
    foreach ($rowForms as $formdir => $values) {
        foreach ($answerColumns($formdir) as $column) {
            if (!isset($values[$column])) {
                $values[$column] = $formdir === 'ros' ? 'YES' : 'on';
                $expected[$formdir]++;
            }
        }
        $fileForm($formdir, $values);
    }
    $rowFormsExport = exported($encounterC, $token);
    check(outputValue($rowFormsExport, 'noteCount', 'valueUnsignedInt') === 5, 'dictation, both review of systems forms and clinical instructions export as notes');
    file_put_contents('/module-local/artifacts/export-forms.pdf', base64_decode(outputValue($rowFormsExport, 'document', 'resource')['content'][0]['attachment']['data'], true));
    file_put_contents('/module-local/artifacts/export-forms.json', json_encode($expected, JSON_THROW_ON_ERROR));
    sqlStatement('UPDATE form_clinical_instructions SET encounter = ? WHERE pid = 910001', ['910001']);
    exported($encounterC, $token, 409);
    sqlStatement('UPDATE form_clinical_instructions SET encounter = ? WHERE pid = 910001', ['910003']);
    // Saved untouched, Review of Systems stores N/A for every answer, which its report skips.
    $fileForm('ros', array_fill_keys($answerColumns('ros'), 'N/A'));
    $fileForm('dictation', ['dictation' => '', 'additional_notes' => '']);
    sqlStatement('UPDATE form_reviewofs SET activity = 0 WHERE pid = 910001');
    $withBlank = exported($encounterC, $token);
    check(outputValue($withBlank, 'noteCount', 'valueUnsignedInt') === 4 && excludedTypes($withBlank) === ['dictation', 'reviewofs', 'ros'], 'forms saved blank or with only inactive rows are excluded');
    // OpenEMR reads TIMESTAMP columns at the current UTC offset, which daylight saving changes.
    sqlStatement('UPDATE layout_group_properties SET grp_last_update = NOW() WHERE grp_form_id = ?', [$layout]);
    sqlStatement('UPDATE form_clinical_instructions SET date = NOW() WHERE pid = 910001');
    $zone = sqlQuery('SELECT @@session.time_zone AS zone')['zone'];
    $readings = [];
    try {
        foreach (['-05:00', '-06:00'] as $offset) {
            sqlStatement('SET time_zone = ?', [$offset]);
            $readings[] = [revisionOf((new \Raport\OpenEmr\EncounterDocument())->export($encounterC, null, $directRequest)), sqlQuery('SELECT date FROM form_clinical_instructions WHERE pid = 910001')['date']];
        }
    } finally {
        sqlStatement('SET time_zone = ?', [$zone]);
    }
    check($readings[0][0] === $readings[1][0] && $readings[0][1] !== $readings[1][1], 'a daylight saving change leaves revisions unchanged');
    // Restrict the real OAuth system principal; no permission bypass in the module.
    foreach ($groups as $group) { $gacl->del_group_object($group,'users','oe-system','ARO'); }
    $gacl->clear_cache();
    exported($encounterA, $token, 403);
    $testGroup = $gacl->add_group('raport-export-proof', 'RAPORT Export Proof', $gacl->get_root_group_id(), 'ARO');
    $gacl->add_group_object($testGroup, 'users', 'oe-system', 'ARO');
    $testAcl = $gacl->add_acl(['patients'=>['demo'],'encounters'=>['notes']], null, [$testGroup], null, null, 1, 1, 'view', 'Disposable RAPORT test grant');
    check($testAcl !== false, 'grant only demographic and encounter-note reads');
    $gacl->clear_cache();
    exported($encounterA, $token);
    sqlStatement('UPDATE layout_group_properties SET grp_aco_spec = ? WHERE grp_form_id = ? AND grp_group_id = ?', ['admin|super',$layout,'']);
    exported($encounterA, $token, 403);
    sqlStatement('UPDATE layout_group_properties SET grp_aco_spec = ? WHERE grp_form_id = ? AND grp_group_id = ?', ['encounters|notes',$layout,'']);
    sqlStatement('UPDATE form_encounter SET sensitivity = ? WHERE encounter = 910001', ['raport-restricted']);
    exported($encounterA, $token, 403);
    sqlStatement('UPDATE form_encounter SET sensitivity = NULL WHERE encounter = 910001');
    sqlStatement('UPDATE patient_data SET squad = ? WHERE pid = 910001', ['raport-restricted']);
    exported($encounterA, $token, 403);
    sqlStatement('UPDATE patient_data SET squad = ? WHERE pid = 910001', ['']);
    $registryAcl = sqlQuery('SELECT aco_spec FROM registry WHERE directory = ?',['soap'])['aco_spec'];
    sqlStatement('UPDATE registry SET aco_spec = ? WHERE directory = ?',['admin|super','soap']);
    try { exported($encounterA,$token,403); } finally { sqlStatement('UPDATE registry SET aco_spec = ? WHERE directory = ?',[$registryAcl,'soap']); }
    $audit = sqlQuery('SELECT COUNT(*) AS total, SUM(request_body <> ? OR response <> ?) AS bodies FROM api_log WHERE request = ? AND patient_id = 910001', ['', '', 'Encounter.$raport-document']);
    check((int)$audit['total'] > 0 && (int)$audit['bodies'] === 0, 'metadata audit retained without PDF/clinical response bodies');
} finally {
    if ($testAcl !== null && $testAcl !== false) { $gacl->del_acl($testAcl); }
    if ($testGroup !== null) { $gacl->del_group($testGroup, true, 'ARO'); }
    foreach ($groups as $group) { $gacl->add_group_object($group,'users','oe-system','ARO'); }
    $gacl->clear_cache();
    foreach ($globalsBefore as $name=>$value) { sqlStatement('UPDATE globals SET gl_value = ? WHERE gl_name = ?',[$value,$name]); }
    sqlStatement('DELETE FROM esign_signatures WHERE `table` = ? AND tid = 910001',['form_encounter']);
    foreach ($fixtures as $id) { sqlStatement('DELETE FROM esign_signatures WHERE `table` = ? AND tid = ?', ['forms',$id]); }
    foreach (['forms','form_soap','form_clinical_notes','form_dictation','form_reviewofs','form_ros','form_clinical_instructions','form_encounter','history_data','patient_data'] as $table) { sqlStatement("DELETE FROM $table WHERE pid IN (910001,910002)"); }
    foreach (array_filter([$lbfId, $secondLbfId]) as $id) { sqlStatement('DELETE FROM lbf_data WHERE form_id = ?',[$id]); }
    sqlStatement('DELETE FROM layout_options WHERE form_id = ?',[$layout]);
    sqlStatement('DELETE FROM layout_group_properties WHERE grp_form_id = ?',[$layout]);
}
check(!sqlQuery('SELECT pid FROM patient_data WHERE pid IN (910001,910002)'), 'HTTP fixture charts removed');
