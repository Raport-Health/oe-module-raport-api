<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use Dompdf\Dompdf;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Forms\FormLocator;
use OpenEMR\Common\Forms\FormReportRenderer;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;

final class EncounterDocument
{
    // Forms stored as one form_<directory> row whose id is forms.form_id, printed by their native report.php.
    private const ROW_FORMS = ['soap', 'dictation', 'reviewofs', 'ros', 'clinical_instructions'];
    // Native table rows cannot split across PDF pages. Flow cells vertically so long notes remain complete.
    private const STYLE ='body{font-family:DejaVu Sans;font-size:10pt}table,thead,tbody,tfoot,tr,td,th{display:block;width:auto;height:auto}h2{margin-top:22pt;page-break-after:avoid}p{white-space:pre-wrap}.provenance{font-size:8pt;color:#444}';

    public function export(string $uuid, ?string $knownRevision, HttpRestRequest $request): array
    {
        $session = SessionWrapperFactory::getInstance()->getWrapper();
        // Native LBF rendering reads patient/encounter globals as well as the session.
        $keys = ['pid', 'encounter', 'attendant_type', 'CPR', 'item_count', 'cell_count', 'last_group'];
        $globals = array_intersect_key($GLOBALS, array_flip($keys));
        $sessionKeys = ['pid', 'encounter', 'attendant_type', 'authUser', 'authUserID'];
        $savedSession = [];
        foreach ($sessionKeys as $key) {
            if ($session->has($key)) {
                $savedSession[$key] = $session->get($key);
            }
        }
        try {
            // Symfony's API session and the legacy form/ACL session use different bags.
            // Bridge only the authenticated OAuth principal; never substitute a human admin.
            foreach (['authUser', 'authUserID'] as $key) {
                $session->set($key, $request->getSession()->get($key));
            }
            $snapshot = $this->snapshot($uuid, $request);
            $revision = $this->revision($snapshot);
            $parameters = [
                ['name' => 'complete', 'valueBoolean' => true],
                ['name' => 'revision', 'valueString' => $revision],
                ['name' => 'noteCount', 'valueUnsignedInt' => count($snapshot['notes'])],
            ];
            foreach ($snapshot['encounterSignatures'] as $signature) {
                $parameters[] = ['name' => 'encounterSignature', 'part' => $this->signatureParts($signature)];
            }
            foreach ($snapshot['excluded'] as $form) {
                $parameters[] = ['name' => 'excludedForm', 'part' => [
                    ['name' => 'registrationId', 'valueString' => (string) $form['id']],
                    ['name' => 'formType', 'valueString' => $form['formdir']],
                ]];
            }
            foreach ($snapshot['notes'] as $note) {
                $form = $note['form'];
                $parts = [
                    ['name' => 'registrationId', 'valueString' => (string) $form['id']],
                    ['name' => 'sourceFormId', 'valueString' => (string) $form['form_id']],
                    ['name' => 'formType', 'valueString' => $form['formdir']],
                    ['name' => 'revision', 'valueString' => $this->revision($note)],
                    ['name' => 'signatureRecorded', 'valueBoolean' => count($note['signatures']) > 0],
                    ['name' => 'locked', 'valueBoolean' => $note['locked']],
                ];
                foreach (['user' => 'authorUsername', 'date' => 'registeredAtLocal'] as $field => $name) {
                    if ($form[$field] !== null && $form[$field] !== '') {
                        $parts[] = ['name' => $name, 'valueString' => $form[$field]];
                    }
                }
                foreach ($note['signatures'] as $signature) {
                    $parts[] = ['name' => 'signature', 'part' => $this->signatureParts($signature)];
                }
                if (in_array($form['formdir'], ['clinical_notes', ...self::ROW_FORMS], true)) {
                    foreach ($note['source'] as $row) {
                        $sourceParts = [['name' => 'id', 'valueString' => (string) $row['id']]];
                        // form_ros has no user column.
                        foreach (['user' => 'authorUsername', 'date' => 'authoredAtLocal'] as $field => $name) {
                            if (isset($row[$field]) && $row[$field] !== '') {
                                $sourceParts[] = ['name' => $name, 'valueString' => $row[$field]];
                            }
                        }
                        $parts[] = ['name' => 'sourceRecord', 'part' => $sourceParts];
                    }
                }
                $parameters[] = ['name' => 'note', 'part' => $parts];
            }
            // The caller already holds this revision's PDF; building it again is most of an export's cost.
            if ($revision === $knownRevision) {
                return ['resourceType' => 'Parameters', 'parameter' => $parameters];
            }
            if ($snapshot['notes'] !== []) {
                $html = $this->documentHtml($snapshot);
                $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'allowedProtocols' => []]);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->render();
                $bytes = $pdf->output();
                $parameters[] = ['name' => 'document', 'resource' => [
                    'resourceType' => 'DocumentReference', 'status' => 'current',
                    'identifier' => [['system' => 'urn:raport:openemr:encounter-document', 'value' => $request->getRequestSite() . '/' . $uuid]],
                    'subject' => ['reference' => 'Patient/' . $snapshot['encounter']['patientUuid']],
                    'context' => ['encounter' => [['reference' => 'Encounter/' . $uuid]]],
                    'date' => gmdate('Y-m-d\TH:i:s\Z'),
                    'description' => 'Current encounter note rendering with recorded signature evidence; not a verified historical signed snapshot.',
                    'content' => [['attachment' => ['contentType' => 'application/pdf', 'title' => 'Encounter notes', 'data' => base64_encode($bytes), 'size' => strlen($bytes)]]],
                ]];
            }
            // Two reads detect concurrent edits without locks; a durable immutable snapshot needs host support.
            if ($revision !== $this->revision($this->snapshot($uuid, $request))) {
                throw new OperationProblem(409, 'conflict', 'Encounter content changed during export. Retry the request.');
            }
            return ['resourceType' => 'Parameters', 'parameter' => $parameters];
        } finally {
            foreach ($keys as $key) {
                unset($GLOBALS[$key]);
            }
            foreach ($globals as $key => $value) {
                $GLOBALS[$key] = $value;
            }
            foreach ($sessionKeys as $key) {
                $session->remove($key);
            }
            foreach ($savedSession as $key => $value) {
                $session->set($key, $value);
            }
        }
    }

    private function snapshot(string $uuid, HttpRestRequest $request): array
    {
        $this->allow('patients|demo');
        $this->allow('encounters|notes');
        $encounter = sqlQuery('SELECT e.encounter, e.pid, e.date, e.sensitivity, e.pc_catid, p.uuid AS patient_uuid, p.fname, p.lname, p.DOB, p.squad FROM form_encounter e JOIN patient_data p ON p.pid = e.pid WHERE e.uuid = ?', [UuidRegistry::uuidToBytes($uuid)]);
        if (!$encounter) {
            throw new OperationProblem(404, 'not-found', 'Encounter not found.');
        }
        if ((int) sqlQuery('SELECT COUNT(*) AS total FROM form_encounter WHERE encounter = ?', [$encounter['encounter']])['total'] !== 1) {
            throw new OperationProblem(409, 'conflict', 'Encounter identifier has ambiguous ownership.');
        }
        if ($encounter['sensitivity']) {
            $this->allow('sensitivities|' . $encounter['sensitivity']);
        }
        if ($encounter['squad']) {
            $this->allow('squads|' . $encounter['squad']);
        }
        $this->allow(AclMain::fetchPostCalendarCategoryACO($encounter['pc_catid']));
        if (strlen((string) $encounter['patient_uuid']) !== 16) {
            throw new OperationProblem(409, 'conflict', 'The encounter patient has no usable UUID.');
        }
        $encounter['patientUuid'] = UuidRegistry::uuidToString($encounter['patient_uuid']);
        unset($encounter['patient_uuid']);
        $request->attributes->set('raportPatientId', (int) $encounter['pid']);
        $session = SessionWrapperFactory::getInstance()->getWrapper();
        foreach (['pid' => $encounter['pid'], 'encounter' => $encounter['encounter'], 'attendant_type' => 'pid'] as $key => $value) {
            $GLOBALS[$key] = $value;
            $session->set($key, $value);
        }
        $forms = QueryUtils::fetchRecords('SELECT id, form_id, formdir, form_name, date, user FROM forms WHERE pid = ? AND encounter = ? AND deleted = 0 ORDER BY id', [$encounter['pid'], $encounter['encounter']]);
        $notes = [];
        $excluded = [];
        require_once $GLOBALS['srcdir'] . '/ESign/Form/Factory.php';
        foreach ($forms as $form) {
            // The encounter header and vitals have dedicated resources, and Misc Billing Options holds claim fields, not
            // note text. Every other unknown form fails visibly.
            if (in_array($form['formdir'], ['newpatient', 'vitals', 'misc_billing_options'], true)) {
                $excluded[] = $form;
                continue;
            }
            // No underscore: native display_layout_rows finds the layout with LIKE, where _ matches any character.
            $isLbf = (bool) preg_match('/^LBF[A-Za-z0-9-]+$/D', $form['formdir']);
            if (!$isLbf && !in_array($form['formdir'], ['clinical_notes', 'procedure_order', ...self::ROW_FORMS], true)) {
                throw new OperationProblem(422, 'not-supported', 'Encounter contains an unsupported note form: ' . $form['formdir'] . '.');
            }
            // All LBF types share one lbf_data keyspace, including deleted registrations. CCDA import registers each
            // encounter's clinical notes under one shared form_id, so those rows are read by encounter, as the native report does.
            $owners = match (true) {
                $isLbf => QueryUtils::fetchRecords("SELECT id FROM forms WHERE form_id = ? AND formdir LIKE 'LBF%'", [$form['form_id']]),
                $form['formdir'] === 'clinical_notes' => QueryUtils::fetchRecords('SELECT id FROM forms WHERE form_id = ? AND formdir = ? AND pid = ? AND encounter = ?', [$form['form_id'], $form['formdir'], $encounter['pid'], $encounter['encounter']]),
                default => QueryUtils::fetchRecords('SELECT id FROM forms WHERE form_id = ? AND formdir = ?', [$form['form_id'], $form['formdir']]),
            };
            if (count($owners) !== 1 || (string) $owners[0]['id'] !== (string) $form['id']) {
                throw new OperationProblem(409, 'conflict', 'A note has ambiguous encounter ownership.');
            }
            $source = $this->source($form, $encounter, $isLbf);
            $html = $source === null ? null : $this->render($form, $encounter, $isLbf, $source);
            $signatures = $this->signatures('forms', $form['id']);
            // An inactive order, or a form saved blank, has nothing to print. A signature on one may carry an amendment.
            if ($html === null && $signatures === []) {
                $excluded[] = $form;
                continue;
            }
            $signable = (new \ESign\Form_Factory($form['id'], $form['formdir'], $encounter['encounter']))->createSignable();
            $notes[] = ['form' => $form, 'source' => $source,
                'signatures' => $signatures, 'locked' => $signable->isLocked(),
                'html' => $html ?? ''];
        }
        // Bump 'format' whenever documentHtml changes: callers keep their PDF while the revision matches.
        return ['format' => 'raport-document-2', 'style' => self::STYLE, 'encounter' => $encounter, 'notes' => $notes, 'excluded' => $excluded,
            'encounterSignatures' => $this->signatures('form_encounter', $encounter['encounter'])];
    }

    /** Null for a procedure_order form whose order is inactive, or a layout note saved blank. */
    private function source(array $form, array $encounter, bool $isLbf): ?array
    {
        if ($isLbf) {
            // OpenEMR reads TIMESTAMP columns at the current UTC offset, so this layout-editor stamp shifts with daylight
            // saving. Nothing prints it.
            $layout = array_map(fn($row) => array_diff_key($row, ['grp_last_update' => true]), QueryUtils::fetchRecords('SELECT * FROM layout_group_properties WHERE grp_form_id = ? ORDER BY grp_group_id', [$form['formdir']]));
            // The table's primary key is (form id, group id), so a layout has at most one header row, group id ''.
            if (!(int) (array_column($layout, 'grp_activity', 'grp_group_id')[''] ?? 0)) {
                throw new OperationProblem(422, 'not-supported', 'A note layout is missing or inactive.');
            }
            // The only ACL check for these notes: display_layout_rows, which prints them, checks none.
            foreach ($layout as $group) {
                $this->allow($group['grp_aco_spec']);
            }
            // uor 0 hides a field, and the native renderer skips it.
            $fields = QueryUtils::fetchRecords('SELECT * FROM layout_options WHERE form_id = ? AND uor > 0 ORDER BY group_id, seq, field_id', [$form['formdir']]);
            $data = QueryUtils::fetchRecords('SELECT field_id, field_value FROM lbf_data WHERE form_id = ? ORDER BY field_id', [$form['form_id']]);
            // Supported field types: text (2), text area (3), provider (10), static text (31), template text (34).
            // Others read live chart data or file-scope globals this method does not have, and need their own tests first.
            require_once $GLOBALS['srcdir'] . '/options.inc.php';
            // Source H fields are stored per patient: every save adds a history_data row, and natively every note shows
            // the newest. A note's own value is the newest row saved before the patient's next note that writes history.
            // Which layouts write history is LBF/new.php's own rule: read-only (0) and hidden (H) fields are not saved.
            $history = sqlQuery("SELECT * FROM history_data WHERE pid = ? AND date < COALESCE((SELECT MIN(date) FROM forms WHERE pid = ? AND id > ? AND formdir IN (SELECT form_id FROM layout_options WHERE source = 'H' AND uor > 0 AND field_id != '' AND edit_options != 'H' AND edit_options NOT LIKE '%0%')), '9999-12-31') ORDER BY date DESC, id DESC LIMIT 1", [$encounter['pid'], $encounter['pid'], $form['id']]);
            $groupIds = array_column($layout, 'grp_group_id');
            $values = [];
            $stored = $data !== [];
            foreach ($fields as $field) {
                if (!in_array((int) $field['data_type'], [2, 3, 10, 31, 34], true)
                    || !in_array($field['source'], ['F', 'D', 'H'], true)
                    || str_contains($field['edit_options'], 'H')
                    || !in_array($field['group_id'], $groupIds, true)) {
                    throw new OperationProblem(422, 'not-supported', 'Note layout field ' . $field['field_id'] . ' uses an unqualified field type, source or group.');
                }
                // The native call still reports a missing history column as '*?*'.
                $value = lbf_current_value($field, $form['form_id'], $encounter['encounter']);
                if ($field['source'] === 'H' && $value !== '*?*') {
                    $value = $history[$field['field_id']] ?? '';
                    // Stored content too, though never in lbf_data.
                    $stored = $stored || $value !== '';
                }
                // Native display_layout_rows uses empty(), silently omitting literal zero.
                if ($value === '0' || $value === 0 || $value === false || $value === '*?*') {
                    throw new OperationProblem(422, 'not-supported', 'A note field cannot be represented completely by the native renderer.');
                }
                $values[$field['field_id']] = $value;
            }
            foreach ($data as $value) {
                if ($value['field_value'] !== '' && $value['field_value'] !== null && !array_key_exists($value['field_id'], $values)) {
                    throw new OperationProblem(422, 'not-supported', 'A populated note field has no visible layout definition.');
                }
            }
            // A blank save stores no lbf_data rows. Demographic fields would still print, so check stored content.
            if (!$stored) {
                return null;
            }
            return ['layout' => $layout, 'fields' => $fields, 'data' => $data, 'values' => $values];
        }
        $registry = sqlQuery('SELECT aco_spec FROM registry WHERE directory = ?', [$form['formdir']]);
        if (!$registry) {
            throw new OperationProblem(422, 'not-supported', 'A note form is not registered.');
        }
        $this->allow($registry['aco_spec']);
        if ($form['formdir'] === 'procedure_order') {
            $user = SessionWrapperFactory::getInstance()->getWrapper()->get('authUser');
            return (new Labs())->encounterOrder((int) $form['form_id'], (int) $encounter['pid'], (int) $encounter['encounter'], $user);
        }
        if ($form['formdir'] === 'clinical_notes') {
            $rows = QueryUtils::fetchRecords('SELECT * FROM form_clinical_notes WHERE form_id = ? AND pid = ? AND encounter = ? ORDER BY id', [$form['form_id'], $encounter['pid'], $encounter['encounter']]);
        } else {
            // The directory is one of ROW_FORMS, never request input.
            $rows = QueryUtils::fetchRecords('SELECT * FROM `form_' . $form['formdir'] . '` WHERE id = ?', [$form['form_id']]);
        }
        if ($rows === []) {
            throw new OperationProblem(409, 'conflict', 'A registered note has no stored content.');
        }
        foreach ($rows as &$row) {
            // Only some form tables record their encounter; those must name this one.
            if ((string) $row['pid'] !== (string) $encounter['pid'] || (array_key_exists('encounter', $row) && (string) $row['encounter'] !== (string) $encounter['encounter'])) {
                throw new OperationProblem(409, 'conflict', 'A note does not belong to the requested patient and encounter.');
            }
            // Clinical-note UUIDs are binary, and internal form/record IDs already identify this source. The native report
            // backfills a missing UUID, which also bumps last_updated, so neither can be in the snapshot.
            unset($row['uuid'], $row['last_updated']);
            // A TIMESTAMP, read at the current UTC offset, so it shifts with daylight saving. Its report does not print it.
            if ($form['formdir'] === 'clinical_instructions') {
                unset($row['date']);
            }
        }
        unset($row);
        // A form whose rows are all inactive prints nothing, so it is excluded like one saved blank.
        return array_values(array_filter($rows, fn($row) => (int) $row['activity'] === 1));
    }

    /** Null when the form prints nothing. */
    private function render(array $form, array $encounter, bool $isLbf, array $source): ?string
    {
        if ($form['formdir'] === 'procedure_order') {
            // The native report embeds scripts and omits order history/instructions. Render our qualified snapshot.
            $tests = $answers = $reports = $results = [];
            foreach ($source as $entry) {
                $parts = array_column($entry['part'], null, 'name');
                switch ($entry['name']) {
                    case 'order': $order = $entry; break;
                    case 'test': $tests[$parts['sequence']['valueInteger']] = $entry; break;
                    case 'answer': $answers[$parts['sequence']['valueInteger']][] = $entry; break;
                    case 'report': $reports[$parts['sequence']['valueInteger']][] = $entry; break;
                    case 'result': $results[$parts['report']['valueString']][] = $entry; break;
                }
            }
            $html = '<h3>Order</h3>' . $this->orderFields($order['part']);
            foreach ($tests as $sequence => $test) {
                $html .= '<h3>Ordered test</h3>' . $this->orderFields($test['part']);
                foreach ($answers[$sequence] ?? [] as $answer) {
                    $html .= '<h4>Order answer</h4>' . $this->orderFields($answer['part']);
                }
                foreach ($reports[$sequence] ?? [] as $report) {
                    $parts = array_column($report['part'], null, 'name');
                    $html .= '<h4>Report</h4>' . $this->orderFields($report['part']);
                    foreach ($results[$parts['uuid']['valueString']] ?? [] as $result) {
                        $html .= '<h4>Result</h4>' . $this->orderFields($result['part']);
                    }
                }
            }
            return $html;
        }
        ob_start();
        try {
            if ($isLbf) {
                // Native lbf_report minus its own value lookup, which would give every note the newest history value.
                echo "<table>\n";
                display_layout_rows($form['formdir'], $source['values']);
                echo "</table>\n";
            } else {
                $expected = $GLOBALS['fileroot'] . '/interface/forms/' . $form['formdir'] . '/report.php';
                $path = (new FormLocator())->findFile($form['formdir'], 'report.php', 'report');
                if (!is_file($expected) || realpath($path) !== realpath($expected)) {
                    throw new OperationProblem(422, 'not-supported', 'A native note renderer is missing or overridden.');
                }
                (new FormReportRenderer())->renderReport($form['formdir'], 'report', $encounter['pid'], $encounter['encounter'], 1, $form['form_id']);
            }
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        // Notes are joined into one document. Parse each alone with Dompdf's parser, so a tag or comment left open
        // closes at the end of its own note instead of hiding every later one.
        $html5 = new \Masterminds\HTML5(['disable_html_ns' => true]);
        $fragment = $html5->loadHTMLFragment($html);
        // Comments never print. Dropped, one around an old image cannot trip the asset check below.
        foreach ((new \DOMXPath($fragment->ownerDocument))->query('.//comment()', $fragment) as $comment) {
            $comment->remove();
        }
        $html = $html5->saveHTML($fragment);
        // For example Review of Systems saved untouched: every answer is N/A, which its report skips.
        if (trim(strip_tags($html)) === '') {
            return null;
        }
        // Text and table notes only. Refuse active content and assets rather than silently dropping them from the PDF.
        if (preg_match('/<(?:img|svg|object|embed|iframe|script|link|style|base|video|audio|canvas|input|textarea|select)\b|style\s*=\s*[\'\"][^\'\"]*\burl\s*\(/i', $html)) {
            throw new OperationProblem(422, 'not-supported', 'This note requires unsupported embedded assets or controls.');
        }
        return $html;
    }

    private function orderFields(array $parts): string
    {
        $html = '<p>';
        foreach ($parts as $part) {
            // Identity links and encoding details remain in the API/source revision, outside clinical prose.
            if (in_array($part['name'], ['uuid', 'order', 'report', 'encounter', 'labId', 'providerId', 'dataType'], true)) {
                continue;
            }
            $label = ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', $part['name']));
            $value = $part['valueString'] ?? (string) $part['valueInteger'];
            $html .= '<b>' . $this->escape($label) . ':</b> ' . $this->escape($value) . '<br>';
        }
        return $html . '</p>';
    }

    private function signatures(string $table, $id): array
    {
        // OpenEMR deactivates users and never deletes them, so every signer has a row.
        return QueryUtils::fetchRecords('SELECT s.id, s.uid, s.datetime, s.is_lock, s.amendment, s.hash, s.signature_hash, u.fname, u.lname FROM esign_signatures s JOIN users u ON u.id = s.uid WHERE s.`table` = ? AND s.tid = ? ORDER BY s.datetime, s.id', [$table, $id]);
    }

    private function signatureParts(array $signature): array
    {
        $parts = [
            ['name' => 'id', 'valueString' => (string) $signature['id']],
            ['name' => 'signerUserId', 'valueString' => (string) $signature['uid']],
            ['name' => 'recordedAtLocal', 'valueString' => $signature['datetime']],
            ['name' => 'lockRecorded', 'valueBoolean' => (bool) $signature['is_lock']],
            ['name' => 'verification', 'valueCode' => 'not-performed'],
        ];
        if ($signature['amendment'] !== null && $signature['amendment'] !== '') {
            $parts[] = ['name' => 'amendment', 'valueString' => $signature['amendment']];
        }
        $name = trim($signature['fname'] . ' ' . $signature['lname']);
        if ($name !== '') {
            $parts[] = ['name' => 'signerDisplay', 'valueString' => $name];
        }
        return $parts;
    }

    private function documentHtml(array $snapshot): string
    {
        $encounter = $snapshot['encounter'];
        $escape = $this->escape(...);
        $signed = fn(string $what, array $signature) => '<p class="provenance">' . $what . ' recorded: ' . $escape(trim($signature['fname'] . ' ' . $signature['lname'])) . ' at ' . $escape($signature['datetime']) . '. ' . $escape($signature['amendment']) . '</p>';
        $html ='<html><head><meta charset="UTF-8"><style>' . self::STYLE . '</style></head><body><h1>Encounter notes</h1><p>' . $escape($encounter['fname'] . ' ' . $encounter['lname']) . ' | DOB: ' . $escape($encounter['DOB']) . '<br>Encounter: ' . $escape($encounter['encounter']) . ' | Date (clinic local): ' . $escape($encounter['date']) . '</p><p class="provenance">Current rendering. Signature records are evidence of recorded actions, not cryptographic verification of this PDF or a historical snapshot.</p>';
        foreach ($snapshot['encounterSignatures'] as $signature) {
            $html .= $signed('Encounter signature', $signature);
        }
        foreach ($snapshot['notes'] as $note) {
            $html .= '<h2>' . $escape($note['form']['form_name']) . '</h2><p class="provenance">Form registration ' . $escape($note['form']['id']) . ' | Author: ' . $escape($note['form']['user']) . ' | Registered (clinic local): ' . $escape($note['form']['date']) . ' | Locked: ' . ($note['locked'] ? 'yes' : 'no') . '</p>';
            foreach ($note['signatures'] as $signature) {
                $html .= $signed('Signature', $signature);
            }
            $html .= $note['html'];
        }
        return $html . '</body></html>';
    }

    private function revision(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function escape(string|int|null $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A host ACO spec, "section|value"; an empty spec allows. */
    private function allow(?string $spec): void
    {
        if (!AclMain::aclCheckAcoSpec($spec)) {
            throw new OperationProblem(403, 'forbidden', 'The system principal cannot read all notes for this encounter.');
        }
    }
}
