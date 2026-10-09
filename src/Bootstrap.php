<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class Bootstrap
{
    public const SCOPE = 'system/Encounter.$raport-document';
    public const ROUTE = 'GET /fhir/Encounter/:encounterUuid/$raport-document';
    public const VISITS_SCOPE = 'system/*.$raport-visits';
    public const VISITS_ROUTE = 'GET /fhir/$raport-visits';
    public const PROBLEMS_SCOPE = 'system/Patient.$raport-problems';
    public const PROBLEMS_ROUTE = 'GET /fhir/Patient/:patientUuid/$raport-problems';
    public const LABS_SCOPE = 'system/Patient.$raport-labs';
    public const LABS_ROUTE = 'GET /fhir/Patient/:patientUuid/$raport-labs';

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, function (RestApiCreateEvent $event): void {
            $controller = new OperationController();
            $event->addToFHIRRouteMap(self::ROUTE, [$controller, 'document']);
            $event->addToFHIRRouteMap(self::VISITS_ROUTE, [$controller, 'visits']);
            $event->addToFHIRRouteMap(self::PROBLEMS_ROUTE, [$controller, 'problems']);
            $event->addToFHIRRouteMap(self::LABS_ROUTE, [$controller, 'labs']);
        });
        $dispatcher->addListener(RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES, function (RestApiScopeEvent $event): void {
            if ($event->getApiType() === RestApiScopeEvent::API_TYPE_FHIR && $event->isSystemScopesEnabled()) {
                $event->addScope('system', 'Encounter', '$raport-document');
                $event->addScope('system', '*', '$raport-visits');
                $event->addScope('system', 'Patient', '$raport-problems');
                $event->addScope('system', 'Patient', '$raport-labs');
            }
        });
        $dispatcher->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event): void {
            $request = $event->getRequest();
            if (!$request instanceof HttpRestRequest || $event->getResponse()->getStatusCode() !== 200) {
                return;
            }
            $path = $request->getRequestPathWithoutSite();
            if (!in_array($path, ['/fhir/metadata', '/fhir/OperationDefinition'], true)) {
                return;
            }
            $definition = json_decode(file_get_contents(__DIR__ . '/../OperationDefinition.json'), true, 512, JSON_THROW_ON_ERROR);
            $visits = json_decode(file_get_contents(__DIR__ . '/../OperationDefinition-visits.json'), true, 512, JSON_THROW_ON_ERROR);
            $problems = json_decode(file_get_contents(__DIR__ . '/../OperationDefinition-problems.json'), true, 512, JSON_THROW_ON_ERROR);
            $labs = json_decode(file_get_contents(__DIR__ . '/../OperationDefinition-labs.json'), true, 512, JSON_THROW_ON_ERROR);
            $body = json_decode($event->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/fhir/OperationDefinition') {
                $body['entry'][] = ['fullUrl' => $definition['url'], 'resource' => $definition];
                $body['entry'][] = ['fullUrl' => $visits['url'], 'resource' => $visits];
                $body['entry'][] = ['fullUrl' => $problems['url'], 'resource' => $problems];
                $body['entry'][] = ['fullUrl' => $labs['url'], 'resource' => $labs];
                $body['total'] = count($body['entry']);
            } else {
                foreach ($body['rest'] as &$rest) {
                    foreach ($rest['resource'] as &$resource) {
                        if ($resource['type'] === 'Encounter') {
                            $resource['operation'][] = ['name' => 'raport-document', 'definition' => $definition['url']];
                        }
                        if ($resource['type'] === 'Patient') {
                            $resource['operation'][] = ['name' => 'raport-problems', 'definition' => $problems['url']];
                            $resource['operation'][] = ['name' => 'raport-labs', 'definition' => $labs['url']];
                        }
                    }
                    unset($resource);
                    $rest['operation'][] = ['name' => 'raport-visits', 'definition' => $visits['url']];
                }
                unset($rest);
            }
            $event->getResponse()->setContent(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        });
    }
}
