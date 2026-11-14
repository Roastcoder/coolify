<?php

use App\Jobs\AiWhatsappReplyJob;
use App\Jobs\GuestAssistantJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Livewire\Ai\Editor as CodePage;
use App\Livewire\Ai\Index as AiPage;
use App\Livewire\Settings\AiAssistant as AiSettingsPage;
use App\Models\AiConversation;
use App\Models\AiSettings;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
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
        ->and($assistant->handle(conversationFor($this->admin, channel: 'web'), 'are my apps fine?'))->toBe('All apps are up.');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://coolify-ollama:11434/v1/messages'
        && $request['model'] === 'qwen3:1.7b' && $request['thinking'] === ['type' => 'disabled'] && ! $request->hasHeader('x-api-key'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'anthropic.com'));
});

it('answers plain chat on a local model fast, without tools, and keeps server questions on the tools', function () {
    AiSettings::current()->update(['api_key' => null, 'model' => 'local:yogii']);
    Http::fake([
        'coolify-ollama:11434/api/chat' => Http::response(['message' => ['content' => 'Hi! I am Yogii.']]),
        'coolify-ollama:11434/v1/messages' => Http::response(claudeText('Everything is running.')),
    ]);
    $conversation = conversationFor($this->admin, channel: 'whatsapp');

    expect((new AiAssistant)->handle($conversation, 'kya haal hai bhai'))->toBe('Hi! I am Yogii.')
        ->and((new AiAssistant)->handle($conversation->fresh(), 'is the backend down?'))->toBe('Everything is running.')
        ->and($conversation->fresh()->messages[1])->toBe(['role' => 'assistant', 'content' => 'Hi! I am Yogii.']);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://coolify-ollama:11434/api/chat'
        && $request['model'] === 'yogii' && ! isset($request['tools']) && $request['keep_alive'] === -1 && str_contains($request['messages'][0]['content'], 'Yogii'));
    Http::assertSentCount(2);
});

it('starts the resource list with a plain summary for small models', function () {
    $summary = (fn (array $servers) => $this->summary($servers))->call(new AiAssistant, [
        ['name' => 'main', 'applications' => [['name' => 'api', 'status' => 'running:healthy'], ['name' => 'web', 'status' => 'exited']], 'databases' => [], 'services' => []],
    ]);

    expect($summary)->toBe('Summary: 1 server(s) (main), 2 application(s), 0 database(s), 0 service(s). Not running: web (exited).');
});

it('answers the common commands instantly without the AI', function () {
    Http::fake();
    $assistant = new AiAssistant;
    $conversation = conversationFor($this->admin);

    expect($assistant->handle($conversation, 'help'))->toContain('Projects and apps')
        ->and($assistant->handle($conversation, 'Status'))->toStartWith('Summary: 0 server(s)')
        ->and($assistant->handle($conversation, 'down'))->toBe('Everything is running.')
        ->and($assistant->handle($conversation, 'Appliction list'))->toBe('No apps found.')
        ->and($assistant->handle($conversation, 'list'))->toBe('No apps found.');
    Http::assertNothingSent();
});

it('walks the WhatsApp menu from projects to an app and holds a restart for the password and a YES', function () {
    Http::fake();
    $assistant = new class extends AiAssistant
    {
        protected function listResources(User $user): array
        {
            return [['name' => 'main', 'uuid' => 's1', 'applications' => [
                ['uuid' => 'a1', 'name' => 'noida-haat', 'status' => 'running:healthy', 'domains' => 'https://shop.test', 'project' => 'Noida Haat'],
                ['uuid' => 'a2', 'name' => 'mehar-api', 'status' => 'exited', 'domains' => null, 'project' => 'Mehar'],
            ], 'databases' => [['uuid' => 'd1', 'name' => 'noida-db', 'status' => 'running:healthy', 'project' => 'Noida Haat']], 'services' => []]];
        }
    };
    $conversation = conversationFor($this->admin);
    $send = fn (string $text) => $assistant->handle($conversation->fresh(), $text);

    expect($send('menu'))->toContain('2. 📁 Projects and apps')
        ->and($send('2'))->toContain("1. 🔴 *Mehar* (1 app) - 1 down\n2. 🟢 *Noida Haat* (1 app, 1 db)")
        ->and($send('2'))->toContain("1. 🟢 noida-haat (app)\n2. 🟢 noida-db (db)")
        ->and($send('1'))->toContain("Domain: https://shop.test\n\n1. 📜 Logs (last 30 lines)\n2. 🔄 Restart\n3. 🚀 Redeploy\n4. ⏹ Stop\n0. ⬅ Back")
        ->and($send('2'))->toBe("Restart *noida-haat*?\nTo allow, send: unlock <your access password>, then reply YES.")
        ->and($conversation->fresh()->pending_action['calls'][0])->toBe(['id' => 'menu', 'name' => 'restart_application', 'input' => ['uuid' => 'a1']])
        ->and($send('no'))->toBe('Cancelled.')
        ->and($conversation->fresh()->pending_action)->toBeNull()
        ->and($send('0'))->toContain('📁 *Noida Haat*')
        ->and($send('0'))->toContain('📁 *Your projects*')
        ->and($send('9'))->toBe('No option 9. Send *menu* to start again.');
    Http::assertNothingSent();
});

it('answers a resource name with its real status, without the AI', function () {
    $servers = [['name' => 'main', 'uuid' => 's1', 'applications' => [
        ['name' => 'standalone-coders', 'uuid' => 'a1', 'status' => 'running:healthy'],
        ['name' => 'mehar-dvr-backend', 'uuid' => 'a2', 'status' => 'exited'],
    ], 'databases' => [], 'services' => []]];
    $statusOf = fn (string $wanted) => (fn () => $this->statusOf($servers, $wanted))->call(new AiAssistant);

    expect($statusOf('standalonecoder'))->toBe('app standalone-coders: running:healthy')
        ->and($statusOf('mehardvr'))->toBe('app mehar-dvr-backend: exited')
        ->and($statusOf('running'))->toBeNull();
});

it('opens a picked app in VS Code and falls back to all files', function () {
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->rootTeam->id])->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id, 'name' => 'Shop API']);
    $vscode = Mockery::mock(VscodeServer::class)->makePartial();
    $vscode->shouldReceive('isRunning')->andReturnTrue();
    $vscode->shouldReceive('openProject')->once()->withArgs(fn (Application $app) => $app->is($application))->andReturn('/root/projects/shop-api-abc123');
    app()->instance(VscodeServer::class, $vscode);

    Livewire::actingAs($this->admin)->test(CodePage::class)
        ->assertSee('Shop API')
        ->set('project', $application->uuid)->call('openProject')
        ->assertSet('folder', '/root/projects/shop-api-abc123')
        ->assertSee('/vscode/?folder='.rawurlencode('/root/projects/shop-api-abc123'), false)
        ->set('project', '')->call('openProject')
        ->assertSet('folder', VscodeServer::ALL_FILES);
});

it('lets Yogii chat with unknown senders and passes their messages to the owner', function () {
    Queue::fake();
    $secret = ['X-Whatsapp-Secret' => str_repeat('s', 48)];
    $this->postJson('/webhooks/whatsapp', ['id' => 'g1', 'number' => '15550001111', 'body' => 'Kesa h'], $secret)->assertOk();
    Queue::assertPushed(GuestAssistantJob::class, fn ($job) => $job->number === '15550001111' && $job->text === 'Kesa h');
    Queue::assertNotPushed(AiWhatsappReplyJob::class);

    AiSettings::current()->update(['model' => AiSettings::LOCAL_PREFIX.'yogii']);
    Http::fake(['coolify-ollama:11434/*' => Http::response(['message' => ['content' => 'Main theek hoon! Aap kaun bol rahe hain?']])]);
    (new GuestAssistantJob('15550001111', 'Kesa h'))->handle();
    (new GuestAssistantJob('15550001111', 'Ravi, car loan ke baare mein'))->handle();

    $sent = Queue::pushed(SendWhatsappMessageJob::class)->map(fn ($job) => [$job->number, $job->message])->values()->all();
    expect($sent)->toHaveCount(4)
        ->and($sent[0])->toBe(['919876543210', "Message for you from +15550001111 (wa.me/15550001111):\n\nKesa h"])
        ->and($sent[1])->toBe(['15550001111', 'Main theek hoon! Aap kaun bol rahe hain?'])
        ->and($sent[2][0])->toBe('919876543210');
    // The second call carries the first exchange, so Yogii keeps the thread.
    Http::assertSent(fn ($request) => count($request['messages']) === 4 && $request['messages'][1]['content'] === 'Kesa h');
});

it('falls back to fixed replies for unknown senders without the local model', function () {
    Queue::fake();
    Http::fake();
    foreach (['hello', 'Ravi here, about the car loan', 'please call me'] as $text) {
        (new GuestAssistantJob('15550001111', $text))->handle();
    }

    $toGuest = Queue::pushed(SendWhatsappMessageJob::class)->filter(fn ($job) => $job->number === '15550001111')->map->message->values();
    expect($toGuest)->toHaveCount(2)
        ->and($toGuest[0])->toContain('May I know your name')
        ->and($toGuest[1])->toContain('passed this on')
        ->and(Queue::pushed(SendWhatsappMessageJob::class)->where('number', '919876543210'))->toHaveCount(3);
    Http::assertNothingSent();
});
