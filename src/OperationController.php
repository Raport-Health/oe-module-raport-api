<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\EventAuditLogger;
use Symfony\Component\HttpFoundation\JsonResponse;

final class OperationController
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const HEADERS = ['Content-Type' => 'application/fhir+json', 'Cache-Control' => 'no-store'];

    public function document(string $encounterUuid, HttpRestRequest $request): JsonResponse
    {
        return $this->respond($request, 'raport-document', 'Encounter.$raport-document', function () use ($encounterUuid, $request): array {
            $uuid = $this->instance($encounterUuid, $request, ['knownRevision']);
            $known = $request->query->all()['knownRevision'] ?? null;
            if ($known !== null && (!is_string($known) || !preg_match('/^[0-9a-f]{64}$/D', $known))) {
                throw new OperationProblem(400, 'invalid', 'knownRevision must be a revision returned by this operation.');
            }
            return (new EncounterDocument())->export($uuid, $known, $request);
        });
    }

    public function visits(HttpRestRequest $request): JsonResponse
    {
        return $this->respond($request, 'raport-visits', '$raport-visits', fn(): array => (new Visits())->read($request));
    }

    public function problems(string $patientUuid, HttpRestRequest $request): JsonResponse
    {
        return $this->respond($request, 'raport-problems', 'Patient.$raport-problems',
            fn(): array => (new Problems())->read($this->instance($patientUuid, $request), $request));
    }

    public function labs(string $patientUuid, HttpRestRequest $request): JsonResponse
    {
        return $this->respond($request, 'raport-labs', 'Patient.$raport-labs',
            fn(): array => (new Labs())->read($this->instance($patientUuid, $request), $request));
    }

    private function instance(string $uuid, HttpRestRequest $request, array $parameters = []): string
    {
        if (!preg_match(self::UUID, $uuid)) {
            throw new OperationProblem(400, 'invalid', 'A UUID is required in the path.');
        }
        if (array_diff($request->query->keys(), $parameters) !== []) {
            throw new OperationProblem(400, 'invalid', 'This operation does not accept that query parameter.');
        }
        return $uuid;
    }

    private function respond(HttpRestRequest $request, string $label, string $operation, \Closure $read): JsonResponse
    {
        // The host checks the operation scope before dispatch. Only OAuth system clients are served.
        if ($request->getRequestUserRole() !== 'system') {
            return $this->outcome(403, 'forbidden', 'This operation requires an OAuth system client.');
        }
        // Replace body logging with a metadata-only audit using the host audit service.
        $request->attributes->set('skipResponseLogging', true);
        $status = 500;
        try {
            $resource = $read();
            $status = 200;
            return new JsonResponse($resource, 200, self::HEADERS);
        } catch (OperationProblem $problem) {
            $status = $problem->status;
            return $this->outcome($status, $problem->issue, $problem->getMessage());
        } finally {
            $this->audit($request, $status, $label, $operation);
        }
    }

    // Metadata only: the URL carries no query string and neither body is stored.
    private function audit(HttpRestRequest $request, int $status, string $label, string $operation): void
    {
        $session = $request->getSession();
        $patientId = $request->attributes->get('raportPatientId');
        EventAuditLogger::getInstance()->recordLogItem($status === 200 ? 1 : 0, 'api', $session->get('authUser'), $session->get('authProvider'), $label . ' HTTP ' . $status, $patientId, 'api', 'open-emr', null, null, '', [
            'user_id' => (int) $session->get('authUserID'), 'patient_id' => $patientId ?? 0,
            'method' => 'GET', 'request' => $operation,
            'request_url' => $request->getBaseUrl() . $request->getPathInfo(), 'request_body' => '', 'response' => '',
        ]);
    }

    private function outcome(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'resourceType' => 'OperationOutcome',
            'issue' => [['severity' => 'error', 'code' => $code, 'diagnostics' => $message]],
        ], $status, self::HEADERS);
    }
}
