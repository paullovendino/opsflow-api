<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    public function test_token_mismatch_on_api_route_returns_419_envelope(): void
    {
        $request = Request::create('/api/v1/auth/logout', 'POST');

        $response = (new ApiExceptionRenderer())->render(
            new TokenMismatchException('CSRF token mismatch.'),
            $request,
        );

        $this->assertNotNull($response);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'CSRF token mismatch.',
            'data' => null,
            'errors' => null,
            'meta' => null,
        ], $response->getData(true));
    }

    public function test_token_mismatch_on_non_api_route_is_not_handled(): void
    {
        $request = Request::create('/login', 'POST');

        $response = (new ApiExceptionRenderer())->render(
            new TokenMismatchException('CSRF token mismatch.'),
            $request,
        );

        $this->assertNull($response);
    }
}
