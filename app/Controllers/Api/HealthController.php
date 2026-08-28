<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Support\SystemStatus;

/**
 * Used by the Docker health check and by CI as a smoke test.
 * Returns 200 when everything is wired up and 503 when it is not.
 */
final class HealthController
{
    public function __construct(private readonly SystemStatus $status)
    {
    }

    public function show(Request $request): Response
    {
        $report = $this->status->report();

        return Response::json($report, $report['ok'] ? 200 : 503);
    }
}
