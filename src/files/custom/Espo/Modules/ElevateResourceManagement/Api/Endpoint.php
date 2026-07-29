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

        $body = $this->normalizeObject($request->getParsedBody());
        $result = match ($operation) {
            'settings' => $this->service->settings(),
            'permissions' => $this->service->permissions(),
            'updateSettings' => $this->service->updateSettings($body),
            'myWork' => $this->service->myWork(),
            'workBlockComposition' => $this->service->workBlockComposition(
                $this->routeString($request, 'id')
            ),
            'createWorkBlock' => $this->service->createWorkBlock($body),
            'updateWorkBlockDefinition' => $this->service->updateWorkBlockDefinition(
                $this->routeString($request, 'id'),
                $body
            ),
            'context' => $this->service->context(
                $this->routeString($request, 'entityType'),
                $this->routeString($request, 'id')
            ),
            'contextBulk' => $this->service->contextBulk($body),
            'rollup' => $this->service->rollup(
                $this->routeString($request, 'entityType'),
                $this->routeString($request, 'id')
            ),
            'createPackage' => $this->service->createPackage($body),
            'attachWorkBlocks' => $this->service->attachWorkBlocks(
                $this->routeString($request, 'id'),
                $body
            ),
            'updateScheduledBlock' => $this->service->updateScheduledBlock(
                $this->routeString($request, 'id'),
                $body
            ),
            'rescheduleRemaining' => $this->service->rescheduleRemaining(
                $this->routeString($request, 'id'),
                $body
            ),
            'startTimer' => $this->service->startTimer($body),
            'stopTimer' => $this->service->stopTimer(
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
            'billingQueue' => $this->service->billingQueue(
                $this->routeString($request, 'id')
            ),
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

    /** @return array<string, mixed> */
    private function normalizeObject(mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return [];
        }

        return array_map(
            fn (mixed $item): mixed => is_array($item) || is_object($item)
                ? $this->normalizeValue($item)
                : $item,
            $value
        );
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
    }
}
