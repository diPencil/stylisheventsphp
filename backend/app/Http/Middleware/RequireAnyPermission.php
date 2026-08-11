<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return ApiResponse::fail('Authentication required', 401);
        }

        $roleCode = $user->role->code ?? '';

        $hasAny = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.code', $roleCode)
            ->whereIn('role_permissions.permission_key', $permissions)
            ->where('role_permissions.allowed', 1)
            ->exists();

        if (!$hasAny) {
            return ApiResponse::fail('Permission denied', 403);
        }

        return $next($request);
    }
}
