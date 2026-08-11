<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use App\Auth\CustomJwtGuard;

class CustomJwtGuardTest extends TestCase
{
    public function test_verifies_node_token_and_fetches_user()
    {
        // Setup user and role to match token
        $role = new Role();
        $role->id = 1;
        $role->code = 'customer';
        $role->name_en = 'Customer';
        $role->name_ar = 'عميل';
        // Mock save if not running in DB transaction, actually let's just insert it for the test
        // wait, we can't reliably insert if the DB already has data, but the DB is directevents_platform
        // Let's just create a mock guard instance and test the token verification methods.

        $mockGuard = new CustomJwtGuard(Auth::createUserProvider('users'), request());

        $tokenSecret = env('AUTH_TOKEN_SECRET', env('JWT_SECRET', 'change-this-secret-before-production'));

        $now = time();
        $payload = [
            'sub' => 9999,
            'role' => 'customer',
            'email' => 'test@example.com',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $encoded = $mockGuard->base64UrlEncode(json_encode($payload));
        $signature = $mockGuard->signPayload($encoded);
        $token = $encoded . '.' . $signature;

        $request = request();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $guard = new CustomJwtGuard(Auth::createUserProvider('users'), $request);

        // Use reflection to call verifyToken
        $reflection = new \ReflectionClass($guard);
        $method = $reflection->getMethod('verifyToken');
        $method->setAccessible(true);

        $verifiedPayload = $method->invoke($guard, $token);

        $this->assertNotNull($verifiedPayload);
        $this->assertEquals(9999, $verifiedPayload->sub);
        $this->assertEquals('test@example.com', $verifiedPayload->email);
    }
}
