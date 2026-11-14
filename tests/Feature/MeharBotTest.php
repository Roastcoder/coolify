<?php

use App\Jobs\MeharBotJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\AiSettings;
use App\Models\InstanceSettings;
use App\Services\MeharBot;
use App\Services\WhatsappGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    AiSettings::current()->update(['whatsapp_secret' => str_repeat('s', 48), 'whatsapp_root_numbers' => '919876543210', 'mehar_api_url' => 'https://mehar.test']);
    Once::flush();
    $this->bot = fn () => new MeharBot('919000000001', 'https://mehar.test');
});

function meharLogin(Closure $bot): void
{
    Http::fake(['mehar.test/api/auth/request-otp' => Http::response(['ok' => true]), 'mehar.test/api/auth/verify-otp' => Http::response(['token' => 'tok-1', 'user' => ['name' => 'Asha', 'role' => 'manager']])]);
    $bot()->handle('login');
    $bot()->handle('1');
    expect(implode("\n", $bot()->handle('123456')))->toContain('Logged in as *Asha*');
}

it('logs in with an OTP and keeps the token encrypted', function () {
    meharLogin($this->bot);

    $session = Cache::get('mehar-bot:919000000001');
    expect($session['token'])->not->toBe('tok-1')->and($session['step'])->toBe('menu');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/request-otp') && $r['phone'] === '9000000001');
});

it('offers ID and password when the number is unknown, and stops after three wrong tries', function () {
    Http::fake(['mehar.test/api/auth/request-otp' => Http::response(['error' => 'not found'], 404), 'mehar.test/api/auth/login' => Http::response(['error' => 'bad'], 401)]);
    ($this->bot)()->handle('login');
    expect(($this->bot)()->handle('1')[0])->toContain('Reply *2*');
    ($this->bot)()->handle('2');
    ($this->bot)()->handle('asha@example.com');
    expect(($this->bot)()->handle('wrong1')[0])->toContain('Try again');
    ($this->bot)()->handle('wrong2');
    expect(($this->bot)()->handle('wrong3')[0])->toContain('Too many tries')
        ->and(Cache::has('mehar-bot:919000000001'))->toBeFalse();
});

it('searches loan files, opens one and sends its PDF', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=RJ14AB1234' => Http::response(['data' => [['id' => 7, 'loan_number' => 'ML-7', 'applicant_name' => 'Ravi', 'vehicle_number' => 'RJ14AB1234', 'status' => 'disbursed']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'ML-7', 'applicant_name' => 'Ravi', 'loan_amount' => 500000]]),
        'mehar.test/api/pdf/loan/7' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
    ]);

    ($this->bot)()->handle('1');
    expect(($this->bot)()->handle('RJ14AB1234')[0])->toContain('1. ML-7 · Ravi · RJ14AB1234 · Disbursed');
    expect(($this->bot)()->handle('1')[0])->toContain('Loan amount: ₹5,00,000')->toContain('Download loan PDF');
    $file = ($this->bot)()->handle('1')[0];
    expect($file['file'])->toBe('%PDF-1.4')->and($file['filename'])->toBe('Loan-ML-7.pdf');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/pdf/loan/7') && $r->hasHeader('Authorization', 'Bearer tok-1'));
});

it('uploads a document sent on WhatsApp to the open loan file', function () {
    meharLogin($this->bot);
    $this->mock(WhatsappGateway::class)->shouldReceive('media')->with('media-1')->andReturn(['mimetype' => 'application/pdf', 'contents' => '%PDF', 'filename' => 'rc.pdf']);
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => [['id' => 7, 'loan_number' => 'ML-7']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'ML-7']]),
        'mehar.test/api/loans/7/documents' => Http::response(['ok' => true]),
    ]);
    foreach (['1', 'ML-7', '1', '3'] as $message) {
        ($this->bot)()->handle($message);
    }

    expect(($this->bot)()->handle('RC copy', ['id' => 'media-1', 'mimetype' => 'application/pdf', 'filename' => 'rc.pdf', 'size' => 4])[0])->toContain('Uploaded *RC copy*');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/loans/7/documents') && $r->isMultipart());
});

it('logs out when the backend says the token expired', function () {
    meharLogin($this->bot);
    Http::fake(['mehar.test/api/external/surepass/rc' => Http::response(['error' => 'expired'], 401)]);
    ($this->bot)()->handle('3');

    expect(($this->bot)()->handle('RJ14AB1234')[0])->toContain('login has expired')
        ->and(Cache::has('mehar-bot:919000000001'))->toBeFalse();
});

it('routes login and active sessions to the bot, and everyone else as before', function () {
    Queue::fake();
    $secret = ['X-Whatsapp-Secret' => str_repeat('s', 48)];

    $this->postJson('/webhooks/whatsapp', ['id' => 'a1', 'number' => '919000000001', 'body' => 'hello'], $secret)->assertOk();
    Queue::assertPushed(SendWhatsappMessageJob::class, fn ($job) => str_contains($job->message, 'Reply *login*'));
    $this->postJson('/webhooks/whatsapp', ['id' => 'a2', 'number' => '919000000001', 'body' => 'Login'], $secret)->assertOk();
    Queue::assertPushed(MeharBotJob::class, 1);

    Cache::put('mehar-bot:919000000002', ['step' => 'upload', 'token' => 'x'], 60);
    $this->postJson('/webhooks/whatsapp', ['id' => 'a3', 'number' => '919000000002', 'body' => '', 'media' => ['id' => 'm', 'mimetype' => 'image/jpeg', 'filename' => null, 'size' => 9, 'extra' => 'dropped']], $secret)->assertOk();
    Queue::assertPushed(MeharBotJob::class, fn (MeharBotJob $job) => $job->media === ['id' => 'm', 'mimetype' => 'image/jpeg', 'filename' => null, 'size' => 9]);
});

it('answers free text at the menu with Yogii', function () {
    AiSettings::current()->update(['model' => AiSettings::LOCAL_PREFIX.'yogii:latest']);
    meharLogin($this->bot);
    Http::fake(['coolify-ollama:11434/api/chat' => Http::response(['message' => ['content' => 'A hypothecation is the bank lien on the vehicle.']])]);

    expect(($this->bot)()->handle('what is hypothecation?')[0])->toContain('bank lien')->toContain('*menu*');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api/chat') && $r['model'] === 'yogii:latest' && ! $r->hasHeader('Authorization'));
});
