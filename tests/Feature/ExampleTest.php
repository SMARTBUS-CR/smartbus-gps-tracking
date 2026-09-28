<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root has no page of its own: it redirects to the API docs (Scramble).
     */
    public function test_the_root_redirects_to_the_api_docs(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('scramble.docs.ui'));
    }
}
