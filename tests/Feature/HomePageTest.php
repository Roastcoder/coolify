<?php

it('sends guests opening the site root to the home page', function () {
    $this->get('/')->assertRedirect(route('home'));
});

it('still sends guests on other pages to the login form', function () {
    $this->get('/servers')->assertRedirect(route('login'));
});

it('shows the paid plans and the developer', function () {
    $this->get('/home')
        ->assertOk()
        ->assertSee('₹999')
        ->assertSee('About the developer')
        ->assertDontSee('Self-host')
        ->assertDontSee('Open source');
});
