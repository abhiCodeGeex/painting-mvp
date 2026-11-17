<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;

test('super admin can open the admin panel', function () {
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

test('super admin can open the staff users screen', function () {
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);

    $this->actingAs($admin)->get('/admin/users')->assertOk();
});

test('customers cannot open the admin panel', function () {
    $customer = User::factory()->create();
    $customer->assignRole(Role::Customer->value);

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

test('inactive staff cannot open the admin panel', function () {
    $staff = User::factory()->staff()->inactive()->create();
    $staff->assignRole(Role::SuperAdmin->value);

    $this->actingAs($staff)->get('/admin')->assertForbidden();
});

test('staff visiting the customer dashboard are sent to the admin panel', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->get('/dashboard')->assertRedirect('/admin');
});

test('guests are sent to the admin login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('the admin seeder creates a verified super admin', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::where('email', env('ADMIN_EMAIL', 'admin@paint-mvp.test'))->firstOrFail();

    expect($admin->isStaff())->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->hasRole(Role::SuperAdmin->value))->toBeTrue();
});
