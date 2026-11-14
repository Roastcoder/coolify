<?php

use App\Jobs\MeharBotJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\AiConversation;
use App\Models\AiSettings;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\AiAssistant;
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

/** A stand-in for the Yogii model: intents for the sentences in $intents, a chat answer for anything else. */
function fakeYogii(array $intents = [], string $answer = 'Yogii here.'): Closure
{
    return function (Request $request) use ($intents, $answer) {
        $last = collect($request['messages'])->last()['content'];
        if (($request['format'] ?? null) === 'json') {
            return Http::response(['message' => ['content' => json_encode($intents[$last] ?? ['intent' => 'chat', 'query' => ''])]]);
        }

        return Http::response(['message' => ['content' => $answer]]);
    };
}

function meharLogin(Closure $bot): void
{
    Http::fake(['mehar.test/api/auth/request-otp' => Http::response(['ok' => true]), 'mehar.test/api/auth/verify-otp' => Http::response(['token' => 'tok-1', 'user' => ['name' => 'Asha', 'role' => 'manager']])]);
    expect($bot()->handle('login')[0])->toContain('mobile number* or *email*');
    expect($bot()->handle('+91 90000 00001')[0])->toContain('OTP was sent to ••••••0001');
    expect(implode("\n", $bot()->handle('123456')))->toContain('Logged in as *Asha*');
}

it('logs in with an OTP and keeps the token encrypted', function () {
    meharLogin($this->bot);

    $session = Cache::get('mehar-bot:919000000001');
    expect($session['token'])->not->toBe('tok-1')->and($session['step'])->toBe('menu');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/request-otp') && $r['phone'] === '9000000001');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/verify-otp') && $r['phone'] === '9000000001' && $r['otp'] === '123456');
});

it('asks for an email and password when the number is unknown, and stops after three wrong tries', function () {
    Http::fake(['mehar.test/api/auth/request-otp' => Http::response(['error' => 'not found'], 404), 'mehar.test/api/auth/login' => Http::response(['error' => 'bad'], 401)]);
    ($this->bot)()->handle('login');
    expect(($this->bot)()->handle('9876500000')[0])->toContain('No dashboard account uses this number');
    expect(($this->bot)()->handle('asha@example.com')[0])->toContain('send your password');
    expect(($this->bot)()->handle('wrong1')[0])->toContain('Try again');
    ($this->bot)()->handle('wrong2');
    expect(($this->bot)()->handle('wrong3')[0])->toContain('Too many tries')
        ->and(Cache::has('mehar-bot:919000000001'))->toBeFalse();
});

it('searches loan files, opens one and sends its PDF', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=Ravi' => Http::response(['data' => [['id' => 7, 'loan_number' => 'ML-7', 'applicant_name' => 'Ravi', 'vehicle_number' => 'RJ14AB1234', 'status' => 'disbursed']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'ML-7', 'applicant_name' => 'Ravi', 'loan_amount' => 500000]]),
        'mehar.test/api/pdf/loan/7' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
    ]);

    ($this->bot)()->handle('1');
    expect(($this->bot)()->handle('Ravi')[0])->toContain('1. ML-7 · Ravi · RJ14AB1234 · Disbursed');
    expect(($this->bot)()->handle('1')[0])->toContain('*Loan amount:* ₹5,00,000')->toContain('*1* Loan PDF');
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
    foreach (['1', 'ML-7', '3'] as $message) {
        ($this->bot)()->handle($message);
    }

    expect(($this->bot)()->handle('RC copy', ['id' => 'media-1', 'mimetype' => 'application/pdf', 'filename' => 'rc.pdf', 'size' => 4])[0])->toContain('Uploaded *RC copy*');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/loans/7/documents') && $r->isMultipart());
});

it('logs out when the backend says the token expired', function () {
    meharLogin($this->bot);
    Http::fake(['mehar.test/api/external/surepass/rc-full' => Http::response(['error' => 'expired'], 401)]);
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
    Http::fake(['coolify-ollama:11434/api/chat' => fakeYogii([], 'A hypothecation is the bank lien on the vehicle.')]);

    expect(($this->bot)()->handle('what is hypothecation?')[0])->toBe('A hypothecation is the bank lien on the vehicle.');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api/chat') && $r['model'] === 'yogii:latest' && ! isset($r['format']) && ! $r->hasHeader('Authorization'));
});

it('opens Mehar Advisory from the Yogii menu', function () {
    $owner = User::factory()->create();
    $conversation = AiConversation::create(['user_id' => $owner->id, 'channel' => 'whatsapp', 'channel_id' => '919000000003', 'messages' => [], 'read_only' => false]);
    $assistant = app(AiAssistant::class);

    expect($assistant->handle($conversation, 'menu'))->toContain('4. 🏦 Mehar Advisory');
    expect($assistant->handle($conversation, '4'))->toContain('Yogii · Mehar Advisory login')
        ->and(Cache::get('mehar-bot:919000000003')['step'])->toBe('login-id');
});

it('opens a loan file straight away when the loan number matches exactly', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737'], ['id' => 9, 'loan_number' => 'MEHCAR0393']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'MEHCAR0737', 'applicant_name' => 'Jaygopal']]),
    ]);
    ($this->bot)()->handle('1');

    expect(($this->bot)()->handle('mehcar 0737')[0])->toContain('Loan file')->toContain('*Applicant:* Jaygopal');
});

it('checks challans with the chassis and engine numbers from the RC', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/external/surepass/rc-full' => Http::response(['data' => ['vehicle_chasi_number' => 'CH123', 'vehicle_engine_number' => 'EN456']]),
        'mehar.test/api/external/surepass/challan' => Http::response(['data' => ['challan_details' => [['challan_date' => '2026-10-01', 'amount' => 1500, 'challan_status' => 'Pending']]]]),
    ]);
    ($this->bot)()->handle('4');

    expect(($this->bot)()->handle('rj60 hs 6517')[0])->toContain('1 challan(s)')->toContain('₹1,500');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/challan') && $r['rc_number'] === 'RJ60HS6517' && $r['chassis_number'] === 'CH123' && $r['engine_number'] === 'EN456');
});

it('shows the loan in sections with readable values', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'MEHCAR0737', 'aadhar_number' => '123412341234', 'pan_number' => 'ABCDE1234F', 'disbursement_date' => '2026-10-01T00:00:00.000Z', 'emi_amount' => '9850', 'status' => 'disbursed', 'secret_token' => 'x']]),
    ]);
    ($this->bot)()->handle('1');
    $loan = ($this->bot)()->handle('MEHCAR0737')[0];

    expect($loan)->toContain('*— Customer —*')->toContain('*Aadhaar:* •••• •••• 1234')->toContain('*PAN:* ••••••234F')
        ->toContain('*Disbursed on:* 01 Oct 2026')->toContain('*EMI:* ₹9,850')->toContain('*Status:* Disbursed')
        ->not->toContain('123412341234')->not->toContain('— Insurance —');
    expect(($this->bot)()->handle('5')[0])->toContain('*Loan Number:* MEHCAR0737')->not->toContain('Secret Token');
});

it('takes a loan or vehicle number typed straight in, and rc/challan/payment commands, from any screen', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'MEHCAR0737']]),
        'mehar.test/api/external/surepass/rc-full' => Http::response(['data' => ['owner_name' => 'JAYGOPAL']]),
        'mehar.test/api/payments/applications' => Http::response([['id' => 3, 'loan_number' => 'MEHCAR0737', 'amount' => 1000]]),
    ]);

    expect(($this->bot)()->handle('MEHCAR0737')[0])->toContain('What do you want for *MEHCAR0737*?')->toContain('*2* Loan PDF');
    expect(($this->bot)()->handle('1')[0])->toContain('Loan file MEHCAR0737');
    expect(($this->bot)()->handle('RJ31UA4510')[0])->toContain('*2* RC details')->toContain('*3* Challan check');
    expect(($this->bot)()->handle('2')[0])->toContain('*Owner:* JAYGOPAL');
    expect(($this->bot)()->handle('rc RJ31UA4510')[0])->toContain('*Owner:* JAYGOPAL');
    expect(($this->bot)()->handle('payment MEHCAR0737')[0])->toContain('Payment applications');
});

it('lets an approver approve a payment with remarks, and hides that from others', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/payments/applications/3/manager-action' => Http::response(['ok' => true]),
        'mehar.test/api/payments/applications/3' => Http::response(['data' => ['id' => 3, 'amount' => 1000, 'status' => 'submitted']]),
        'mehar.test/api/payments/applications' => Http::response([['id' => 3, 'loan_number' => 'MEHCAR0737']]),
    ]);
    Cache::put('mehar-bot:919000000001', [...Cache::get('mehar-bot:919000000001'), 'role' => 'super_admin'], 60);
    foreach (['2', 'MEHCAR0737'] as $message) {
        ($this->bot)()->handle($message);
    }
    expect(($this->bot)()->handle('1')[0])->toContain('*3* Approve');
    expect(($this->bot)()->handle('3')[0])->toContain('send your remarks');
    expect(($this->bot)()->handle('Checked, OK')[0])->toContain('Done: approve');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/manager-action') && $r['action'] === 'approve' && $r['remarks'] === 'Checked, OK');

    $session = Cache::get('mehar-bot:919000000001');
    Cache::put('mehar-bot:919000000001', [...$session, 'role' => 'sales'], 60);
    ($this->bot)()->handle('payment MEHCAR0737');
    expect(($this->bot)()->handle('1')[0])->toContain('*2* All fields')->not->toContain('Approve');
    expect(($this->bot)()->handle('3')[0])->toContain('Pick a number');
});

it('understands chat as well as the menu', function () {
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=jaygopal' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737', 'applicant_name' => 'JAYGOPAL'], ['id' => 8, 'loan_number' => 'MEHCAR0800', 'applicant_name' => 'JAYGOPAL S']]]),
        'mehar.test/api/loans?search=MEHCAR0737' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737']]]),
        'mehar.test/api/loans?search=*' => Http::response(['data' => []]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'MEHCAR0737']]),
        'mehar.test/api/pdf/loan/7' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        'mehar.test/api/external/surepass/rc-full' => Http::response(['data' => ['vehicle_chasi_number' => 'C1', 'vehicle_engine_number' => 'E1']]),
        'mehar.test/api/external/surepass/challan' => Http::response(['data' => ['challan_details' => []]]),
    ]);

    expect(($this->bot)()->handle('jaygopal ki file')[0])->toContain('*Loan files*')->toContain('MEHCAR0800');
    expect(($this->bot)()->handle('RJ60HS6517 ka challan')[0])->toContain('No challans found');
    $replies = ($this->bot)()->handle('MEHCAR0737 pdf');
    expect($replies[0])->toContain('Loan file MEHCAR0737')->and($replies[1]['filename'])->toBe('Loan-MEHCAR0737.pdf');
    expect(($this->bot)()->handle('1')[0])->toBeArray(); // numbers still pick menu options
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'search=jaygopal'));
});

it('sends a name that finds nothing, or a question, to Yogii', function () {
    AiSettings::current()->update(['model' => AiSettings::LOCAL_PREFIX.'yogii:latest']);
    meharLogin($this->bot);
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => []]),
        'coolify-ollama:11434/api/chat' => fakeYogii(),
    ]);

    expect(($this->bot)()->handle('good morning team')[0])->toContain('Yogii here.');
    expect(($this->bot)()->handle('how does foreclosure work?')[0])->toContain('Yogii here.');
});

it('shows saved CIBIL reports to managers only, without pulling new ones', function () {
    meharLogin($this->bot); // logged in as a manager
    Http::fake([
        'mehar.test/api/loans?search=*' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737']]]),
        'mehar.test/api/loans/7' => Http::response(['data' => ['id' => 7, 'loan_number' => 'MEHCAR0737']]),
        'mehar.test/api/credit-reports/loan/7' => Http::response([['provider' => 'cibil', 'score' => '742', 'customer_name' => 'JAYGOPAL', 'created_at' => '2026-10-01T05:00:00.000Z', 'report_link' => '/uploads/credit-reports/r.pdf']]),
        'mehar.test/uploads/credit-reports/r.pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
    ]);

    expect(($this->bot)()->handle('MEHCAR0737')[0])->toContain('*5* CIBIL reports');
    expect(($this->bot)()->handle('1')[0])->toContain('*6* CIBIL reports');
    expect(($this->bot)()->handle('6')[0])->toContain('*1* CIBIL · Score 742 · JAYGOPAL · 01 Oct 2026');
    expect(($this->bot)()->handle('1')[0]['filename'])->toBe('CIBIL-MEHCAR0737-1.pdf');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'credit-report') && $r->method() === 'POST');

    Cache::put('mehar-bot:919000000001', [...Cache::get('mehar-bot:919000000001'), 'role' => 'sales'], 60);
    expect(($this->bot)()->handle('MEHCAR0737')[0])->not->toContain('CIBIL');
    expect(($this->bot)()->handle('MEHCAR0737 file')[0])->not->toContain('CIBIL');
    expect(($this->bot)()->handle('6')[0])->toContain('Pick a number');
});

it('lets Yogii read a free sentence and act on it', function () {
    AiSettings::current()->update(['model' => AiSettings::LOCAL_PREFIX.'yogii:latest']);
    meharLogin($this->bot);
    Http::fake([
        'coolify-ollama:11434/api/chat' => fakeYogii([
            'bhai jaygopal wali file bhejna' => ['intent' => 'loan', 'query' => 'jaygopal'],
            'RJ60HS6517 gaadi pe koi chalan pending hai kya' => ['intent' => 'challan', 'query' => 'RJ60HS6517'],
            'mohan ka payment kab tak aayega' => ['intent' => 'payments', 'query' => ''],
        ]),
        'mehar.test/api/loans?search=jaygopal' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737'], ['id' => 8, 'loan_number' => 'MEHCAR0800']]]),
        'mehar.test/api/external/surepass/rc-full' => Http::response(['data' => ['vehicle_chasi_number' => 'C1', 'vehicle_engine_number' => 'E1']]),
        'mehar.test/api/external/surepass/challan' => Http::response(['data' => ['challan_details' => []]]),
    ]);

    expect(($this->bot)()->handle('bhai jaygopal wali file bhejna')[0])->toContain('*Loan files*');
    // The keyword is spelt "chalan", which the instant rules also know.
    expect(($this->bot)()->handle('RJ60HS6517 gaadi pe koi chalan pending hai kya')[0])->toContain('No challans found');
    expect(($this->bot)()->handle('mohan ka payment kab tak aayega')[0])->toContain('Which loan number, vehicle number or name?');
    expect(Cache::get('mehar-bot:919000000001')['step'])->toBe('find-payment');
});

it('searches a mobile number typed straight in', function () {
    meharLogin($this->bot);
    Http::fake(['mehar.test/api/loans?search=9462394650' => Http::response(['data' => [['id' => 7, 'loan_number' => 'MEHCAR0737', 'mobile' => '9462394650']]])]);

    expect(($this->bot)()->handle('9462394650')[0])->toContain('MEHCAR0737');
});

it('chats normally with Yogii and remembers the last messages', function () {
    AiSettings::current()->update(['model' => AiSettings::LOCAL_PREFIX.'yogii:latest']);
    meharLogin($this->bot);
    Http::fake(['coolify-ollama:11434/api/chat' => fakeYogii([], 'Haha, sure!')]);

    expect(($this->bot)()->handle('ek joke sunao')[0])->toBe('Haha, sure!');
    ($this->bot)()->handle('aur ek');
    Http::assertSent(fn (Request $r) => ! isset($r['format']) && collect($r['messages'])->pluck('content')->contains('ek joke sunao') && collect($r['messages'])->last()['content'] === 'aur ek');
});
