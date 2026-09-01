<?php

namespace Tests\Feature;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PhaseATest extends TestCase
{
    #[Test]
    public function root_endpoint_returns_exact_json_format(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertExactJson([
            'message' => 'Stylish Holidays Backend API',
            'version' => '1.0.0',
            'status' => 'running',
        ]);
    }

    #[Test]
    public function health_endpoint_returns_exact_json_format(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
        ]);
        $this->assertEquals('ok', $response->json('status'));
    }

    #[Test]
    public function api_404_returns_proper_json_format(): void
    {
        $response = $this->getJson('/api/non-existent-route');

        $response->assertStatus(404);
        $response->assertJsonStructure([
            'success',
            'message',
            'details' => ['path']
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertEquals('Route not found', $response->json('message'));
        $this->assertEquals('api/non-existent-route', $response->json('details.path'));
    }

    #[Test]
    public function api_method_not_allowed_returns_proper_json_format(): void
    {
        $response = $this->postJson('/health'); // /health only supports GET

        $response->assertStatus(405);
        $response->assertExactJson([
            'success' => false,
            'message' => 'Method not allowed',
        ]);
    }

    #[Test]
    public function cors_allows_configured_origins(): void
    {
        // Default allowed origin from .env
        $origin = 'http://localhost:3000';
        $response = $this->options('/api/user', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    #[Test]
    public function cors_rejects_unconfigured_origins(): void
    {
        $origin = 'http://evil-domain.com';
        $response = $this->options('/api/user', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ]);

        // Laravel CORS middleware does not return the Allow-Origin header if origin is not allowed
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    #[Test]
    public function api_response_helper_success_format(): void
    {
        $response = ApiResponse::ok(['key' => 'value'], 'Success Message');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(json_encode([
            'success' => true,
            'message' => 'Success Message',
            'data' => ['key' => 'value'],
        ]), $response->getContent());
    }

    #[Test]
    public function api_response_helper_error_format(): void
    {
        $response = ApiResponse::fail('Error Message', 400, ['field' => 'Required']);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals(json_encode([
            'success' => false,
            'message' => 'Error Message',
            'details' => ['field' => 'Required'],
        ]), $response->getContent());
    }
}
