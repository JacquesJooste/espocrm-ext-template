<?php

namespace Espo\Modules\ElevateResourceManagement\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\ElevateResourceManagement\Service\ApplicationService;

final class Endpoint implements Action
{
    public function __construct(private ApplicationService $service) {}

    public function process(Request $request): Response
    {
        $operation = $request->getRouteParam('operation');

        if (!is_string($operation)) {
            throw new BadRequest('Missing operation.');
        }

        $body = (array) $request->getParsedBody();
        $result = match ($operation) {
            'context' => $this->service->context(
                $this->routeString($request, 'entityType'),
                $this->routeString($request, 'id')
            ),
            'contextBulk' => $this->service->contextBulk($body),
            'createPackage' => $this->service->createPackage($body),
            'updateScheduledBlock' => $this->service->updateScheduledBlock(
                $this->routeString($request, 'id'),
                $body
            ),
            'reportIn' => $this->service->reportIn($body),
            'milestone' => $this->service->milestone($this->routeString($request, 'id'), $body),
            'finish' => $this->service->finish($this->routeString($request, 'id'), $body),
            'manualEntry' => $this->service->manualEntry($body),
            'capacity' => $this->service->capacity([
                'instanceId' => $request->getQueryParam('instanceId'),
                'from' => $request->getQueryParam('from'),
                'to' => $request->getQueryParam('to'),
            ]),
            'report' => $this->service->report($body),
            'billing' => $this->service->billing(
                $this->routeString($request, 'id'),
                $this->routeString($request, 'billingAction'),
                $body
            ),
            default => throw new BadRequest('Unknown operation.'),
        };

        return ResponseComposer::json($result);
    }

    private function routeString(Request $request, string $name): string
    {
        $value = $request->getRouteParam($name);

        if (!is_string($value) || $value === '') {
            throw new BadRequest("Missing route parameter: $name.");
        }

        return $value;
    }
}
