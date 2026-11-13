<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\AcademicPeriodService;

/** GET /api/periods (registry.view): períodos letivos com a regra de frequência; o vigente tem "active": true. */
final class PeriodApiController extends ApiController
{
    public function __construct(private readonly AcademicPeriodService $periods)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        return $this->paginateList($this->periods->list(), $page, $perPage);
    }
}
