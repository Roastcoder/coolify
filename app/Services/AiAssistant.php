<?php

namespace App\Services;

use App\Actions\Application\StopApplication;
use App\Models\AiConversation;
use App\Models\AiSettings;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fork-only: Claude as an operator for this instance, used by the AI page and the WhatsApp agent.
 *
 * Instance admins: reading (list, logs) runs at once; every change or command waits for a YES. On WhatsApp it
 * also needs the chat unlocked with the access password (the web app already has a login). The password is
 * checked here and never sent to Claude. Everyone else (employees) only gets the dashboard data of their teams.
 */
class AiAssistant
{
    public const UNLOCK_MINUTES = 15;

    private const MAX_STEPS = 12;

    private const MAX_TOOL_OUTPUT = 20000;

    /** Tools that wait for a YES. */
    public const GUARDED_TOOLS = ['deploy_application', 'restart_application', 'stop_application', 'run_server_command', 'read_file', 'write_file'];

    /** All that a user who is not an instance admin can use. */
    public const READ_ONLY_TOOLS = ['list_resources'];

    /** Full tools: an instance admin in a conversation that is not read-only. Set per handled message. */
    private bool $admin = false;

    public function __construct(private ?AiSettings $settings = null)
    {
        $this->settings ??= AiSettings::current();
    }

    public function isConfigured(): bool
    {
        return $this->settings->isLocal() || filled($this->settings->api_key);
    }

    /** Handles one message from the user and returns the reply to show. */
    public function handle(AiConversation $conversation, string $text): string
    {
        $text = trim($text);
        $this->admin = ! $conversation->read_only && (bool) $conversation->user?->isInstanceAdmin();

        if (preg_match('/^\/?(new|reset)$/i', $text)) {
            $conversation->update(['messages' => [], 'pending_action' => null]);

            return 'Started a new chat.';
        }
        if (preg_match('/^\/?lock$/i', $text)) {
            $conversation->update(['unlocked_until' => null]);

            return 'Locked. Actions need the access password again.';
        }
        if (preg_match('/^\/?unlock\s+(.+)$/is', $text, $match)) {
            return $this->unlock($conversation, trim($match[1]));
        }
        if (! $conversation->pending_action && ($quick = $this->quickReply($conversation->user, $text)) !== null) {
            return $quick;
        }
        if (! $this->isConfigured()) {
            return 'The AI assistant is not set up yet. Add a Claude API key in Settings > AI assistant.';
        }

        $messages = $conversation->messages ?? [];
        $pending = $conversation->pending_action;

        if ($pending) {
            $approved = (bool) preg_match('/^(yes|y|confirm|allow|ok|run it)[.!]?$/i', $text);
            if ($approved && $this->needsPassword($conversation) && ! $conversation->isUnlocked()) {
                return 'Locked. First send: unlock <your access password>. Then reply YES.';
            }
            $results = $pending['results'];
            foreach ($pending['calls'] as $call) {
                $results[] = $approved
                    ? $this->toolResult($call['id'], $this->runTool($conversation->user, $call['name'], $call['input']))
                    : $this->toolResult($call['id'], 'The user did not allow this action.', true);
            }
            if (! $approved && ! preg_match('/^(no|n|cancel|stop|deny)[.!]?$/i', $text)) {
                $results[] = ['type' => 'text', 'text' => $text];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
            $conversation->pending_action = null;
        } else {
            $messages[] = ['role' => 'user', 'content' => $text];
        }

        if (! $pending && $this->settings->isLocal() && ! $this->isServerQuestion($text)) {
            return $this->chat($conversation, $messages);
        }

        return $this->run($conversation, $messages);
    }

    /** Words that need the server tools. Everything else on a local model is plain chat. */
    private function isServerQuestion(string $text): bool
    {
        return (bool) preg_match('/\b(apps?|applications?|servers?|deploy\w*|restart\w*|stop\w*|logs?|databases?|db|down|status|errors?|memory|ram|cpu|disk|containers?|services?|domains?|backups?|uuid|running|crash\w*)\b/i', $text);
    }

    /**
     * Plain chat with the local model: a short prompt and no tools, so a CPU-only server answers in seconds
     * instead of minutes. Server questions go to run() with the tools.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function chat(AiConversation $conversation, array $messages): string
    {
        $model = substr($this->settings->model, strlen(AiSettings::LOCAL_PREFIX));
        $name = str_starts_with($model, 'yogii') ? 'Yogii' : 'the assistant';
        $history = collect($messages)->filter(fn (array $m) => is_string($m['content']))->take(-8)->values()->all();

        try {
            $reply = trim((string) Http::timeout(120)->connectTimeout(15)->post('http://coolify-ollama:11434/api/chat', [
                'model' => $model,
                'stream' => false,
                'think' => false,
                'keep_alive' => -1, // Stay loaded: loading the model again costs more than the reply.
                'options' => ['num_predict' => 300],
                'messages' => [
                    ['role' => 'system', 'content' => "You are {$name}, the owner's personal AI assistant on StandaloneCoders Cloud. Reply in the user's language, short and friendly, like a WhatsApp message. Never claim to be a human. For servers or apps, tell them to send: status, apps or down."],
                    ...$history,
                ],
            ])->throw()->json('message.content'));
        } catch (RequestException|ConnectionException) {
            $conversation->update(['messages' => $messages]);

            return 'The AI is not reachable right now. Try again in a minute.';
        }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $conversation->update(['messages' => $messages]);

        return $reply ?: 'Done.';
    }

    /** Instant answers without the AI for the common questions. Null when the AI should answer. */
    private function quickReply(?User $user, string $text): ?string
    {
        $command = strtolower(trim($text, " \t\n\r\0\x0B/.!?"));
        if (in_array($command, ['help', 'menu', 'hi', 'hello', 'hey'], true)) {
            return "Instant commands:\n*status* - summary of everything\n*apps* - every app and its status\n*down* - what is not running\n\nAsk anything else in your own words and the AI answers (slower).";
        }
        if (! $user || ! in_array($command, ['status', 'servers', 'apps', 'down', 'health'], true)) {
            return null;
        }
        $servers = $this->listResources($user);
        if ($command === 'status' || $command === 'servers' || $command === 'health') {
            return $this->summary($servers);
        }
        $lines = $this->resourceLines($servers, onlyDown: $command === 'down');

        return $lines === '' ? ($command === 'down' ? 'Everything is running.' : 'No apps found.') : $lines;
    }

    /** Actions from WhatsApp need the access password; the web app is behind a login already. */
    private function needsPassword(AiConversation $conversation): bool
    {
        return $conversation->channel === 'whatsapp';
    }

    private function unlock(AiConversation $conversation, string $password): string
    {
        if (! $this->admin) {
            return 'Only the instance owner can unlock actions.';
        }
        $hash = $this->settings->getAttributes()['access_password'] ?? null;
        if (blank($hash)) {
            return 'No access password is set. Set one in Settings > AI assistant.';
        }
        if (! Hash::check($password, $hash)) {
            return 'Wrong password.';
        }
        $conversation->update(['unlocked_until' => now()->addMinutes(self::UNLOCK_MINUTES)]);

        $reply = 'Unlocked for '.self::UNLOCK_MINUTES.' minutes.';
        if ($conversation->pending_action) {
            $reply .= ' Reply YES to run:'."\n".$this->describe($conversation->pending_action['calls']);
        }

        return $reply;
    }

    /** @param  array<int, array<string, mixed>>  $messages */
    private function run(AiConversation $conversation, array $messages): string
    {
        $user = $conversation->user;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $response = $this->call([
                    'system' => $this->systemPrompt($conversation),
                    'tools' => $this->tools($user),
                    'messages' => $messages,
                ]);
            } catch (RequestException $e) {
                $conversation->update(['messages' => $messages]);

                return match ($e->response->status()) {
                    401 => 'The Claude API key is not valid. Check Settings > AI assistant.',
                    429 => 'The AI is busy right now. Try again in a minute.',
                    default => 'AI error ('.$e->response->status().'): '.Str::limit((string) data_get($e->response->json(), 'error.message', ''), 200),
                };
            } catch (ConnectionException) {
                $conversation->update(['messages' => $messages]);

                return 'The AI is not reachable right now. Try again in a minute.';
            }

            $messages[] = ['role' => 'assistant', 'content' => $response['content'] ?? []];

            if ($response['stop_reason'] === 'refusal') {
                $conversation->update(['messages' => $messages]);

                return 'The AI declined this request.';
            }
            if ($response['stop_reason'] !== 'tool_use') {
                $conversation->update(['messages' => $messages]);

                return $this->text($response) ?: 'Done.';
            }

            $results = [];
            $guarded = [];
            foreach ($response['content'] ?? [] as $block) {
                if ($block['type'] !== 'tool_use') {
                    continue;
                }
                if (in_array($block['name'], self::GUARDED_TOOLS, true)) {
                    $guarded[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $block['input']];
                } else {
                    $results[] = $this->toolResult($block['id'], $this->runTool($user, $block['name'], $block['input']));
                }
            }

            if ($guarded) {
                $conversation->update(['messages' => $messages, 'pending_action' => ['results' => $results, 'calls' => $guarded]]);
                $lead = $this->text($response);

                return trim($lead."\n\n".'The AI wants to:'."\n".$this->describe($guarded)."\n\n"
                    .($this->needsPassword($conversation) && ! $conversation->isUnlocked() ? 'To allow, send: unlock <your access password>, then reply YES.' : 'Reply YES to allow, or NO.'));
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $conversation->update(['messages' => $messages]);

        return 'Stopped after many steps. Send a message to continue.';
    }

    /** @param  array<string, mixed>  $body */
    private function call(array $body): array
    {
        $model = $this->settings->model ?: 'claude-opus-5-5';
        if ($this->settings->isLocal()) {
            // Ollama speaks the same Messages API. Thinking off: much faster on CPU and the small model calls tools more reliably without it.
            return Http::timeout(600)->connectTimeout(15)->post('http://coolify-ollama:11434/v1/messages', [
                'model' => substr($model, strlen(AiSettings::LOCAL_PREFIX)),
                'max_tokens' => 600,
                'thinking' => ['type' => 'disabled'],
                ...$body,
            ])->throw()->json();
        }
        $request = Http::withHeaders([
            'x-api-key' => $this->settings->api_key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(300)->connectTimeout(15);

        // Haiku has no server-side fallback; on the others a declined request is retried on a fallback model.
        if ($model !== 'claude-haiku-5-5') {
            $request = $request->withHeaders(['anthropic-beta' => 'server-side-fallback-2026-07-01']);
            $body['fallbacks'] = 'default';
        }

        return $request->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 16000,
            'cache_control' => ['type' => 'ephemeral'],
            ...$body,
        ])->throw()->json();
    }

    private function text(array $response): string
    {
        return trim(collect($response['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n\n"));
    }

    private function toolResult(string $id, string $output, bool $isError = false): array
    {
        return ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => Str::limit($output, self::MAX_TOOL_OUTPUT), 'is_error' => $isError];
    }

    /** @param  array<int, array{name: string, input: array<string, mixed>}>  $calls */
    public function describe(array $calls): string
    {
        return collect($calls)->map(function (array $call): string {
            $input = $call['input'];
            if (isset($input['content'])) {
                $input['content'] = '('.strlen((string) $input['content']).' bytes)';
            }

            return '- '.$call['name'].' '.Str::limit(json_encode($input, JSON_UNESCAPED_SLASHES), 400);
        })->implode("\n");
    }

    private function systemPrompt(AiConversation $conversation): string
    {
        $channel = $conversation->channel === 'whatsapp' ? 'WhatsApp, so keep replies short and use plain text, no markdown tables' : 'the StandaloneCoders Cloud web app';

        if (! $this->admin) {
            return <<<PROMPT
            You are the assistant of StandaloneCoders Cloud, a hosting platform. You talk with a team member on {$channel}.
            This person has read-only access: you can only tell them about the servers, applications, databases and services on their dashboard (names, status, domains), using list_resources. You cannot deploy, restart, stop, read logs or run anything for them; if they ask, tell them to ask the owner. Never share passwords, keys or environment variables.
            PROMPT;
        }

        return <<<PROMPT
        You are the operator assistant of StandaloneCoders Cloud, a hosting platform. You help the owner manage servers, applications, databases and services. You talk with them on {$channel}.

        Use list_resources first to find uuids. Reading logs is always allowed. Deploying, restarting, stopping, running commands on a server and reading or writing server files are held until the user allows them; call the tool and the app asks the user (on WhatsApp the user also sends their access password to the app, never to you). Never ask the user for passwords or API keys. Call the product StandaloneCoders Cloud, never Coolify. If the user did not allow an action, do not try a different way to do the same thing.
        Commands run as root over SSH: prefer read-only commands to look around, and say clearly what a command will change before you run it.
        PROMPT;
    }

    /** @return array<int, array<string, mixed>> */
    private function tools(User $user): array
    {
        $uuid = ['type' => 'string', 'description' => 'The resource uuid, from list_resources.'];
        $server = ['type' => 'string', 'description' => 'The server uuid, from list_resources.'];
        $tool = fn (string $name, string $description, array $properties = [], array $required = []): array => [
            'name' => $name,
            'description' => $description,
            'input_schema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
        ];

        $tools = [
            $tool('list_resources', 'Lists every server with its applications, databases and services: uuid, name, status and domains.'),
            $tool('get_logs', 'Returns the latest container logs of an application, database or service.', ['uuid' => $uuid, 'lines' => ['type' => 'integer', 'description' => 'How many lines, 1 to 500. Default 100.']], ['uuid']),
            $tool('deploy_application', 'Builds and deploys an application.', ['uuid' => $uuid, 'force_rebuild' => ['type' => 'boolean']], ['uuid']),
            $tool('restart_application', 'Restarts the containers of an application without building.', ['uuid' => $uuid], ['uuid']),
            $tool('stop_application', 'Stops an application.', ['uuid' => $uuid], ['uuid']),
            $tool('run_server_command', 'Runs a shell command on a server as root and returns its output. Times out after 120 seconds.', ['server_uuid' => $server, 'command' => ['type' => 'string']], ['server_uuid', 'command']),
            $tool('read_file', 'Reads a file on a server.', ['server_uuid' => $server, 'path' => ['type' => 'string', 'description' => 'Absolute path.']], ['server_uuid', 'path']),
            $tool('write_file', 'Writes a whole file on a server, replacing it.', ['server_uuid' => $server, 'path' => ['type' => 'string', 'description' => 'Absolute path.'], 'content' => ['type' => 'string']], ['server_uuid', 'path', 'content']),
        ];

        return array_values(array_filter($tools, fn (array $tool) => $this->canUse($user, $tool['name'])));
    }

    private function canUse(User $user, string $tool): bool
    {
        return ($this->admin && $user->isInstanceAdmin()) || in_array($tool, self::READ_ONLY_TOOLS, true);
    }

    /** @param  array<string, mixed>  $input */
    public function runTool(User $user, string $name, array $input): string
    {
        if (! $this->canUse($user, $name)) {
            return 'Error: this user has read-only access.';
        }
        try {
            return match ($name) {
                'list_resources' => $this->summary($servers = $this->listResources($user))."\n\n".$this->resourceLines($servers, withUuid: true),
                'get_logs' => $this->logs($user, (string) ($input['uuid'] ?? ''), (int) ($input['lines'] ?? 100)),
                'deploy_application', 'restart_application' => $this->deploy($user, (string) ($input['uuid'] ?? ''), $name === 'restart_application', (bool) ($input['force_rebuild'] ?? false)),
                'stop_application' => $this->stop($user, (string) ($input['uuid'] ?? '')),
                'run_server_command' => $this->command($user, (string) ($input['server_uuid'] ?? ''), (string) ($input['command'] ?? '')),
                'read_file' => $this->readFile($user, (string) ($input['server_uuid'] ?? ''), (string) ($input['path'] ?? '')),
                'write_file' => $this->writeFile($user, (string) ($input['server_uuid'] ?? ''), (string) ($input['path'] ?? ''), (string) ($input['content'] ?? '')),
                default => 'Unknown tool.',
            };
        } catch (\Throwable $e) {
            return 'Error: '.Str::limit($e->getMessage(), 1000);
        }
    }

    /** @return array<int, int> */
    private function teamIds(User $user): array
    {
        return $user->teams()->pluck('teams.id')->all();
    }

    private function listResources(User $user): array
    {
        return Server::query()->whereIn('team_id', $this->teamIds($user))->get()->map(fn (Server $server): array => [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'ip' => $server->ip,
            'reachable' => (bool) $server->settings?->is_reachable,
            'applications' => $server->applications()->map(fn ($app) => ['uuid' => $app->uuid, 'name' => $app->name, 'status' => $app->status, 'domains' => $app->fqdn])->values(),
            'databases' => $server->databases()->map(fn ($db) => ['uuid' => $db->uuid, 'name' => $db->name, 'type' => class_basename($db), 'status' => $db->status])->values(),
            'services' => $server->services()->get()->map(fn ($service) => ['uuid' => $service->uuid, 'name' => $service->name, 'status' => $service->status])->values(),
        ])->all();
    }

    /** A plain-English summary first: small local models misread counts in long JSON. */
    private function summary(array $servers): string
    {
        $all = collect($servers)->flatMap(fn (array $server) => collect(['applications', 'databases', 'services'])
            ->flatMap(fn (string $kind) => collect($server[$kind])->map(fn (array $item) => [$kind, $item['name'], (string) $item['status']])));
        $down = $all->reject(fn (array $item) => str_starts_with($item[2], 'running'));
        $count = fn (string $kind) => $all->where(0, $kind)->count();

        return sprintf('Summary: %d server(s) (%s), %d application(s), %d database(s), %d service(s). %s',
            count($servers), collect($servers)->pluck('name')->implode(', ') ?: 'none',
            $count('applications'), $count('databases'), $count('services'),
            $down->isEmpty() ? 'Everything is running.' : 'Not running: '.$down->map(fn (array $item) => "{$item[1]} ({$item[2]})")->implode(', ').'.');
    }

    /** One short line per resource: far fewer tokens than JSON, so the local model answers faster. */
    private function resourceLines(array $servers, bool $onlyDown = false, bool $withUuid = false): string
    {
        return collect($servers)->flatMap(fn (array $server) => collect(['applications' => 'app', 'databases' => 'db', 'services' => 'service'])
            ->flatMap(fn (string $label, string $kind) => collect($server[$kind])
                ->reject(fn (array $item) => $onlyDown && str_starts_with((string) $item['status'], 'running'))
                ->map(fn (array $item) => "{$label} {$item['name']}: {$item['status']}".($withUuid ? " (uuid {$item['uuid']}, server {$server['name']} uuid {$server['uuid']})" : ''))))
            ->implode("\n");
    }

    /** Finds a resource of one of the user's teams, or fails. */
    private function resource(User $user, string $uuid): mixed
    {
        $resource = $uuid !== '' ? queryResourcesByUuid($uuid) : null;
        if (! $resource || ! method_exists($resource, 'team') || ! in_array($resource->team()?->id, $this->teamIds($user), true)) {
            throw new \RuntimeException('No resource with this uuid.');
        }

        return $resource;
    }

    private function server(User $user, string $uuid): Server
    {
        $server = Server::query()->where('uuid', $uuid)->whereIn('team_id', $this->teamIds($user))->first();
        if (! $server || ! Gate::forUser($user)->allows('update', $server)) {
            throw new \RuntimeException('No server with this uuid.');
        }

        return $server;
    }

    private function logs(User $user, string $uuid, int $lines): string
    {
        $resource = $this->resource($user, $uuid);
        $server = data_get($resource, 'destination.server') ?? data_get($resource, 'server');
        $lines = max(1, min(500, $lines));
        $name = escapeshellarg($resource->uuid);

        return instant_remote_process(["for c in \$(docker ps -a -q --filter name={$name}); do echo \"== \$(docker inspect -f '{{.Name}}' \$c)\"; docker logs --tail {$lines} \$c 2>&1; done"], $server, false) ?: 'No containers or no output.';
    }

    private function application(User $user, string $uuid): Application
    {
        $application = $this->resource($user, $uuid);
        if (! $application instanceof Application || ! Gate::forUser($user)->allows('deploy', $application)) {
            throw new \RuntimeException('No application with this uuid.');
        }

        return $application;
    }

    private function deploy(User $user, string $uuid, bool $restartOnly, bool $force): string
    {
        $application = $this->application($user, $uuid);
        $result = queue_application_deployment(application: $application, deployment_uuid: new_public_id(), force_rebuild: $force, is_api: true, restart_only: $restartOnly);

        return $result['message'] ?? ($restartOnly ? 'Restart queued.' : 'Deployment queued.');
    }

    private function stop(User $user, string $uuid): string
    {
        StopApplication::dispatch($this->application($user, $uuid));

        return 'Stopping the application.';
    }

    private function command(User $user, string $serverUuid, string $command): string
    {
        $output = instant_remote_process([$command], $this->server($user, $serverUuid), true, false, 120);

        return $output === null || $output === '' ? '(no output)' : $output;
    }

    private function readFile(User $user, string $serverUuid, string $path): string
    {
        return (string) instant_remote_process(['cat '.escapeshellarg($this->path($path))], $this->server($user, $serverUuid));
    }

    private function writeFile(User $user, string $serverUuid, string $path, string $content): string
    {
        $path = $this->path($path);
        instant_remote_process(['printf %s '.escapeshellarg(base64_encode($content)).' | base64 -d > '.escapeshellarg($path)], $this->server($user, $serverUuid));

        return 'Wrote '.strlen($content).' bytes to '.$path.'.';
    }

    private function path(string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \RuntimeException('Use an absolute path.');
        }

        return $path;
    }

    /** Public entry for the code editor, which uses the same team and gate checks. */
    public function readServerFile(User $user, string $serverUuid, string $path): string
    {
        return $this->readFile($user, $serverUuid, $path);
    }

    public function writeServerFile(User $user, string $serverUuid, string $path, string $content): string
    {
        return $this->writeFile($user, $serverUuid, $path, $content);
    }
}
