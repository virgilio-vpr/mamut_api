<?php

it('returns the API status at the versioned endpoint', function () {
    $response = $this->getJson('/api/v1/status');

    $response->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'API is running.',
            'data' => ['status' => 'ok'],
        ]);
});

it('returns the standard JSON error for an unknown API endpoint', function () {
    $response = $this->getJson('/api/v1/unknown');

    $response->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'message' => 'Endpoint not found.',
            'errors' => null,
        ]);
});
