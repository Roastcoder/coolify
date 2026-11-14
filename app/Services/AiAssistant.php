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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        if (! $conversation->pending_action && ($quick = $this->quickReply($conversation, $text)) !== null) {
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
            // An action picked from the menu runs without the AI.
            if (! empty($pending['menu'])) {
                $conversation->update(['pending_action' => null]);
                $call = $pending['calls'][0];

                return $approved ? Str::limit($this->runTool($conversation->user, $call['name'], $call['input']), 1500) : 'Cancelled.';
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
        $name = str_starts_with($model, 'yogii') ? 'Yogii' : 'an AI assistant';
        $owner = $conversation->user?->name ?: 'the owner';
        // A small model mixes up longer chats: the last two exchanges are enough for a WhatsApp reply.
        $history = collect($messages)->filter(fn (array $m) => is_string($m['content']))->take(-4)->values()->all();

        try {
            $reply = trim((string) Http::timeout(120)->connectTimeout(15)->post('http://coolify-ollama:11434/api/chat', [
                'model' => $model,
                'stream' => false,
                'think' => false,
                'keep_alive' => -1, // Stay loaded: loading the model again costs more than the reply.
                'options' => ['num_predict' => 300],
                'messages' => [
                    // Close to the prompt Yogii was trained with, which gives it the most sensible answers.
                    ['role' => 'system', 'content' => "You are {$name}, {$owner}'s personal AI assistant. Be practical, accurate, privacy-conscious, and transparent about uncertainty. Never claim to be the human {$owner}. You can only chat: you cannot set reminders, send messages or change anything."],
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

    /**
     * Instant answers without the AI: a numbered menu (main menu, projects, one project, one resource, its actions)
     * and the common commands. Null when the AI should answer. The open menu level is kept in the cache, so a
     * reply with a number picks from the menu that was sent last.
     */
    private function quickReply(AiConversation $conversation, string $text): ?string
    {
        $user = $conversation->user;
        $command = strtolower(trim($text, " \t\n\r\0\x0B/.!?"));
        $key = "ai-menu:{$conversation->id}";
        $menu = Cache::get($key);
        $show = function (array $state, string $reply) use ($key): string {
            Cache::put($key, $state, now()->addHour());

            return $reply;
        };
        $mehar = $conversation->channel === 'whatsapp' && MeharBot::enabled();
        $mainMenu = fn (): string => $show(['level' => 'main'], "*Menu*\n1. 📊 Status of everything\n2. 📁 Projects and apps\n3. 🔴 What is down".($mehar ? "\n4. 🏦 Mehar Advisory" : '')."\n\nReply with a number. Send *menu* any time, *0* to go back. Or type a question in your own words for the AI.");

        if (in_array($command, ['help', 'menu', 'hi', 'hello', 'hey', 'start'], true) || ($command === '0' && in_array($menu['level'] ?? 'main', ['main', 'projects'], true))) {
            return $mainMenu();
        }
        // "app list", "list", "projects", "application list" and typos like "appliction" all mean apps.
        if (preg_match('/^(list|projects?|ap+l?\w*( list)?|list( of)? ap+l?\w*)$/', $command)) {
            $command = 'apps';
        }
        if (! $user) {
            return null;
        }
        // Mehar Advisory runs inside Yogii: from here on the webhook sends this chat to it until logout.
        if ($mehar && ($command === 'mehar' || ($command === '4' && ($menu['level'] ?? null) === 'main'))) {
            return implode("\n\n", array_filter((new MeharBot($conversation->channel_id))->handle('login'), 'is_string'));
        }
        if ($command === 'status' || $command === 'servers' || $command === 'health' || ($command === '1' && ($menu['level'] ?? null) === 'main')) {
            return $this->summary($this->listResources($user));
        }
        if ($command === 'down' || ($command === '3' && ($menu['level'] ?? null) === 'main')) {
            $lines = $this->resourceLines($this->listResources($user), onlyDown: true);

            return $lines === '' ? 'Everything is running.' : $lines;
        }
        if ($command === 'apps' || ($command === '2' && ($menu['level'] ?? null) === 'main') || ($command === '0' && ($menu['level'] ?? null) === 'project')) {
            [$reply, $projects] = $this->projectMenu($this->listResources($user));

            return $show(['level' => 'projects', 'items' => $projects], $reply);
        }
        if ($command === '0' && ($menu['level'] ?? null) === 'resource') {
            $command = (string) $menu['project'];
            $menu = ['level' => 'projects', 'items' => [$command]];
            $command = '1';
        }
        if (ctype_digit($command) && $menu && $menu['level'] !== 'main') {
            $picked = $menu['items'][(int) $command - 1] ?? null;
            if ($picked === null) {
                return 'No option '.$command.'. Send *menu* to start again.';
            }
            $servers = $this->listResources($user);
            if ($menu['level'] === 'projects') {
                $items = $this->flatResources($servers)->where('project', $picked)->values();

                return $show(['level' => 'project', 'project' => $picked, 'items' => $items->pluck('uuid')->all()], $this->projectView($items, $picked));
            }
            if ($menu['level'] === 'project') {
                $resource = $this->flatResources($servers)->firstWhere('uuid', $picked);
                if (! $resource) {
                    return 'That resource is gone. Send *apps* for the list.';
                }
                $actions = $this->actionsFor($resource);

                return $show(['level' => 'resource', 'project' => $menu['project'], 'uuid' => $picked, 'name' => $resource['name'], 'items' => array_keys($actions)], $this->resourceView($resource, $actions));
            }

            return $this->menuAction($conversation, $picked, $menu['uuid'], $menu['name']);
        }
        $wanted = preg_replace('/[^a-z0-9]/', '', $command);

        // Only a one-word message can be a resource name; anything else goes to the AI.
        return str_contains($command, ' ') || strlen($wanted) < 4 ? null : $this->statusOf($this->listResources($user), $wanted);
    }

    /** @return Collection<int, array{kind: string, uuid: string, name: string, status: string, project: string, domains: ?string}> */
    private function flatResources(array $servers): Collection
    {
        return collect($servers)->flatMap(fn (array $server) => collect(['applications', 'databases', 'services'])
            ->flatMap(fn (string $kind) => collect($server[$kind])->map(fn (array $item) => [
                'kind' => $kind, 'uuid' => $item['uuid'], 'name' => $item['name'], 'status' => (string) $item['status'],
                'project' => $item['project'] ?? 'Other', 'domains' => $item['domains'] ?? null,
            ])));
    }

    private function icon(string $status): string
    {
        return str_starts_with($status, 'running') ? '🟢' : '🔴';
    }

    /**
     * The numbered list of projects, and the project names in that order.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function projectMenu(array $servers): array
    {
        $projects = $this->flatResources($servers)->groupBy('project')->sortKeys();
        if ($projects->isEmpty()) {
            return ['No apps found.', []];
        }
        $lines = $projects->values()->map(function (Collection $items, int $i) use ($projects): string {
            $down = $items->reject(fn (array $item) => str_starts_with($item['status'], 'running'))->count();
            $counts = collect(['applications' => 'app', 'databases' => 'db', 'services' => 'service'])
                ->map(fn (string $label, string $kind) => ($n = $items->where('kind', $kind)->count()) ? $n.' '.$label.($n > 1 ? 's' : '') : null)
                ->filter()->implode(', ');

            return ($i + 1).'. '.($down ? '🔴' : '🟢').' *'.$projects->keys()[$i].'* ('.$counts.')'.($down ? " - {$down} down" : '');
        });

        return ["📁 *Your projects*\n".$lines->implode("\n")."\n\nReply with a number to open a project. *0* Back", $projects->keys()->all()];
    }

    /** One project: its resources, numbered in the order of $items. */
    private function projectView(Collection $items, string $project): string
    {
        $kinds = ['applications' => 'app', 'databases' => 'db', 'services' => 'service'];
        $lines = $items->map(fn (array $item, int $i) => ($i + 1).'. '.$this->icon($item['status']).' '.$item['name'].' ('.$kinds[$item['kind']].')'
            .(str_starts_with($item['status'], 'running') ? '' : " - {$item['status']}"));

        return "📁 *{$project}*\n".($lines->isEmpty() ? 'Nothing here any more.' : $lines->implode("\n"))."\n\nReply with a number to open one. *0* Back";
    }

    /**
     * What the menu offers for one resource. Restart, redeploy and stop are for apps, and only for the owner.
     *
     * @return array<string, string>
     */
    private function actionsFor(array $resource): array
    {
        $actions = $this->admin ? ['get_logs' => '📜 Logs (last 30 lines)'] : [];
        if ($this->admin && $resource['kind'] === 'applications') {
            $actions += ['restart_application' => '🔄 Restart', 'deploy_application' => '🚀 Redeploy', 'stop_application' => '⏹ Stop'];
        }

        return $actions;
    }

    /** @param  array<string, string>  $actions */
    private function resourceView(array $resource, array $actions): string
    {
        $options = collect(array_values($actions))->map(fn (string $label, int $i) => ($i + 1).'. '.$label)->push('0. ⬅ Back');

        return $this->icon($resource['status'])." *{$resource['name']}*\nStatus: {$resource['status']}\nProject: {$resource['project']}"
            .($resource['domains'] ? "\nDomain: {$resource['domains']}" : '')."\n\n".$options->implode("\n");
    }

    /** Runs a menu action: logs at once; restart, redeploy and stop wait for a YES (and the password on WhatsApp). */
    private function menuAction(AiConversation $conversation, string $tool, string $uuid, string $name): string
    {
        if ($tool === 'get_logs') {
            return Str::limit($this->runTool($conversation->user, 'get_logs', ['uuid' => $uuid, 'lines' => 30]), 3500);
        }
        $conversation->update(['pending_action' => ['results' => [], 'calls' => [['id' => 'menu', 'name' => $tool, 'input' => ['uuid' => $uuid]]], 'menu' => true]]);
        $verb = ['restart_application' => 'Restart', 'deploy_application' => 'Redeploy', 'stop_application' => 'Stop'][$tool];

        return "{$verb} *{$name}*?\n".($this->needsPassword($conversation) && ! $conversation->isUnlocked()
            ? 'To allow, send: unlock <your access password>, then reply YES.'
            : 'Reply YES to allow, or NO.');
    }

    /** A one-word message that names a resource gets its real status at once, so the AI never has to guess it. */
    private function statusOf(array $servers, string $wanted): ?string
    {
        $matches = collect(explode("\n", $this->resourceLines($servers)))
            ->filter(fn (string $line) => str_contains(preg_replace('/[^a-z0-9]/', '', strtolower(Str::between($line, ' ', ': '))), $wanted));

        return $matches->isEmpty() ? null : $matches->implode("\n");
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

    protected function listResources(User $user): array
    {
        return Server::query()->whereIn('team_id', $this->teamIds($user))->get()->map(fn (Server $server): array => [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'ip' => $server->ip,
            'reachable' => (bool) $server->settings?->is_reachable,
            'applications' => $server->applications()->map(fn ($app) => ['uuid' => $app->uuid, 'name' => $app->name, 'status' => $app->status, 'domains' => $app->fqdn, 'project' => $app->environment?->project?->name])->values(),
            'databases' => $server->databases()->map(fn ($db) => ['uuid' => $db->uuid, 'name' => $db->name, 'type' => class_basename($db), 'status' => $db->status, 'project' => $db->environment?->project?->name])->values(),
            'services' => $server->services()->get()->map(fn ($service) => ['uuid' => $service->uuid, 'name' => $service->name, 'status' => $service->status, 'project' => $service->environment?->project?->name])->values(),
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
