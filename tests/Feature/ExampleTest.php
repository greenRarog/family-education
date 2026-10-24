<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_csrf_endpoint_is_available_to_the_frontend(): void
    {
        $response = $this->get('/api/csrf');

        $response->assertNoContent();
    }
}
