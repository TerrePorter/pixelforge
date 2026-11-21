<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the registration page renders', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Register'));
});

test('new users can register and are signed in', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('generate'));

    $this->assertAuthenticated();
    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('registration requires a unique email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});
