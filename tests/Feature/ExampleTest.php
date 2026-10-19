<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_backend_root_is_not_exposed(): void
    {
        $this->get('/')->assertNotFound();
    }
}
