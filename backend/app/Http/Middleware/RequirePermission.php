<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequirePermission
{
    public function handle(Request $request, Closure $next, $permissionKey): Response
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return ApiResponse::fail('Authentication required', 401);
        }

        $roleCode = $user->role->code ?? '';

        $permission = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.code', $roleCode)
            ->where('role_permissions.permission_key', $permissionKey)
            ->first();

        if (!$permission || !$permission->allowed) {
            return ApiResponse::fail('Permission denied', 403);
        }

        return $next($request);
    }
}
