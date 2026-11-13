<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('redirects the root to the API docs (Scramble)', function () {
    get('/')->assertRedirect(route('scramble.docs.ui'));
});

it('answers the health check ping', function () {
    getJson('/api/ping')
        ->assertOk()
        ->assertExactJson(['service' => 'smartbus-gps', 'status' => 'ok']);
});
