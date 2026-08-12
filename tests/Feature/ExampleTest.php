<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_to_user_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/user');
    }

    public function test_login_pages_are_available(): void
    {
        $this->get('/user')->assertOk();
        $this->get('/admin')->assertOk();
    }

    public function test_public_password_generator_is_not_available(): void
    {
        $this->get('/pass')->assertNotFound();
    }
}
