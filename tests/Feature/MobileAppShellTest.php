<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Once::flush();
});

it('shows the bottom tab bar with the current page marked', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $html = $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->get(route('server.index'))
        ->assertOk()
        ->assertSee('mobile-tab-bar', false)
        ->assertSee('aria-label="Open menu"', false)
        ->getContent();

    preg_match('/<nav aria-label="Main" class="mobile-tab-bar.*?<\/nav>/s', $html, $tabBar);
    expect($tabBar[0])->toContain('>Home<')->toContain('>Projects<')->toContain('>Servers<')
        ->and(preg_match('/aria-current="page"\s+class="mobile-tab mobile-tab-active">.*?<span>Servers<\/span>/s', $tabBar[0]))->toBe(1);
});

it('lets the installed app use the whole screen and registers the offline worker', function () {
    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertSee('viewport-fit=cover', false)
        ->assertSee('<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />', false)
        ->assertSee("navigator.serviceWorker.register('/sw.js')", false);
});

it('ships a service worker that only serves the offline page when navigation fails', function () {
    $worker = file_get_contents(public_path('sw.js'));
    $offline = file_get_contents(public_path('offline.html'));

    expect($worker)->toContain("event.request.mode !== 'navigate'")
        ->toContain("caches.match('/offline.html')")
        ->and($offline)->toContain("You're offline")
        // The offline page must not need the network: no external scripts, styles or fonts.
        ->not->toMatch('/(src|href)="https?:/');
});
