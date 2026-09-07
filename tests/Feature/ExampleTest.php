<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The front door.
     *
     * It was the client portal at /user and is now the customer portal, which
     * is the larger audience and the one being sent here. Asserted in
     * CustomerPortalTest as well, with the reasoning; kept here because this is
     * where somebody looks to find out what a bare domain does.
     */
    public function test_the_application_redirects_to_the_customer_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/customer');
    }

    public function test_login_pages_are_available(): void
    {
        // All three, because moving the front door moved none of them.
        $this->get('/customer')->assertOk();
        $this->get('/user')->assertOk();
        $this->get('/admin')->assertOk();
    }

    public function test_public_password_generator_is_not_available(): void
    {
        $this->get('/pass')->assertNotFound();
    }
}
