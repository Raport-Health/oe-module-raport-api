<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Uuid\UuidRegistry;

final class Visits
{
    // calendar_owner is the host calendar-user rule, which ignores active. It is NULL, so false, when a column is NULL.
    private const PROVIDERS = "SELECT id, uuid, username, fname, lname, npi, active, (username != '' AND authorized = 1 AND calendar = 1) AS calendar_owner FROM users WHERE ";
    // Category and facility Location are joined as the host AppointmentService::search joins them for FHIR Appointment.
    private const APPOINTMENTS = "SELECT e.pc_eid, e.uuid, e.pc_aid, e.pc_eventDate, e.pc_startTime, e.pc_endTime, e.pc_apptstatus, e.pc_recurrtype, p.pid, p.uuid AS patient_uuid, p.squad, c.pc_constant_id, c.pc_catname, m.uuid AS location_uuid FROM openemr_postcalendar_events e JOIN patient_data p ON p.pid = e.pc_pid LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = e.pc_catid LEFT JOIN facility f ON f.id = e.pc_facility LEFT JOIN uuid_mapping m ON m.target_uuid = f.uuid AND m.resource = 'Location' WHERE ";
    // The host FHIR Appointment status of each default pc_apptstatus (FhirAppointmentService::parseOpenEMRRecord). The host
    // reads a clinic's own status as pending; here it fails, since nobody has decided what it means.
    private const STATUS = ['-' => 'proposed', '#' => 'pending', '^' => 'pending', '>' => 'fulfilled', '$' => 'fulfilled', 'AVM' => 'booked', 'SMS' => 'booked', 'EMAIL' => 'booked', '*' => 'booked', '%' => 'cancelled', '!' => 'cancelled', 'x' => 'cancelled', '?' => 'noshow', '~' => 'arrived', '@' => 'arrived', '<' => 'checked-in', '+' => 'checked-in', 'CALL' => 'waitlist'];
    // The category's ACO spec as AclMain::fetchPostCalendarCategoryACO reads it, joined to skip a query per row.
    private const ENCOUNTERS = 'SELECT fe.id, fe.uuid, fe.encounter, fe.pid, fe.date, fe.reason, fe.provider_id, fe.sensitivity, c.aco_spec, p.uuid AS patient_uuid, p.squad FROM form_encounter fe JOIN patient_data p ON p.pid = fe.pid LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid WHERE ';
    // The Flow Board's current tracker row: same patient, date, start time and appointment id. Encounter 0 is blank, and a recurring appointment is never linked.
    private const LINKS = 'SELECT e.pc_eid, t.encounter FROM openemr_postcalendar_events e JOIN patient_tracker t ON t.pid = e.pc_pid AND t.apptdate = e.pc_eventDate AND t.appttime = e.pc_startTime AND t.eid = e.pc_eid WHERE t.encounter <> 0 AND e.pc_recurrtype = 0 AND e.pc_eid';
    private const LINKERS = 'SELECT DISTINCT e.pc_eid FROM form_encounter fe JOIN patient_tracker t ON t.encounter = fe.encounter AND t.pid = fe.pid JOIN openemr_postcalendar_events e ON t.pid = e.pc_pid AND t.apptdate = e.pc_eventDate AND t.appttime = e.pc_startTime AND t.eid = e.pc_eid WHERE t.encounter <> 0 AND e.pc_recurrtype = 0 AND fe.id';

    private string $user;
    private array $allowed = [];

    public function read(HttpRestRequest $request): array
    {
        $scope = $this->scope($request->query->all());
        $this->user = $request->getSession()->get('authUser');
        $this->allow('admin|users');
        if ($scope !== null) {
            $this->allow('patients|appt');
            $this->allow('encounters|auth_a');
        }
        // The backfill commits its own transactions, so it runs before the snapshot.
        UuidRegistry::createMissingUuidsForTables(['users', 'patient_data', 'form_encounter', 'openemr_postcalendar_events']);
        QueryUtils::sqlStatementThrowException('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return QueryUtils::inTransaction(fn(): array => $scope === null
            ? $this->parameters($this->records(QueryUtils::fetchRecords(self::PROVIDERS . "username != '' AND authorized = 1 AND calendar = 1"), 'id'), [], [], [])
            : $this->visits($scope, $request));
    }

    private function scope(array $query): ?array
    {
        if (array_filter($query, 'is_string') !== $query) {
            throw new OperationProblem(400, 'invalid', 'Query parameters must be single values.');
        }
        $names = array_keys($query);
        sort($names);
        return match ($names) {
            [] => null,
            ['patient'] => preg_match(OperationController::UUID, $query['patient']) ? ['patient' => $query['patient']]
                : throw new OperationProblem(400, 'invalid', 'A patient UUID is required.'),
            ['end', 'start'] => $this->window($query['start'], $query['end']),
            default => throw new OperationProblem(400, 'invalid', 'Use no parameters, patient, or start and end.'),
        };
    }

    private function window(string $start, string $end): array
    {
        $utc = new \DateTimeZone('UTC');
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $utc);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $utc);
        if (!$first || !$last || $first->format('Y-m-d') !== $start || $last->format('Y-m-d') !== $end) {
            throw new OperationProblem(400, 'invalid', 'start and end must be YYYY-MM-DD dates.');
        }
        if ($first > $last || $first->diff($last)->days > 30) {
            throw new OperationProblem(400, 'invalid', 'The window must run forward and span at most 31 days.');
        }
        return ['start' => $start, 'end' => $end];
    }

    private function visits(array $scope, HttpRestRequest $request): array
    {
        if (isset($scope['patient'])) {
            $pid = (int) (QueryUtils::fetchRecords('SELECT pid FROM patient_data WHERE uuid = ?', [UuidRegistry::uuidToBytes($scope['patient'])])[0]['pid']
                ?? throw new OperationProblem(404, 'not-found', 'Patient not found.'));
            $request->attributes->set('raportPatientId', $pid);
            $appointments = $this->records(QueryUtils::fetchRecords(self::APPOINTMENTS . 'p.pid = ?', [$pid]), 'pc_eid', 'pc_eventDate');
            $encounters = $this->records(QueryUtils::fetchRecords(self::ENCOUNTERS . 'fe.pid = ?', [$pid]), 'id', 'date');
        } else {
            $appointments = $this->records(QueryUtils::fetchRecords(
                self::APPOINTMENTS . "(e.pc_eventDate BETWEEN ? AND ?) OR (e.pc_recurrtype <> 0 AND e.pc_eventDate <= ? AND (e.pc_endDate >= ? OR e.pc_endDate = '0000-00-00'))",
                [$scope['start'], $scope['end'], $scope['end'], $scope['start']],
            ), 'pc_eid', 'pc_eventDate');
            $encounters = $this->records(QueryUtils::fetchRecords(self::ENCOUNTERS . 'fe.date >= ? AND fe.date < DATE_ADD(?, INTERVAL 1 DAY)', [$scope['start'], $scope['end']]), 'id', 'date');
        }
        [$links, $linked] = $this->link($appointments);
        $encounters += $linked;
        // One closure round is enough: a linker's link targets an encounter already in the set.
        $linkers = array_diff(array_column($this->in(self::LINKERS, array_keys($encounters)), 'pc_eid'), array_keys($appointments));
        $more = $this->records($this->in(self::APPOINTMENTS . 'e.pc_eid', $linkers), 'pc_eid', 'pc_eventDate');
        [$moreLinks] = $this->link($more);
        $links += $moreLinks;
        $appointments += $more;

        foreach ($encounters as $row) {
            if ($row['sensitivity']) {
                $this->allow('sensitivities|' . $row['sensitivity']);
            }
            $this->allow($row['aco_spec']);
        }
        foreach ([...$appointments, ...$encounters] as $row) {
            if ($row['squad']) {
                $this->allow('squads|' . $row['squad']);
            }
        }

        $ids = array_map('intval', [...array_column($appointments, 'pc_aid'), ...array_column($encounters, 'provider_id')]);
        $providers = $this->records($this->in(self::PROVIDERS . 'id', array_unique(array_filter($ids, fn(int $id): bool => $id > 0))), 'id');
        return $this->parameters($providers, $encounters, $appointments, $links);
    }

    // Each current link must resolve to exactly one encounter of the appointment's own patient.
    private function link(array $appointments): array
    {
        $numbers = [];
        foreach ($this->in(self::LINKS, array_keys($appointments)) as $row) {
            if (($numbers[$row['pc_eid']] ?? (int) $row['encounter']) !== (int) $row['encounter']) {
                throw new OperationProblem(409, 'conflict', 'Appointment ' . UuidRegistry::uuidToString($appointments[$row['pc_eid']]['uuid']) . ' has conflicting encounter links.');
            }
            $numbers[$row['pc_eid']] = (int) $row['encounter'];
        }
        $candidates = [];
        foreach ($this->records($this->in(self::ENCOUNTERS . 'fe.encounter', array_unique($numbers)), 'id', 'date') as $id => $row) {
            $candidates[(int) $row['encounter']][$id] = $row;
        }
        $links = [];
        $linked = [];
        foreach ($numbers as $eid => $number) {
            $matches = $candidates[$number] ?? [];
            $id = array_key_first($matches);
            if (count($matches) !== 1 || (int) $matches[$id]['pid'] !== (int) $appointments[$eid]['pid']) {
                throw new OperationProblem(409, 'conflict', 'Appointment ' . UuidRegistry::uuidToString($appointments[$eid]['uuid']) . ' has an unresolvable encounter link.');
            }
            $links[$eid] = $id;
            $linked[$id] = $matches[$id];
        }
        return [$links, $linked];
    }

    private function in(string $sql, array $ids): array
    {
        return $ids === [] ? [] : QueryUtils::fetchRecords($sql . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids));
    }

    // Every loaded record is returned or fails the request, so unusable records are refused here.
    private function records(array $rows, string $key, ?string $date = null): array
    {
        $records = [];
        foreach ($rows as $row) {
            if (strlen((string) $row['uuid']) !== 16 || (array_key_exists('patient_uuid', $row) && strlen((string) $row['patient_uuid']) !== 16)) {
                throw new OperationProblem(409, 'conflict', 'A visit record has no usable UUID.');
            }
            if ($date !== null && ($row[$date] === null || str_starts_with($row[$date], '0000-00-00'))) {
                throw new OperationProblem(409, 'conflict', 'Visit ' . UuidRegistry::uuidToString($row['uuid']) . ' has no usable date.');
            }
            $records[$row[$key]] = $row;
        }
        return $records;
    }

    /** A host ACO spec, "section|value"; an empty spec allows. Cached: a window's rows share a few specs. */
    private function allow(?string $spec): void
    {
        if (!($this->allowed[(string) $spec] ??= AclMain::aclCheckAcoSpec($spec, $this->user))) {
            throw new OperationProblem(403, 'forbidden', 'The system principal cannot read every requested visit.');
        }
    }

    private function parameters(array $providers, array $encounters, array $appointments, array $links): array
    {
        $uuid = fn(array $row, string $column = 'uuid'): string => UuidRegistry::uuidToString($row[$column]);
        $out = ['provider' => [], 'encounter' => [], 'appointment' => []];
        foreach ($providers as $id => $row) {
            $parts = [['name' => 'uuid', 'valueString' => $uuid($row)], ['name' => 'id', 'valueInteger' => (int) $id]];
            foreach (['username' => 'username', 'fname' => 'given', 'lname' => 'family', 'npi' => 'npi'] as $column => $name) {
                if ((string) $row[$column] !== '') {
                    $parts[] = ['name' => $name, 'valueString' => $row[$column]];
                }
            }
            $parts[] = ['name' => 'active', 'valueBoolean' => (int) $row['active'] === 1];
            $parts[] = ['name' => 'calendarOwner', 'valueBoolean' => (int) $row['calendar_owner'] === 1];
            $out['provider'][$uuid($row)] = $parts;
        }
        foreach ($encounters as $row) {
            $parts = [['name' => 'uuid', 'valueString' => $uuid($row)], ['name' => 'patient', 'valueString' => $uuid($row, 'patient_uuid')], ['name' => 'date', 'valueString' => $row['date']]];
            if (isset($providers[(int) $row['provider_id']])) {
                $parts[] = ['name' => 'provider', 'valueString' => $uuid($providers[(int) $row['provider_id']])];
            }
            if ((string) $row['reason'] !== '') {
                $parts[] = ['name' => 'reason', 'valueString' => $row['reason']];
            }
            $out['encounter'][$uuid($row)] = $parts;
        }
        foreach ($appointments as $eid => $row) {
            $parts = [['name' => 'uuid', 'valueString' => $uuid($row)], ['name' => 'patient', 'valueString' => $uuid($row, 'patient_uuid')], ['name' => 'date', 'valueDate' => $row['pc_eventDate']]];
            if ($row['pc_startTime'] !== null) {
                $parts[] = ['name' => 'time', 'valueTime' => $row['pc_startTime']];
            }
            if ($row['pc_endTime'] !== null) {
                // The host reads an end of 24:00 or later as a time on the next day.
                $end = new \DateTimeImmutable($row['pc_eventDate'] . ' ' . $row['pc_endTime'], new \DateTimeZone('UTC'));
                $parts[] = ['name' => 'end', 'valueString' => $end->format('Y-m-d H:i:s')];
            }
            if (isset($providers[(int) $row['pc_aid']])) {
                $parts[] = ['name' => 'provider', 'valueString' => $uuid($providers[(int) $row['pc_aid']])];
            }
            $parts[] = ['name' => 'status', 'valueCode' => self::STATUS[$row['pc_apptstatus']]
                ?? throw new OperationProblem(409, 'conflict', 'Appointment ' . $uuid($row) . ' has an unsupported status.')];
            foreach (['pc_constant_id' => 'typeCode', 'pc_catname' => 'typeDisplay'] as $column => $name) {
                if ((string) $row[$column] !== '') {
                    $parts[] = ['name' => $name, 'valueString' => $row[$column]];
                }
            }
            if ($row['location_uuid'] !== null) {
                $parts[] = ['name' => 'location', 'valueString' => $uuid($row, 'location_uuid')];
            }
            $parts[] = ['name' => 'recurring', 'valueBoolean' => (int) $row['pc_recurrtype'] !== 0];
            if (isset($links[$eid])) {
                $parts[] = ['name' => 'encounter', 'valueString' => $uuid($encounters[$links[$eid]])];
            }
            $out['appointment'][$uuid($row)] = $parts;
        }
        $parameters = [['name' => 'complete', 'valueBoolean' => true]];
        foreach ($out as $name => $rows) {
            ksort($rows, SORT_STRING);
            foreach ($rows as $parts) {
                $parameters[] = ['name' => $name, 'part' => $parts];
            }
        }
        return ['resourceType' => 'Parameters', 'parameter' => $parameters];
    }
}
