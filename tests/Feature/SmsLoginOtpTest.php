<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('services.sms', [
        'base_url' => 'http://sms.test',
        'user' => 'user',
        'password' => 'secret',
        'sender_id' => 'SENDER',
        'entity_id' => '1',
        'otp_template_id' => '2',
        'otp_message' => 'Your OTP is :code.',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->user = User::factory()->create(['email' => 'sms@example.com', 'phone' => '9876543210']);
    $this->user->teams->first()->update(['show_boarding' => false]);
});

/** Fakes the gateway and returns a closure that reads the code from the last SMS sent. */
function fakeSmsGateway(int $status = 200, string $body = '{"status":"success"}'): Closure
{
    Http::fake(['sms.test/*' => Http::response($body, $status)]);

    return function (): string {
        $sent = Http::recorded()->last()[0];
        parse_str(parse_url($sent->url(), PHP_URL_QUERY), $query);
        preg_match('/\d{6}/', $query['sms'], $match);

        return $match[0];
    };
}

it('asks for an SMS code after a correct password and signs in with it', function () {
    $lastCode = fakeSmsGateway();

    $this->post('/login', ['email' => 'sms@example.com', 'password' => 'password'])
        ->assertRedirect(route('login.sms-otp'));
    $this->assertGuest();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'mobiles=9876543210') && str_contains($request->url(), 'tempid=2'));
    $this->get(route('login.sms-otp'))->assertSuccessful()->assertSee('******3210');

    $this->post(route('login.sms-otp.verify'), ['code' => $lastCode()])->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});

it('rejects a wrong code and ends the pending login after too many tries', function () {
    fakeSmsGateway();
    $this->post('/login', ['email' => 'sms@example.com', 'password' => 'password']);

    $this->post(route('login.sms-otp.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.sms-otp.verify'), ['code' => '000000']);
    }
    $this->post(route('login.sms-otp.verify'), ['code' => '000000'])->assertRedirect(route('login'));
    $this->get(route('login.sms-otp'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('does not send a code for a wrong password', function () {
    fakeSmsGateway();

    $this->post('/login', ['email' => 'sms@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');

    Http::assertNothingSent();
    $this->assertGuest();
});

it('keeps the user signed out when the SMS cannot be sent', function () {
    fakeSmsGateway(500, 'gateway down');

    $this->post('/login', ['email' => 'sms@example.com', 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->get(route('login.sms-otp'))->assertRedirect(route('login'));
});

it('signs in directly when the user has no number or SMS is not configured', function (bool $hasPhone, bool $configured) {
    fakeSmsGateway();
    $this->user->update(['phone' => $hasPhone ? '9876543210' : null]);
    if (! $configured) {
        config()->set('services.sms.password', null);
    }

    $this->post('/login', ['email' => 'sms@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->user);
    Http::assertNothingSent();
})->with([
    'no mobile number' => [false, true],
    'gateway not configured' => [true, false],
]);
