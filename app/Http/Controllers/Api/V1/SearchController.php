<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Search\SearchRequest;
use App\Http\Resources\Api\V1\SearchProjectResource;
use App\Http\Resources\Api\V1\SearchTaskResource;
use App\Http\Resources\Api\V1\SearchUserResource;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;

class SearchController extends BaseApiController
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function index(SearchRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $this->searchService->search(
            actor: $actor,
            q: $request->queryString(),
            types: $request->types(),
            perType: $request->perType(),
        );

        return $this->successResponse(
            data: [
                'users' => SearchUserResource::collection($result['data']['users'])->resolve(),
                'projects' => SearchProjectResource::collection($result['data']['projects'])->resolve(),
                'tasks' => SearchTaskResource::collection($result['data']['tasks'])->resolve(),
            ],
            message: 'Search completed successfully.',
            meta: $result['meta'],
        );
    }
}
