<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;

class AuthTest extends DemoDataTestCase
{
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('vendors.show', $this->vendor('Company A')))->assertRedirect(route('login'));
        $this->post(route('vendors.transitions.store', $this->vendor('Company A')))->assertRedirect(route('login'));
    }

    public function test_rejects_a_wrong_password(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['username' => 'linh', 'password' => 'nope'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Invalid username or password.']);

        $this->assertGuest();
    }

    public function test_logs_in_with_a_case_insensitive_username(): void
    {
        $this->post(route('login'), ['username' => ' Linh ', 'password' => DatabaseSeeder::PASSWORD])
            ->assertRedirect(route('vendors.index'));

        $this->assertAuthenticatedAs($this->user('linh'));
        $this->get('/')->assertOk()->assertSee('Signed in as <strong>Linh Nguyen</strong>', false);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->post(route('login'), ['username' => 'mai', 'password' => DatabaseSeeder::PASSWORD]);
        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
    }
}
