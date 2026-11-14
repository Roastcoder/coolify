<?php

use App\Jobs\UpdateCoolifyJob;
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
    config(['custom_build.repository' => 'Roastcoder/standalonecoders-cloud', 'custom_build.workflow' => 'custom-build.yml']);
});

function fakeLatestBuild(?string $sha): void
{
    Http::fake([
        'api.github.com/repos/Roastcoder/standalonecoders-cloud/actions/workflows/custom-build.yml/runs*' => Http::response([
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
        ->assertSee('New version available');
});

it('hides the update when the running image is the newest build', function () {
    config(['custom_build.sha' => 'aaaaaaa1111111']);
    fakeLatestBuild('aaaaaaa1111111');

    Livewire::test(CustomUpgrade::class)
        ->call('checkUpdate')
        ->assertSet('isUpdateAvailable', false)
        ->assertDontSee('New version available');
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

it('reads the build commit of the latest image when GitHub cannot be reached (private repository, no token)', function (string $runningSha, bool $available) {
    config(['custom_build.sha' => $runningSha, 'custom_build.token' => null]);
    $image = 'ghcr.io/v2/roastcoder/standalonecoders-cloud';
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        'ghcr.io/token*' => Http::response(['token' => 't']),
        "{$image}/manifests/latest" => Http::response(['manifests' => [
            ['digest' => 'sha256:att', 'platform' => ['os' => 'unknown']],
            ['digest' => 'sha256:amd', 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
        ]]),
        "{$image}/manifests/sha256:amd" => Http::response(['config' => ['digest' => 'sha256:cfg']]),
        "{$image}/blobs/sha256:cfg" => Http::response(['config' => ['Labels' => ['org.opencontainers.image.revision' => '42684fa8dda58f5e4c6bc290ba373a1d06bc8e1b']]]),
        // Tag names are not in build order; the old check picked the alphabetically last one.
        "{$image}/tags/list" => Http::response(['tags' => ['42684fa', '892075d', 'latest']]),
    ]);

    Livewire::test(CustomUpgrade::class)
        ->call('checkUpdate')
        ->assertSet('latestSha', '42684fa8dda58f5e4c6bc290ba373a1d06bc8e1b')
        ->assertSet('isUpdateAvailable', $available);
})->with([
    'running the latest image' => ['42684fa8dda58f5e4c6bc290ba373a1d06bc8e1b', false],
    'running an older build' => ['892075d0f00000000000000000000000000000000', true],
]);

it('makes automatic updates install the newest fork build, not the upstream release', function (bool $available) {
    $upgrade = Mockery::mock(CustomUpgrade::class)->makePartial();
    $upgrade->shouldReceive('installLatest')->once()->with('instance.custom_auto_update_started')->andReturn($available);
    app()->instance(CustomUpgrade::class, $upgrade);
    Http::fake();

    (new UpdateCoolifyJob)->handle();

    Http::assertNothingSent(); // no upstream version check against the CDN
})->with(['update available' => true, 'already newest' => false]);
