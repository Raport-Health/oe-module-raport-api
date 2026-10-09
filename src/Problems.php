<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\CodeTypesService;

final class Problems
{
    // The problem list rows the host FHIR Condition services read, resolved problems included.
    private const PROBLEMS = "SELECT id, uuid, title, diagnosis, begdate, enddate FROM lists WHERE type = 'medical_problem' AND activity = 1 AND pid = ?";
    // Every encounter link of those problems, once per encounter carrying the linked number.
    private const LINKS = "SELECT ie.id, ie.list_id, ie.pid, fe.uuid, fe.pid AS encounter_pid FROM lists l JOIN issue_encounter ie ON ie.list_id = l.id LEFT JOIN form_encounter fe ON fe.encounter = ie.encounter WHERE l.type = 'medical_problem' AND l.activity = 1 AND l.pid = ? ORDER BY fe.uuid";

    private string $user;

    public function read(string $patientUuid, HttpRestRequest $request): array
    {
        $this->user = $request->getSession()->get('authUser');
        $this->allow('patients|med');
        // The backfill commits its own transactions, so it runs before the snapshot.
        UuidRegistry::createMissingUuidsForTables(['lists', 'patient_data', 'form_encounter']);
        QueryUtils::sqlStatementThrowException('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return QueryUtils::inTransaction(function () use ($patientUuid, $request): array {
            $patient = QueryUtils::fetchRecords('SELECT pid, squad FROM patient_data WHERE uuid = ?', [UuidRegistry::uuidToBytes($patientUuid)])[0]
                ?? throw new OperationProblem(404, 'not-found', 'Patient not found.');
            $pid = (int) $patient['pid'];
            $request->attributes->set('raportPatientId', $pid);
            if ($patient['squad']) {
                $this->allow('squads|' . $patient['squad']);
            }
            return $this->parameters($pid);
        });
    }

    private function parameters(int $pid): array
    {
        // The host loads the code tables that lookup_code_description reads only with BaseService.
        require_once $GLOBALS['fileroot'] . '/custom/code_types.inc.php';
        $codes = new CodeTypesService();
        $uuids = [];
        $problems = [];
        foreach (QueryUtils::fetchRecords(self::PROBLEMS, [$pid]) as $row) {
            if (strlen((string) $row['uuid']) !== 16) {
                throw new OperationProblem(409, 'conflict', 'A problem has no usable UUID.');
            }
            $uuid = UuidRegistry::uuidToString($row['uuid']);
            if (trim((string) $row['title']) === '') {
                throw new OperationProblem(409, 'conflict', "Problem $uuid has no title.");
            }
            $uuids[$row['id']] = $uuid;
            $parts = [['name' => 'uuid', 'valueString' => $uuid], ['name' => 'title', 'valueString' => $row['title']]];
            foreach (['begdate', 'enddate'] as $column) {
                if ($row[$column] !== null && !str_starts_with($row[$column], '0000-00-00')) {
                    $parts[] = ['name' => $column, 'valueString' => $row[$column]];
                }
            }
            // Split as the host's addCoding does, but in source order and without keying by code, which drops codes.
            foreach (array_unique(array_filter(array_map('trim', explode(';', (string) $row['diagnosis'])), 'strlen')) as $item) {
                ['code_type' => $type, 'code' => $code] = $codes->parseCode($item);
                if ((string) $type === '' || $code === '') {
                    throw new OperationProblem(409, 'conflict', "Problem $uuid has a diagnosis code without a type.");
                }
                $system = $codes->getSystemForCodeType($type);
                $description = $codes->lookup_code_description($item);
                $parts[] = ['name' => 'code', 'part' => [
                    ['name' => 'type', 'valueString' => $type],
                    ['name' => 'code', 'valueString' => $code],
                    ...($system === null ? [] : [['name' => 'system', 'valueString' => $system]]),
                    ...(trim($description) === '' ? [] : [['name' => 'description', 'valueString' => $description]]),
                ]];
            }
            $problems[$uuid] = $parts;
        }
        // Each link must resolve to exactly one encounter, and the link and encounter belong to the problem's patient.
        $linked = [];
        foreach (QueryUtils::fetchRecords(self::LINKS, [$pid]) as $row) {
            $uuid = $uuids[$row['list_id']];
            if (isset($linked[$row['id']]) || strlen((string) $row['uuid']) !== 16 || (int) $row['pid'] !== $pid || (int) $row['encounter_pid'] !== $pid) {
                throw new OperationProblem(409, 'conflict', "Problem $uuid has an unresolvable encounter link.");
            }
            $linked[$row['id']] = true;
            $problems[$uuid][] = ['name' => 'encounter', 'valueString' => UuidRegistry::uuidToString($row['uuid'])];
        }
        ksort($problems, SORT_STRING);
        $parameters = [['name' => 'complete', 'valueBoolean' => true]];
        foreach ($problems as $parts) {
            $parameters[] = ['name' => 'problem', 'part' => $parts];
        }
        return ['resourceType' => 'Parameters', 'parameter' => $parameters];
    }

    private function allow(string $spec): void
    {
        if (!AclMain::aclCheckAcoSpec($spec, $this->user)) {
            throw new OperationProblem(403, 'forbidden', 'The system principal cannot read this problem list.');
        }
    }
}
