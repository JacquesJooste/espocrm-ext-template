<?php

namespace Espo\Modules\ElevateResourceManagement\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\ElevateResourceManagement\Service\ApplicationService;

final class ReconcileLifecycle implements JobDataLess
{
    public function __construct(private ApplicationService $service) {}

    public function run(): void
    {
        $this->service->reconcileAll();
    }
}
