<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'database' => 'connected',
            ]);
    }

    public function test_queue_status_endpoint_returns_job_counts(): void
    {
        $response = $this->getJson('/api/queue-status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'queue_connection',
                'queue_name',
                'pending_jobs',
                'failed_jobs',
            ]);
    }
}
