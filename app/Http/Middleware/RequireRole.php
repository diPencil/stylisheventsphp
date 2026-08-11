<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return ApiResponse::fail('Authentication required', 401);
        }

        if (!in_array($user->role->code ?? '', $roles)) {
            return ApiResponse::fail('Permission denied', 403);
        }

        return $next($request);
    }
}
