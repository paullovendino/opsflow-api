<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Reports\IndexEmployeeReportsRequest;
use App\Http\Requests\Api\V1\Reports\IndexProjectReportsRequest;
use App\Http\Requests\Api\V1\Reports\ShowEmployeeReportRequest;
use App\Http\Requests\Api\V1\Reports\ShowProjectReportRequest;
use App\Http\Resources\Api\V1\EmployeeReportResource;
use App\Http\Resources\Api\V1\ProjectReportResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends BaseApiController
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    public function projects(IndexProjectReportsRequest $request): JsonResponse
    {
        $this->authorize('viewAnyProjectReports');

        /** @var User $actor */
        $actor = $request->user();

        $reports = $this->reportService->projectReports(
            actor: $actor,
            filters: $request->filters(),
        );

        return $this->paginatedResponse(
            paginator: $reports,
            data: ProjectReportResource::collection($reports)->resolve(),
            message: 'Project reports retrieved successfully.',
        );
    }

    public function project(ShowProjectReportRequest $request, Project $project): JsonResponse
    {
        $this->authorize('viewProjectReport', $project);

        $report = $this->reportService->projectReport(
            project: $project,
            dateRange: $request->dateRange(),
        );

        return $this->successResponse(
            data: new ProjectReportResource($report),
            message: 'Project report retrieved successfully.',
        );
    }

    public function employees(IndexEmployeeReportsRequest $request): JsonResponse
    {
        $this->authorize('viewAnyEmployeeReports');

        $reports = $this->reportService->employeeReports(
            filters: $request->filters(),
        );

        return $this->paginatedResponse(
            paginator: $reports,
            data: EmployeeReportResource::collection($reports)->resolve(),
            message: 'Employee reports retrieved successfully.',
        );
    }

    public function employee(ShowEmployeeReportRequest $request, User $user): JsonResponse
    {
        $this->authorize('viewEmployeeReport', $user);

        $report = $this->reportService->employeeReport(
            user: $user,
            dateRange: $request->dateRange(),
        );

        return $this->successResponse(
            data: new EmployeeReportResource($report),
            message: 'Employee report retrieved successfully.',
        );
    }
}
