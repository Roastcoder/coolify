<?php

use App\Jobs\AiWhatsappReplyJob;
use App\Livewire\Ai\Index as AiPage;
use App\Livewire\Settings\AiAssistant as AiSettingsPage;
use App\Models\AiConversation;
use App\Models\AiSettings;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\WhatsappChannel;
use App\Services\AiAssistant;
use App\Services\VscodeServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $this->admin = User::factory()->create(['phone' => '+91 98765 43210']);
    $this->admin->teams()->attach($this->rootTeam, ['role' => 'owner']);
    $this->employeeTeam = Team::factory()->create();
    $this->employee = User::factory()->create(['phone' => '9123456789']);
    $this->employee->teams()->attach($this->employeeTeam, ['role' => 'member']);
    AiSettings::current()->update(['api_key' => 'test-key', 'access_password' => 'open-sesame-42', 'whatsapp_secret' => str_repeat('s', 48), 'whatsapp_root_numbers' => '919876543210']);
    Once::flush();
});

function claudeToolCall(string $name, array $input = []): array
{
    return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $name, 'input' => $input]]];
}

function claudeText(string $text): array
{
    return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
}

function conversationFor(User $user, bool $readOnly = false, string $channel = 'whatsapp'): AiConversation
{
    return AiConversation::query()->create(['user_id' => $user->id, 'channel' => $channel, 'channel_id' => $channel === 'web' ? (string) $user->id : (string) $user->phone, 'messages' => [], 'read_only' => $readOnly]);
}

it('holds WhatsApp actions until the access password and a YES, and never sends the password to Claude', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(claudeToolCall('run_server_command', ['server_uuid' => 'nope', 'command' => 'df -h']))
        ->push(claudeText('Disk is fine.'));
    $conversation = conversationFor($this->admin);
    $assistant = new AiAssistant;

    expect($assistant->handle($conversation, 'how full is the disk?'))->toContain('run_server_command')->toContain('unlock <your access password>')
        ->and($assistant->handle($conversation->refresh(), 'YES'))->toContain('Locked')
        ->and($assistant->handle($conversation->refresh(), 'unlock wrong-password'))->toBe('Wrong password.')
        ->and($assistant->handle($conversation->refresh(), 'unlock open-sesame-42'))->toContain('Unlocked')
        ->and($assistant->handle($conversation->refresh(), 'YES'))->toBe('Disk is fine.');

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'open-sesame-42'));
    // The command was run after the YES (it fails here: no such server) and its result went back to Claude.
    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"tool_use_id":"toolu_1"'));
});

it('asks the web app only for a YES, but WhatsApp also for the password', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(claudeToolCall('deploy_application', ['uuid' => 'missing']))
        ->push(claudeText('Could not find it.'))
        ->push(claudeToolCall('deploy_application', ['uuid' => 'missing']));
    $web = conversationFor($this->admin, channel: 'web');
    $whatsapp = conversationFor($this->admin);
    $assistant = new AiAssistant;

    expect($assistant->handle($web, 'deploy my app'))->toContain('Reply YES')
        ->and($assistant->handle($web->refresh(), 'yes'))->toBe('Could not find it.')
        ->and($assistant->handle($whatsapp, 'deploy my app'))->toContain('unlock <your access password>')
        ->and($assistant->handle($whatsapp->refresh(), 'yes'))->toContain('Locked');
});

it('tells Claude when the user does not allow an action', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(claudeToolCall('stop_application', ['uuid' => 'x']))
        ->push(claudeText('Okay, not stopping it.'));
    $conversation = conversationFor($this->admin);
    $assistant = new AiAssistant;
    $assistant->handle($conversation, 'stop it');

    expect($assistant->handle($conversation->refresh(), 'no'))->toBe('Okay, not stopping it.');
    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'The user did not allow this action.'));
});

it('gives read-only chats only the dashboard tool and no way to unlock', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(claudeText('You have no servers.'))]);
    $conversation = conversationFor($this->admin, readOnly: true);
    $assistant = new AiAssistant;

    expect($assistant->handle($conversation, 'unlock open-sesame-42'))->toBe('Only the instance owner can unlock actions.')
        ->and($assistant->handle($conversation, 'what is running?'))->toBe('You have no servers.')
        ->and($assistant->runTool($this->admin, 'run_server_command', ['server_uuid' => 'x', 'command' => 'id']))->toBe('Error: this user has read-only access.');

    Http::assertSent(function (Request $request) {
        $tools = collect($request->data()['tools'])->pluck('name')->all();

        return $tools === ['list_resources'];
    });
});

it('lets root numbers act as the owner and everyone else read only', function () {
    Queue::fake();
    $secret = ['X-Whatsapp-Secret' => str_repeat('s', 48)];

    $this->postJson('/webhooks/whatsapp', ['id' => 'm1', 'number' => '919876543210', 'body' => 'hi'], ['X-Whatsapp-Secret' => 'wrong'])->assertUnauthorized();
    $this->postJson('/webhooks/whatsapp', ['id' => 'm1', 'number' => '919876543210', 'body' => 'hi'], $secret)->assertOk();
    $this->postJson('/webhooks/whatsapp', ['id' => 'm1', 'number' => '919876543210', 'body' => 'hi again'], $secret)->assertOk();
    $this->postJson('/webhooks/whatsapp', ['id' => 'm2', 'number' => '919123456789', 'body' => 'status?'], $secret)->assertOk();
    $this->postJson('/webhooks/whatsapp', ['id' => 'm3', 'number' => '15550001111', 'body' => 'hello'], $secret)->assertOk();

    Queue::assertPushed(AiWhatsappReplyJob::class, 2);
    Queue::assertPushed(AiWhatsappReplyJob::class, fn ($job) => $job->userId === $this->admin->id && ! $job->readOnly && $job->text === 'hi');
    Queue::assertPushed(AiWhatsappReplyJob::class, fn ($job) => $job->userId === $this->employee->id && $job->readOnly);
});

it('sends WhatsApp notifications only for teams with an instance admin', function () {
    AiSettings::current()->update(['whatsapp_notifications_enabled' => true, 'whatsapp_notify_numbers' => '919876543210']);

    expect(WhatsappChannel::isEnabledFor($this->rootTeam))->toBeTrue()
        ->and(WhatsappChannel::isEnabledFor($this->employeeTeam))->toBeFalse();

    AiSettings::current()->update(['whatsapp_notifications_enabled' => false]);
    expect(WhatsappChannel::isEnabledFor($this->rootTeam))->toBeFalse();
});

it('keeps the AI pages to instance admins and never shows the saved key', function () {
    Http::fake(['coolify-whatsapp:3000/*' => Http::response([], 500)]);

    $this->actingAs($this->employee)->withSession(['currentTeam' => $this->employeeTeam]);
    Livewire::test(AiSettingsPage::class)->assertRedirect(route('dashboard'));
    Livewire::test(AiPage::class)->assertRedirect(route('dashboard'));

    $this->actingAs($this->admin)->withSession(['currentTeam' => $this->rootTeam]);
    Livewire::test(AiSettingsPage::class)
        ->assertSet('hasApiKey', true)
        ->assertSet('apiKey', '')
        ->assertDontSee('test-key')
        ->set('rootNumbers', "+91 98765-43210, 9988\n+91 91234 56789")
        ->call('save')
        ->assertSet('rootNumbers', '919876543210, 9988, 919123456789');

    expect(AiSettings::current()->api_key)->toBe('test-key');
});

it('opens the settings page on a fresh install', function () {
    Http::fake(['coolify-whatsapp:3000/*' => Http::response([], 500)]);
    AiSettings::query()->delete();

    $this->actingAs($this->admin)->withSession(['currentTeam' => $this->rootTeam]);
    Livewire::test(AiSettingsPage::class)->assertOk()->assertSet('model', 'claude-opus-5-5');
});

it('lets only logged-in instance admins through to VS Code', function () {
    $this->get('/vscode-auth')->assertUnauthorized();
    // Traefik only lets a 2xx through; other middleware may answer a redirect instead.
    expect($this->actingAs($this->employee)->get('/vscode-auth')->status())->not->toBeBetween(200, 299);
    $this->actingAs($this->admin)->get('/vscode-auth')->assertNoContent();
});

it('routes VS Code behind the admin check', function () {
    $route = app(VscodeServer::class)->proxyRoute('app.example.com');

    expect($route)->toContain('Host(`app.example.com`) && PathPrefix(`/vscode`)')
        ->toContain('address: http://coolify:8080/vscode-auth')
        ->toContain('- sc-vscode-auth');
});

it('runs the local model in Ollama without an API key, with thinking off', function () {
    AiSettings::current()->update(['api_key' => null, 'model' => 'local:qwen3:1.7b']);
    Http::fake(['coolify-ollama:11434/*' => Http::response(claudeText('All apps are up.'))]);
    $assistant = new AiAssistant;

    expect($assistant->isConfigured())->toBeTrue()
        ->and($assistant->handle(conversationFor($this->admin, channel: 'web'), 'status?'))->toBe('All apps are up.');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://coolify-ollama:11434/v1/messages'
        && $request['model'] === 'qwen3:1.7b' && $request['thinking'] === ['type' => 'disabled'] && ! $request->hasHeader('x-api-key'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'anthropic.com'));
});
