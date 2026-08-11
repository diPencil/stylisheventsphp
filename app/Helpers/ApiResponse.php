<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Return a success JSON response.
     * Matches Node API: { success: true, message: ..., data: ... }
     */
    public static function ok(mixed $data = [], string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Return a failure JSON response.
     * Matches Node API: { success: false, message: ..., details: ... }
     */
    public static function fail(string $message, int $status = 400, mixed $details = null, ?string $error = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        if ($error !== null && config('app.debug')) {
            $payload['error'] = $error;
        }

        return response()->json($payload, $status);
    }
}
