<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the home page sends guests to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('generate'));

    $this->get(route('generate'))->assertRedirect(route('login'));
});

test('the login page renders', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

test('users can log in with valid credentials', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('generate'));

    $this->assertAuthenticatedAs($user);
});

test('users cannot log in with a wrong password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('signed-in users see the generate page with only safe user fields shared', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generate')
            ->where('auth.user', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => false,
            ]));
});
