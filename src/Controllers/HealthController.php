<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\SystemStatusService;

/** GET /health (público): {"status": "ok"|"degraded", "mongo": bool, "time": ISO-8601 UTC}; 503 sem MongoDB. */
final class HealthController extends Controller
{
    public function __construct(private readonly SystemStatusService $system)
    {
    }

    public function show(Request $request): Response
    {
        $health = $this->system->health();
        return Response::json($health, $health['mongo'] ? 200 : 503);
    }
}
