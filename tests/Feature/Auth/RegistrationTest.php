<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'type' => 'company',
            'name' => 'Test User',
            'company_name' => 'Test Hotels Pvt Ltd',
            'gstin' => '27aapfu0939f1zv',
            'phone' => '+91 98765 43210',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue($user->isCustomer());
        $this->assertTrue($user->hasRole(Role::Customer->value));
        $this->assertFalse($user->hasVerifiedEmail());

        $customer = $user->customer;
        $this->assertSame('Test Hotels Pvt Ltd', $customer->company_name);
        $this->assertSame('27AAPFU0939F1ZV', $customer->gstin);
        $this->assertNotNull($customer->terms_accepted_at);
    }

    public function test_registration_requires_accepting_terms_and_a_phone()
    {
        $this->post('/register', [
            'type' => 'individual',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['terms', 'phone']);

        $this->assertGuest();
    }

    public function test_unverified_customers_are_asked_to_verify_before_the_dashboard()
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice', absolute: false));
    }
}
