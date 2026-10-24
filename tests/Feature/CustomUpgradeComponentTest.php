<?php

use App\Livewire\CustomUpgrade;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::forget(CustomUpgrade::LATEST_BUILD_CACHE_KEY);
    config(['custom_build.repository' => 'Roastcoder/coolify', 'custom_build.workflow' => 'custom-build.yml']);
});

function fakeLatestBuild(?string $sha): void
{
    Http::fake([
        'api.github.com/repos/Roastcoder/coolify/actions/workflows/custom-build.yml/runs*' => Http::response([
            'workflow_runs' => $sha ? [['head_sha' => $sha]] : [],
        ]),
    ]);
}

it('offers an update when the newest successful build is a different commit', function () {
    config(['custom_build.sha' => 'aaaaaaa1111111']);
    fakeLatestBuild('bbbbbbb2222222');

    Livewire::test(CustomUpgrade::class)
        ->call('checkUpdate')
        ->assertSet('latestSha', 'bbbbbbb2222222')
        ->assertSet('isUpdateAvailable', true)
        ->assertSee('Update available');
});

it('hides the update when the running image is the newest build', function () {
    config(['custom_build.sha' => 'aaaaaaa1111111']);
    fakeLatestBuild('aaaaaaa1111111');

    Livewire::test(CustomUpgrade::class)
        ->call('checkUpdate')
        ->assertSet('isUpdateAvailable', false)
        ->assertDontSee('Update available');
});

it('hides the update when the image has no build commit or GitHub is unreachable', function (?string $runningSha, ?string $latestSha) {
    config(['custom_build.sha' => $runningSha]);
    fakeLatestBuild($latestSha);

    Livewire::test(CustomUpgrade::class)
        ->call('checkUpdate')
        ->assertSet('isUpdateAvailable', false);
})->with([
    'local build without a commit' => [null, 'bbbbbbb2222222'],
    'no successful build found' => ['aaaaaaa1111111', null],
]);

it('only lets instance admins start an update', function () {
    config(['custom_build.sha' => 'aaaaaaa1111111']);
    fakeLatestBuild('bbbbbbb2222222');

    Livewire::test(CustomUpgrade::class)
        ->call('update')
        ->assertForbidden();
});
