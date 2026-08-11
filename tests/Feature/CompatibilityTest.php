<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Cross-runtime authentication compatibility tests.
 *
 * Uses a controlled test secret ('phase-c-test-secret-deterministic') so
 * both sides can independently verify token round-trips without touching
 * the live AUTH_TOKEN_SECRET.
 */
class CompatibilityTest extends TestCase
{
    private const TEST_SECRET = 'phase-c-test-secret-deterministic';

    private const LEGACY_NODE_TOKEN = 'eyJzdWIiOjEyMzQsInJvbGUiOiJhZG1pbiIsImVtYWlsIjoidGVzdC1ub2RlQHBoYXNlLWMudGVzdCIsImlhdCI6MTc4NjUwMDAwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.MYc8ltOmT9_qbTd9nqia3wjMq41zG2YKCOvQOoUa6C0';
    private const TAMPER_BASE_TOKEN = 'eyJzdWIiOjEyMzQsInJvbGUiOiJhZG1pbiIsImVtYWlsIjoidGVzdEBwaGFzZS1jLnRlc3QiLCJpYXQiOjE3ODY1MDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.Garr79ZWk2RCVH_PFl69HXVT7nLBsmJsNjaWf07Rg6w';

    // ------------------------------------------------------------------
    // Helper: Build a guard instance using the known test secret
    // ------------------------------------------------------------------
    private function buildGuard(): \App\Auth\CustomJwtGuard
    {
        $guard = new \App\Auth\CustomJwtGuard(Auth::createUserProvider('users'), request());
        // Override secret to the test secret via reflection
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('secret');
        $prop->setAccessible(true);
        $prop->setValue($guard, self::TEST_SECRET);
        return $guard;
    }

    // ------------------------------------------------------------------
    // 1. Legacy Node password hash verified by Laravel
    // ------------------------------------------------------------------
    public function test_legacy_node_hash_verified_by_laravel()
    {
        $hash = 'scrypt:72aeb5d62b01dc79240de6c1a73507f1:61498984512ab6ba098281fc694a6641c38c1e17e91e7869d4f980efb1ac72e267a20f71b5612566de5730751479ed409995d76349df1a174cfcd8ed182da9e4';
        $this->assertTrue(Hash::check('normal-password', $hash));
    }

    // ------------------------------------------------------------------
    // 2. Legacy Node-format HMAC token vector is accepted by Laravel
    // ------------------------------------------------------------------
    public function test_legacy_node_token_accepted_by_laravel()
    {
        $guard = $this->buildGuard();
        $ref   = new \ReflectionClass($guard);
        $method = $ref->getMethod('verifyToken');
        $method->setAccessible(true);
        $result = $method->invoke($guard, self::LEGACY_NODE_TOKEN);

        $this->assertNotNull($result, 'Laravel must accept a token produced by Node auth.js');
        $this->assertEquals(1234, $result->sub);
        $this->assertEquals('admin', $result->role);
        $this->assertEquals('test-node@phase-c.test', $result->email);
    }

    // ------------------------------------------------------------------
    // 3. Token produced by Laravel matches the legacy HMAC/base64url algorithm
    // ------------------------------------------------------------------
    public function test_laravel_token_verified_by_node_algorithm()
    {
        $guard = $this->buildGuard();

        // Build payload in Laravel
        $now  = time();
        $payload = [
            'sub'   => 5678,
            'role'  => 'admin',
            'email' => 'test-laravel@phase-c.test',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $ref = new \ReflectionClass($guard);
        $encode = $ref->getMethod('base64UrlEncode');
        $encode->setAccessible(true);
        $sign = $ref->getMethod('signPayload');
        $sign->setAccessible(true);

        $encoded   = $encode->invoke($guard, json_encode($payload));
        $signature = $sign->invoke($guard, $encoded);
        $laravelToken = $encoded . '.' . $signature;

        [$encodedPart, $signaturePart] = explode('.', $laravelToken);
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPart, self::TEST_SECRET, true)), '+/', '-_'), '=');
        $decodedPayload = json_decode(base64_decode(strtr($encodedPart, '-_', '+/')), true);

        $this->assertSame($expectedSignature, $signaturePart);
        $this->assertEquals(5678, $decodedPayload['sub']);
        $this->assertEquals('test-laravel@phase-c.test', $decodedPayload['email']);
    }

    // ------------------------------------------------------------------
    // 4. Tampered Node token is rejected by Laravel
    // ------------------------------------------------------------------
    public function test_tampered_legacy_token_rejected()
    {
        // Tamper last character of signature
        $tampered = substr(self::TAMPER_BASE_TOKEN, 0, -1) . 'X';

        $guard = $this->buildGuard();
        $ref   = new \ReflectionClass($guard);
        $method = $ref->getMethod('verifyToken');
        $method->setAccessible(true);
        $result = $method->invoke($guard, $tampered);

        $this->assertNull($result, 'Laravel must reject a tampered token');
    }
}
