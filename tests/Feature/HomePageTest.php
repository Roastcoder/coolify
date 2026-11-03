<?php

it('sends guests opening the site root to the home page', function () {
    $this->get('/')->assertRedirect(route('home'));
});

it('still sends guests on other pages to the login form', function () {
    $this->get('/servers')->assertRedirect(route('login'));
});

it('shows plans, the developer and the GitHub link', function () {
    $this->get('/home')
        ->assertOk()
        ->assertSee('Plans')
        ->assertSee('₹999')
        ->assertSee('About the developer')
        ->assertSee('https://github.com/'.config('custom_build.repository'), false);
});
