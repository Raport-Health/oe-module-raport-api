<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Uuid\UuidRegistry;

final class Labs
{
    private const TABLES = ['patient_data', 'form_encounter', 'procedure_order', 'procedure_report', 'procedure_result', 'documents'];
    // Native text stays text: no guessed LOINC/UCUM, status translation, float conversion or range splitting.
    private const ORDER = ['procedure_order_type' => 'type', 'order_status' => 'status', 'order_priority' => 'priority', 'order_intent' => 'intent', 'date_ordered' => 'orderedAtLocal', 'date_collected' => 'collectedAtLocal', 'date_transmitted' => 'transmittedAtLocal', 'clinical_hx' => 'clinicalHistory', 'patient_instructions' => 'patientInstructions', 'order_diagnosis' => 'diagnosis', 'control_id' => 'controlId', 'external_id' => 'externalId', 'specimen_type' => 'specimenType', 'specimen_location' => 'specimenLocation', 'specimen_volume' => 'specimenVolume', 'specimen_fasting' => 'specimenFasting', 'lab_id' => 'labId', 'lab_name' => 'labName', 'provider_id' => 'providerId', 'provider_name' => 'providerName'];
    private const TEST = ['procedure_code' => 'code', 'procedure_name' => 'name', 'procedure_type' => 'type', 'diagnoses' => 'diagnoses', 'procedure_source' => 'source', 'do_not_send' => 'doNotSend', 'transport' => 'transport', 'date_end' => 'endAtLocal', 'reason_code' => 'reasonCode', 'reason_description' => 'reasonDescription', 'reason_date_low' => 'reasonStartAtLocal', 'reason_date_high' => 'reasonEndAtLocal', 'reason_status' => 'reasonStatus'];
    private const REPORT = ['report_status' => 'status', 'review_status' => 'reviewStatus', 'date_report' => 'reportedAtLocal', 'date_report_tz' => 'reportedOffset', 'date_collected' => 'collectedAtLocal', 'date_collected_tz' => 'collectedOffset', 'specimen_num' => 'specimenIdentifier', 'report_notes' => 'notes'];
    private const RESULT = ['result_data_type' => 'dataType', 'result_code' => 'code', 'result_text' => 'name', 'result' => 'value', 'units' => 'units', 'range' => 'referenceRange', 'abnormal' => 'abnormal', 'result_status' => 'status', 'comments' => 'comments', 'facility' => 'facility', 'date' => 'resultAtLocal', 'date_end' => 'endAtLocal'];

    public function read(string $patientUuid, HttpRestRequest $request): array
    {
        $user = $request->getSession()->get('authUser');
        $this->allow('patients|med', $user);
        // Native backfill commits, so it runs before the read transaction, as in the other operations.
        UuidRegistry::createMissingUuidsForTables(self::TABLES);
        QueryUtils::sqlStatementThrowException('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return QueryUtils::inTransaction(function () use ($patientUuid, $request, $user): array {
            $patient = QueryUtils::fetchRecords('SELECT pid, squad FROM patient_data WHERE uuid = ?', [UuidRegistry::uuidToBytes($patientUuid)])[0]
                ?? throw new OperationProblem(404, 'not-found', 'Patient not found.');
            $pid = (int) $patient['pid'];
            $request->attributes->set('raportPatientId', $pid);
            if ($patient['squad']) {
                $this->allow('squads|' . $patient['squad'], $user);
            }
            return ['resourceType' => 'Parameters', 'parameter' => [
                ['name' => 'complete', 'valueBoolean' => true],
                ...$this->snapshot($pid, $user),
            ]];
        });
    }

    /**
     * The registered order form's source, shared with the lab operation; the encounter exporter checks twice.
     * Null for an inactive order: removing an order's last test deactivates it and leaves its form registered
     * (interface/forms/procedure_order/handle_deletions.php).
     */
    public function encounterOrder(int $orderId, int $pid, int $encounter, string $user): ?array
    {
        $this->allow('patients|med', $user);
        UuidRegistry::createMissingUuidsForTables(self::TABLES);
        $order = QueryUtils::fetchRecords('SELECT patient_id, encounter_id, activity FROM procedure_order WHERE procedure_order_id = ?', [$orderId])[0] ?? null;
        if ($order === null || (int) $order['patient_id'] !== $pid || (int) $order['encounter_id'] !== $encounter) {
            throw new OperationProblem(409, 'conflict', 'A registered order does not belong to this patient and encounter.');
        }
        return (int) $order['activity'] === 1 ? $this->snapshot($pid, $user, $orderId) : null;
    }

    private function snapshot(int $pid, string $user, ?int $orderId = null): array
    {
        $where = 'po.patient_id = ? AND po.activity = 1';
        $binds = [$pid];
        if ($orderId !== null) {
            $where .= ' AND po.procedure_order_id = ?';
            $binds[] = $orderId;
        }
        $orders = QueryUtils::fetchRecords("SELECT po.*, fe.uuid AS encounter_uuid, fe.pid AS encounter_pid, fe.sensitivity, fe.pc_catid, pp.name AS lab_name, CONCAT_WS(' ', u.fname, u.mname, u.lname) AS provider_name FROM procedure_order po LEFT JOIN form_encounter fe ON fe.encounter = po.encounter_id LEFT JOIN procedure_providers pp ON pp.ppid = po.lab_id LEFT JOIN users u ON u.id = po.provider_id WHERE $where ORDER BY po.uuid", $binds);
        $codes = QueryUtils::fetchRecords("SELECT pc.* FROM procedure_order_code pc JOIN procedure_order po ON po.procedure_order_id = pc.procedure_order_id WHERE $where ORDER BY po.uuid, pc.procedure_order_seq", $binds);
        $answers = QueryUtils::fetchRecords("SELECT a.* FROM procedure_answers a JOIN procedure_order po ON po.procedure_order_id = a.procedure_order_id WHERE $where ORDER BY po.uuid, a.procedure_order_seq, a.question_code, a.answer_seq", $binds);
        $reports = QueryUtils::fetchRecords("SELECT pr.* FROM procedure_report pr JOIN procedure_order po ON po.procedure_order_id = pr.procedure_order_id WHERE $where ORDER BY pr.uuid", $binds);
        $results = QueryUtils::fetchRecords("SELECT r.*, d.uuid AS document_uuid, d.foreign_id AS document_pid FROM procedure_result r JOIN procedure_report pr ON pr.procedure_report_id = r.procedure_report_id JOIN procedure_order po ON po.procedure_order_id = pr.procedure_order_id LEFT JOIN documents d ON d.id = r.document_id WHERE $where ORDER BY r.uuid", $binds);
        $entries = [];
        $orderUuids = [];
        foreach ($orders as $row) {
            $uuid = $this->uuid($row['uuid']);
            if (isset($orderUuids[$row['procedure_order_id']])) {
                throw new OperationProblem(409, 'conflict', "Order $uuid has an ambiguous encounter link.");
            }
            $orderUuids[$row['procedure_order_id']] = $uuid;
            $parts = [['name' => 'uuid', 'valueString' => $uuid], ...$this->strings($row, self::ORDER)];
            if ((int) $row['encounter_id'] !== 0) {
                if ((int) $row['encounter_pid'] !== $pid || strlen((string) $row['encounter_uuid']) !== 16) {
                    throw new OperationProblem(409, 'conflict', "Order $uuid has an unresolvable encounter link.");
                }
                if ($row['sensitivity']) {
                    $this->allow('sensitivities|' . $row['sensitivity'], $user);
                }
                $this->allow(AclMain::fetchPostCalendarCategoryACO($row['pc_catid']), $user);
                $parts[] = ['name' => 'encounter', 'valueString' => $this->uuid($row['encounter_uuid'])];
            }
            $entries[] = ['name' => 'order', 'part' => $parts];
        }
        $tests = [];
        foreach ($codes as $row) {
            $order = $orderUuids[$row['procedure_order_id']];
            $sequence = (int) $row['procedure_order_seq'];
            $tests[$order . ':' . $sequence] = true;
            $entries[] = ['name' => 'test', 'part' => [['name' => 'order', 'valueString' => $order], ['name' => 'sequence', 'valueInteger' => $sequence], ...$this->strings($row, self::TEST)]];
        }
        foreach ($answers as $row) {
            $order = $orderUuids[$row['procedure_order_id']];
            $sequence = (int) $row['procedure_order_seq'];
            $this->requireTest($tests, $order, $sequence);
            $entries[] = ['name' => 'answer', 'part' => [['name' => 'order', 'valueString' => $order], ['name' => 'sequence', 'valueInteger' => $sequence], ...$this->strings($row, ['question_code' => 'questionCode', 'answer_seq' => 'answerSequence', 'answer' => 'value', 'procedure_code' => 'procedureCode'])]];
        }
        $reportUuids = [];
        foreach ($reports as $row) {
            $uuid = $this->uuid($row['uuid']);
            $order = $orderUuids[$row['procedure_order_id']];
            $sequence = (int) $row['procedure_order_seq'];
            $this->requireTest($tests, $order, $sequence);
            $reportUuids[$row['procedure_report_id']] = $uuid;
            $entries[] = ['name' => 'report', 'part' => [['name' => 'uuid', 'valueString' => $uuid], ['name' => 'order', 'valueString' => $order], ['name' => 'sequence', 'valueInteger' => $sequence], ...$this->strings($row, self::REPORT)]];
        }
        foreach ($results as $row) {
            $uuid = $this->uuid($row['uuid']);
            $parts = [['name' => 'uuid', 'valueString' => $uuid], ['name' => 'report', 'valueString' => $reportUuids[$row['procedure_report_id']]], ...$this->strings($row, self::RESULT)];
            if ((int) $row['document_id'] !== 0) {
                $this->allow('patients|docs', $user);
                if ((int) $row['document_pid'] !== $pid || strlen((string) $row['document_uuid']) !== 16) {
                    throw new OperationProblem(409, 'conflict', "Result $uuid has an unresolvable document link.");
                }
                $parts[] = ['name' => 'document', 'valueString' => $this->uuid($row['document_uuid'])];
            }
            $entries[] = ['name' => 'result', 'part' => $parts];
        }
        return $entries;
    }

    private function strings(array $row, array $fields): array
    {
        $parts = [];
        foreach ($fields as $column => $name) {
            $value = $row[$column];
            if ($value !== null && $value !== '' && !(str_ends_with($name, 'AtLocal') && str_starts_with($value, '0000-00-00'))) {
                $parts[] = ['name' => $name, 'valueString' => (string) $value];
            }
        }
        return $parts;
    }

    private function uuid(?string $bytes): string
    {
        if (strlen((string) $bytes) !== 16) {
            throw new OperationProblem(409, 'conflict', 'A lab record has no usable UUID.');
        }
        return UuidRegistry::uuidToString($bytes);
    }

    private function requireTest(array $tests, string $order, int $sequence): void
    {
        if (!isset($tests[$order . ':' . $sequence])) {
            throw new OperationProblem(409, 'conflict', "Order $order has a report or answer without an ordered test.");
        }
    }

    /** A host ACO spec, "section|value"; an empty spec allows. */
    private function allow(?string $spec, string $user): void
    {
        if (!AclMain::aclCheckAcoSpec($spec, $user)) {
            throw new OperationProblem(403, 'forbidden', 'The system principal cannot read these lab records.');
        }
    }
}
