<?php

namespace App\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Macroable;

class CustomJwtGuard implements Guard
{
    use GuardHelpers, Macroable;

    protected Request $request;
    protected string $secret;
    protected ?string $cachedToken = null;

    public function __construct(UserProvider $provider, Request $request)
    {
        $this->provider = $provider;
        $this->request = $request;
        $this->secret = env('AUTH_TOKEN_SECRET', env('JWT_SECRET', 'change-this-secret-before-production'));
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    public function user()
    {
        $token = $this->request->bearerToken();

        if ($this->user !== null) {
            if ($token && $this->cachedToken === $token) {
                return $this->user;
            }
            if (!$token && $this->cachedToken === null) {
                return $this->user;
            }
            $this->user = null;
            $this->cachedToken = null;
        }

        if (! $token) {
            \Illuminate\Support\Facades\Log::info("CustomJwtGuard: No token found");
            return null;
        }

        $payload = $this->verifyToken($token);
        if (! $payload) {
            \Illuminate\Support\Facades\Log::info("CustomJwtGuard: verifyToken failed");
            return null;
        }

        // The custom token stores user ID in the 'sub' claim
        if (isset($payload->sub)) {
            $this->user = $this->provider->retrieveById($payload->sub);
            $this->cachedToken = $this->user ? $token : null;
            if (!$this->user) {
                \Illuminate\Support\Facades\Log::info("CustomJwtGuard: User not found for ID: " . $payload->sub);
            }
        }

        return $this->user;
    }

    public function validate(array $credentials = [])
    {
        if (empty($credentials['login']) || empty($credentials['password'])) {
            return false;
        }

        $user = $this->provider->retrieveByCredentials($credentials);

        if (! $user) {
            return false;
        }

        if ($this->provider->validateCredentials($user, $credentials)) {
            return true;
        }

        return false;
    }

    /**
     * Exact replication of the Node `verifyToken` method.
     */
    protected function verifyToken(string $token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        $expected = $this->signPayload($encoded);

        // Prevent timing attacks and check length
        if (strlen($signature) !== strlen($expected) || ! hash_equals($expected, $signature)) {
            \Illuminate\Support\Facades\Log::info("CustomJwtGuard: Signature mismatch. Expected: $expected, Got: $signature");
            return null;
        }

        $jsonStr = $this->base64UrlDecode($encoded);
        $payload = json_decode($jsonStr);

        if (! $payload || ! isset($payload->exp) || $payload->exp < time()) {
            \Illuminate\Support\Facades\Log::info("CustomJwtGuard: Payload invalid or expired. JSON: $jsonStr");
            return null;
        }

        return $payload;
    }

    public function createToken($user): string
    {
        $now = time();
        $ttl = (int) env('AUTH_TOKEN_TTL_SECONDS', 60 * 60 * 8);
        $payload = [
            'sub' => $user->id,
            'role' => $user->role->code ?? 'customer',
            'email' => $user->email,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->signPayload($encoded);

        return $encoded . '.' . $signature;
    }

    public function signPayload(string $payload): string
    {
        $hash = hash_hmac('sha256', $payload, $this->secret, true);
        return $this->base64UrlEncode($hash);
    }

    public function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
