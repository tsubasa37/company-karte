<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_トップはダッシュボードを表示する(): void
    {
        $this->get('/')->assertStatus(200);
    }

    public function test_企業一覧が表示できる(): void
    {
        $this->get('/companies')->assertStatus(200);
    }
}
