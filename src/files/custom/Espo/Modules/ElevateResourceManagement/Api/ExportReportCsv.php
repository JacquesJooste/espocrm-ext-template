<?php

namespace Espo\Modules\ElevateResourceManagement\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\ElevateResourceManagement\Service\ApplicationService;

final class ExportReportCsv implements Action
{
    public function __construct(private ApplicationService $service) {}

    public function process(Request $request): Response
    {
        $result = $this->service->report((array) $request->getParsedBody());

        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="elevate-resource-report.csv"')
            ->writeBody((string) $result['csv']);
    }
}
