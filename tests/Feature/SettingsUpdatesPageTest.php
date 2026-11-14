<?php

use App\Livewire\CustomUpgrade;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);
    Cache::forget(CustomUpgrade::LATEST_BUILD_CACHE_KEY);
    config(['custom_build.repository' => 'Roastcoder/standalonecoders-cloud', 'custom_build.sha' => 'aaaaaaa1111111']);
});

it('shows the version status once it has checked', function (?string $latest, string $headline, bool $available) {
    Http::fake(['api.github.com/*' => Http::response(['workflow_runs' => $latest ? [['head_sha' => $latest]] : []])]);

    Livewire::test(Updates::class)
        ->assertSee('Checking for updates…')
        ->call('loadStatus')
        ->assertSee($headline, false)
        ->assertSee('aaaaaaa')
        ->assertSet('updateAvailable', $available)
        ->assertSee('Last checked');
})->with([
    'up to date' => ['aaaaaaa1111111', "You're up to date", false],
    'update ready' => ['bbbbbbb2222222', 'A new version is ready', true],
    'update server unreachable' => [null, 'Could not reach the update server', false],
]);

it('fills the schedule from a preset', function () {
    Livewire::test(Updates::class)
        ->set('update_check_frequency', '0 */6 * * *')
        ->assertSeeHtml("\$set('update_check_frequency', '0 */6 * * *')");
});
