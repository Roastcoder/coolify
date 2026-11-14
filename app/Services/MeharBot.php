<?php

namespace App\Services;

use App\Models\AiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fork-only: Mehar Advisory inside Yogii, the WhatsApp assistant. A dashboard user logs in (OTP to their number, or ID and password)
 * and then works through numbered menus: find a loan file or payment application, RC search, challan check,
 * PDFs and documents. Every call goes to the Mehar backend with the user's own token, so the backend's
 * permissions apply exactly as in the dashboard. The session lives 12 hours in the cache, token encrypted.
 */
class MeharBot
{
    private const SESSION_HOURS = 12;

    private const MAX_LOGIN_ATTEMPTS = 3;

    private const LIST_LIMIT = 10;

    /** @var array<int, string|array{text: string, file: string, filename: string, mimetype: string}> */
    private array $replies = [];

    private array $session = [];

    public function __construct(private string $number, private ?string $apiUrl = null)
    {
        $this->apiUrl ??= AiSettings::current()->mehar_api_url;
        $this->session = Cache::get(self::key($number), []);
    }

    public static function enabled(): bool
    {
        return filled(AiSettings::current()->mehar_api_url);
    }

    /** The bot takes the message when this number has a session or asks to log in. */
    public static function wants(string $number, string $body): bool
    {
        return self::enabled() && (Cache::has(self::key($number)) || preg_match('/^\s*(login|mehar)\s*$/i', $body));
    }

    private static function key(string $number): string
    {
        return 'mehar-bot:'.preg_replace('/\D/', '', $number);
    }

    /**
     * Handles one message and returns the replies to send: texts, or files with a caption.
     *
     * @param  array{id: string, mimetype: string, filename: ?string, size: int}|null  $media
     * @return array<int, string|array{text: string, file: string, filename: string, mimetype: string}>
     */
    public function handle(string $body, ?array $media = null): array
    {
        $text = trim($body);
        $command = strtolower($text);

        try {
            if (in_array($command, ['logout', 'log out', 'exit'], true)) {
                $this->forget('Logged out of Mehar Advisory. Send *login* to log in again.');
            } elseif (! isset($this->session['token'])) {
                $this->login($text);
            } elseif (in_array($command, ['menu', 'hi', 'hello', 'start', 'login', 'mehar'], true) || ($command === '0' && in_array($this->step(), ['menu', 'find-loan', 'find-payment', 'rc', 'challan', 'loans', 'payments'], true))) {
                $this->menu();
            } elseif ($media || in_array($this->step(), ['upload', 'payment-action'], true) || ! $this->direct($text)) {
                $this->step($text, $media);
            }
        } catch (ConnectionException) {
            $this->say('The Mehar server is not reachable right now. Try again in a minute.');
        }
        $this->save();

        return $this->replies;
    }

    private function step(?string $text = null, ?array $media = null): string
    {
        $step = $this->session['step'] ?? 'menu';
        if ($text === null) {
            return $step;
        }

        match ($step) {
            'menu' => $this->menuChoice($text),
            'find-loan' => $this->findLoans($text),
            'loans' => $this->openFromList($text, fn (string $id) => $this->showLoan($id)),
            'loan' => $this->loanChoice($text),
            'documents' => $this->documentChoice($text),
            'upload' => $this->upload($text, $media),
            'find-payment' => $this->findPayments($text),
            'payments' => $this->openFromList($text, fn (string $id) => $this->showPayment($id)),
            'payment' => $this->paymentChoice($text),
            'payment-action' => $this->paymentAction($text),
            'rc' => $this->rcSearch($text),
            'challan' => $this->challanCheck($text),
            default => $this->menu(),
        };

        return $step;
    }

    /**
     * Typed straight in, without picking a menu number first: "rc RJ14AB1234", "challan …", "payment …", "loan …",
     * or a bare loan, file or vehicle number from any list or detail screen.
     */
    private function direct(string $text): bool
    {
        if (preg_match('/^(rc|challan|loan|payment|pay)\s+(.+)$/i', $text, $m)) {
            $query = trim($m[2]);
            match (strtolower($m[1])) {
                'rc' => [$this->session['step'] = 'rc', $this->rcSearch($query)],
                'challan' => [$this->session['step'] = 'challan', $this->challanCheck($query)],
                'loan' => $this->findLoans($query),
                default => $this->findPayments($query),
            };

            return true;
        }
        // A single token with letters and digits (MEHCAR0737, RJ60HS6517) is a loan, file or vehicle number.
        $browsing = in_array($this->step(), ['menu', 'loans', 'loan', 'documents', 'payments', 'payment'], true);
        if ($browsing && preg_match('/^(?=.*\d)(?=.*[a-z])[a-z0-9\-\/ ]{5,20}$/i', $text) && ! str_contains(trim($text), '  ')) {
            $this->findLoans($text);

            return true;
        }

        return false;
    }

    // Login

    private function login(string $text): void
    {
        $step = $this->session['step'] ?? null;
        $attempts = $this->session['attempts'] ?? 0;

        if ($step === null || in_array(strtolower($text), ['login', 'mehar'], true)) {
            $this->session = ['step' => 'login-choice'];
            $this->say("*Yogii · Mehar Advisory login*\n\n*1* OTP to this WhatsApp number\n*2* ID and password\n\nReply with a number.");

            return;
        }
        if ($step === 'login-choice' && $text === '1') {
            $response = $this->api()->post('/api/auth/request-otp', ['phone' => $this->phone(), 'purpose' => 'login']);
            if ($response->status() === 404) {
                $this->say("No dashboard account uses this number.\nReply *2* to log in with your ID and password.");

                return;
            }
            if (! $response->successful()) {
                $this->say('Could not send the OTP: '.$this->error($response));

                return;
            }
            $this->session['step'] = 'otp';
            $this->say('An OTP was sent to your registered mobile. Send the 6 digits here.');

            return;
        }
        if ($step === 'login-choice' && $text === '2') {
            $this->session['step'] = 'login-id';
            $this->say('Send your login ID (email or mobile number).');

            return;
        }
        if ($step === 'otp' && preg_match('/^\d{4,8}$/', $text)) {
            $response = $this->api()->post('/api/auth/verify-otp', ['phone' => $this->phone(), 'otp' => $text, 'purpose' => 'login']);
            $this->finishLogin($response, $attempts, 'Wrong or expired OTP.');

            return;
        }
        if ($step === 'login-id' && $text !== '') {
            $this->session['step'] = 'login-password';
            $this->session['login_id'] = mb_substr($text, 0, 200);
            $this->say("Now send your password.\n\n🔒 After logging in, delete that message from this chat: WhatsApp keeps it in the chat history.");

            return;
        }
        if ($step === 'login-password' && $text !== '') {
            $response = $this->api()->post('/api/auth/login', ['email' => $this->session['login_id'] ?? '', 'password' => $text]);
            $this->finishLogin($response, $attempts, 'Wrong ID or password.');

            return;
        }
        $this->say(match ($step) {
            'otp' => 'Send the OTP digits, or *login* to start again.',
            'login-choice' => 'Reply *1* for an OTP or *2* for ID and password.',
            default => 'Send *login* to start again.',
        });
    }

    private function finishLogin(Response $response, int $attempts, string $wrong): void
    {
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token)) {
            if (++$attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $this->forget("{$wrong} Too many tries: send *login* to start again.");

                return;
            }
            $this->session['attempts'] = $attempts;
            $this->say("{$wrong} Try again.");

            return;
        }
        $this->session = [
            'token' => Crypt::encryptString($token),
            'name' => (string) $response->json('user.name', ''),
            'role' => (string) $response->json('user.role', ''),
            'step' => 'menu',
        ];
        $this->menu('✅ Logged in as *'.($this->session['name'] ?: 'you').'*.');
    }

    // Menu

    private function menu(string $lead = ''): void
    {
        $this->session['step'] = 'menu';
        $this->say(trim($lead."\n\n*Yogii · Mehar Advisory*\n\n*1* Find a loan file\n*2* Find a payment application\n*3* RC search\n*4* Challan check\n*9* Log out\n\nReply with a number, or type a loan or vehicle number straight in. *menu* brings you back here."));
    }

    private function menuChoice(string $text): void
    {
        $prompts = [
            '1' => ['find-loan', 'Send a loan number, file number, RC number, or the applicant or customer name.'],
            '2' => ['find-payment', 'Send a loan number, RC number, or the applicant, customer or beneficiary name.'],
            '3' => ['rc', 'Send the vehicle number, for example RJ14AB1234.'],
            '4' => ['challan', 'Send the vehicle number to check challans.'],
        ];
        if ($text === '9') {
            $this->forget('Logged out of Mehar Advisory. Send *login* to log in again.');

            return;
        }
        if (! isset($prompts[$text])) {
            ctype_digit($text) ? $this->menu('Pick one of the numbers.') : $this->askYogii($text);

            return;
        }
        [$this->session['step'], $prompt] = $prompts[$text];
        $this->say($prompt."\n\n*0* Back");
    }

    // Loan files

    private function findLoans(string $search): void
    {
        $response = $this->authed()?->get('/api/loans', ['search' => $search]);
        if (! $response) {
            return;
        }
        $loans = collect($response->json('data', $response->json()) ?? [])->take(self::LIST_LIMIT)->values();
        if ($loans->isEmpty()) {
            $this->say("No loan file found for \"{$search}\". Try another number or name.\n\n*0* Back");

            return;
        }
        // A typed loan, file or vehicle number that matches one file exactly opens it straight away.
        $key = fn ($value) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
        $exact = $loans->filter(fn (array $loan) => in_array($key($search), array_map($key, [$loan['loan_number'] ?? '', $loan['file_number'] ?? '', $loan['vehicle_number'] ?? '']), true));
        if ($exact->count() === 1) {
            $this->showLoan((string) $exact->first()['id']);

            return;
        }
        $this->session['step'] = 'loans';
        $this->session['list'] = $loans->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->say("*Loan files*\n".$loans->map(fn (array $loan, int $i) => ($i + 1).'. '.$this->loanLine($loan))->implode("\n")."\n\nReply with a number to open one. *0* Back");
    }

    private function loanLine(array $loan): string
    {
        return collect([
            $loan['loan_number'] ?? $loan['file_number'] ?? '#'.$loan['id'],
            $loan['applicant_name'] ?? $loan['customer_name'] ?? null,
            $loan['vehicle_number'] ?? null,
            isset($loan['status']) ? Str::headline((string) $loan['status']) : null,
        ])->filter()->implode(' · ');
    }

    private function openFromList(string $text, \Closure $open): void
    {
        $id = ctype_digit($text) ? ($this->session['list'][(int) $text - 1] ?? null) : null;
        if ($id === null) {
            $this->say('Reply with a number from the list, or *0* to go back.');

            return;
        }
        $open($id);
    }

    /** The loan screen: every useful field, grouped. Label => backend field (the first filled one wins). */
    private const LOAN_SECTIONS = [
        'Customer' => [
            'Applicant' => 'applicant_name', 'Customer' => 'customer_name', 'Mobile' => 'mobile', 'PAN' => 'pan_number', 'Aadhaar' => 'aadhar_number',
            'Address' => 'current_address', 'District' => 'current_district', 'State' => 'current_state', 'Pincode' => 'current_pincode',
            'Income source' => 'income_source', 'Monthly income' => 'monthly_income',
        ],
        'Vehicle' => [
            'Number' => 'vehicle_number', 'Maker' => 'maker_name', 'Model' => 'model_variant_name', 'Mfg year' => 'mfg_year',
            'Chassis' => 'chassis_number', 'Engine' => 'engine_number', 'RC owner' => 'rc_owner_name', 'Registered' => 'registration_date',
        ],
        'Loan' => [
            'Loan number' => 'loan_number', 'File number' => 'file_number', 'Bank' => ['bank_name', 'assigned_bank_name'], 'Scheme' => 'scheme', 'Vertical' => 'vertical',
            'Loan amount' => 'loan_amount', 'Sanctioned' => 'sanction_amount', 'Sanction date' => 'sanction_date', 'LTV' => 'ltv', 'IRR' => ['irr', 'interest_rate'],
            'Tenure' => 'tenure', 'EMI' => ['emi_amount', 'emi'], 'EMI start' => 'emi_start_date', 'EMI end' => 'emi_end_date', 'Processing fee' => 'processing_fee',
        ],
        'Disbursement' => [
            'Net disbursement' => 'net_disbursement_amount', 'Disbursed on' => 'disbursement_date', 'In favour of' => 'payment_in_favour',
            'Mehar deduction' => 'mehar_deduction', 'Hold amount' => 'hold_amount', 'Received' => 'received_amount_1', 'Received on' => 'received_date_1',
        ],
        'Insurance' => [
            'Company' => 'insurance_company_name', 'Policy' => 'insurance_policy_number', 'Premium' => 'premium_amount', 'Date' => 'insurance_date',
        ],
        'Status' => [
            'Status' => 'status', 'Stage' => 'workflow_stage', 'With' => 'current_assignee_role', 'Branch' => 'our_branch', 'Sourced by' => 'sourcing_person_name',
            'Logged in' => 'login_date', 'Approved' => 'approval_date', 'PDD' => 'pdd_status', 'RTO agent' => 'rto_agent_name', 'Remark' => 'remark',
        ],
    ];

    private function showLoan(string $id): void
    {
        $response = $this->authed()?->get("/api/loans/{$id}");
        if (! $response) {
            return;
        }
        $loan = (array) $response->json('data', $response->json());
        $number = (string) ($loan['loan_number'] ?? $loan['file_number'] ?? $id);
        $this->session['step'] = 'loan';
        $this->session['loan'] = ['id' => $id, 'number' => $number];

        $sections = collect(self::LOAN_SECTIONS)->map(function (array $fields, string $title) use ($loan) {
            $lines = collect($fields)->map(function ($keys, string $label) use ($loan) {
                $key = collect((array) $keys)->first(fn (string $k) => filled($loan[$k] ?? null));

                return $key ? "*{$label}:* ".$this->value($key, $loan[$key]) : null;
            })->filter();

            return $lines->isEmpty() ? null : "*— {$title} —*\n".$lines->implode("\n");
        })->filter();

        $this->say(Str::limit("📁 *Loan file {$number}*\n\n".$sections->implode("\n\n"), 3600)
            ."\n\n*Reply with a number*\n*1* Loan PDF\n*2* Documents\n*3* Upload a document\n*4* Payment applications\n*5* All fields\n*0* Back");
    }

    /** One value as people read it: rupees, dates, masked IDs, Yes/No. */
    private function value(string $key, mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : trim((string) $value);
        if (preg_match('/(amount|income|fee|premium|deduction|emi$|^emi_amount$)/', $key) && is_numeric($text)) {
            return $this->money($text);
        }
        if (preg_match('/aadha?r/', $key)) {
            return '•••• •••• '.substr(preg_replace('/\D/', '', $text), -4);
        }
        if (str_contains($key, 'pan_number') && strlen($text) >= 4) {
            return '••••••'.substr($text, -4);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:.]+Z?)?$/', $text)) {
            return Carbon::parse($text)->timezone('Asia/Kolkata')->format('d M Y');
        }
        if (in_array($key, ['status', 'workflow_stage', 'current_assignee_role', 'pdd_status', 'payment_type'], true)) {
            return Str::headline($text);
        }

        return Str::limit($text, 120);
    }

    private function loanChoice(string $text): void
    {
        $loan = $this->session['loan'];
        match ($text) {
            '1' => $this->sendPdf("/api/pdf/loan/{$loan['id']}", "Loan-{$loan['number']}.pdf", "Loan file {$loan['number']}"),
            '2' => $this->listDocuments($loan['id']),
            '3' => $this->askUpload(),
            '4' => $this->paymentsOfLoan($loan),
            '5' => $this->fullDetails("/api/loans/{$loan['id']}", "📋 *Loan file {$loan['number']}: all fields*"),
            '0' => $this->findLoansAgain(),
            default => $this->say('Pick 1 to 5, or *0* to go back.'),
        };
    }

    private function findLoansAgain(): void
    {
        $this->session['step'] = 'find-loan';
        $this->say("Send another loan number, RC number or name.\n\n*0* Menu");
    }

    private function listDocuments(string $loanId): void
    {
        $response = $this->authed()?->get("/api/loans/{$loanId}/documents");
        if (! $response) {
            return;
        }
        $documents = collect($response->json('data', $response->json()) ?? [])->filter(fn ($d) => filled($d['file_url'] ?? null))->take(20)->values();
        if ($documents->isEmpty()) {
            $this->say("No documents on this file yet.\n\n*3* Upload a document\n*0* Back");

            return;
        }
        $this->session['step'] = 'documents';
        $this->session['documents'] = $documents->map(fn (array $d) => ['url' => $d['file_url'], 'name' => $d['document_name'] ?? $d['document_type'] ?? 'document'])->all();
        $this->say("📎 *Documents*\n".$documents->map(fn (array $d, int $i) => ($i + 1).'. '.Str::headline((string) ($d['document_name'] ?? $d['document_type'] ?? 'document')))->implode("\n")."\n\nReply with a number to download it. *0* Back");
    }

    private function documentChoice(string $text): void
    {
        if ($text === '0') {
            $this->showLoan($this->session['loan']['id']);

            return;
        }
        $document = ctype_digit($text) ? ($this->session['documents'][(int) $text - 1] ?? null) : null;
        if (! $document) {
            $this->say('Reply with a number from the list, or *0* to go back.');

            return;
        }
        $extension = pathinfo(parse_url($document['url'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'pdf';
        $this->sendPdf($document['url'], Str::slug($document['name']).'.'.$extension, Str::headline($document['name']));
    }

    private function askUpload(): void
    {
        $this->session['step'] = 'upload';
        $this->say("Send the document as a photo or a PDF. Add a caption with its name, for example *RC copy*.\n\n*0* Back");
    }

    /** @param  array{id: string, mimetype: string, filename: ?string, size: int}|null  $media */
    private function upload(string $caption, ?array $media): void
    {
        if ($caption === '0' && ! $media) {
            $this->showLoan($this->session['loan']['id']);

            return;
        }
        if (! $media) {
            $this->say('Send the document as a photo or a PDF, or *0* to go back.');

            return;
        }
        $file = app(WhatsappGateway::class)->media($media['id']);
        if (! $file) {
            $this->say('That file did not come through. Send it again.');

            return;
        }
        $name = $caption !== '' ? mb_substr($caption, 0, 100) : ($file['filename'] ?: 'WhatsApp document');
        $filename = $file['filename'] ?: Str::slug($name).'.'.(str_contains($file['mimetype'], 'pdf') ? 'pdf' : 'jpg');
        $response = $this->authed(fn (PendingRequest $request) => $request->attach('file', $file['contents'], $filename))
            ?->post("/api/loans/{$this->session['loan']['id']}/documents", ['document_type' => 'other', 'document_name' => $name]);
        if (! $response) {
            return;
        }
        $this->say("✅ Uploaded *{$name}* to loan file {$this->session['loan']['number']}.\n\nSend another document, or *0* to go back.");
    }

    // Payment applications

    private function findPayments(string $search, ?string $loanId = null): void
    {
        $response = $this->authed()?->get('/api/payments/applications');
        if (! $response) {
            return;
        }
        $needle = Str::lower(preg_replace('/\s+/', '', $search));
        $payments = collect($response->json('data', $response->json()) ?? [])
            ->filter(fn (array $p) => $loanId !== null
                ? (string) ($p['loan_id'] ?? '') === $loanId
                : collect(['loan_number', 'vehicle_number', 'applicant_name', 'customer_name', 'beneficiary_name', 'id'])
                    ->contains(fn (string $field) => str_contains(Str::lower(preg_replace('/\s+/', '', (string) ($p[$field] ?? ''))), $needle)))
            ->take(self::LIST_LIMIT)->values();
        if ($payments->isEmpty()) {
            $this->say(($loanId ? 'No payment applications on this file.' : "No payment application found for \"{$search}\".")."\n\n*0* Back");
            $this->session['step'] = $loanId ? 'loan' : 'find-payment';

            return;
        }
        $this->session['step'] = 'payments';
        $this->session['list'] = $payments->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->say("*Payment applications*\n".$payments->map(fn (array $p, int $i) => ($i + 1).'. '.collect([
            $p['loan_number'] ?? null, $p['beneficiary_name'] ?? $p['applicant_name'] ?? null,
            isset($p['amount']) ? $this->money($p['amount']) : null, isset($p['status']) ? Str::headline((string) $p['status']) : null,
        ])->filter()->implode(' · '))->implode("\n")."\n\nReply with a number to open one. *0* Back");
    }

    private function paymentsOfLoan(array $loan): void
    {
        $this->findPayments('', $loan['id']);
    }

    private function showPayment(string $id): void
    {
        $response = $this->authed()?->get("/api/payments/applications/{$id}");
        if (! $response) {
            return;
        }
        $payment = $response->json('data', $response->json());
        $this->session['step'] = 'payment';
        $this->session['payment'] = $id;
        $this->say("💸 *Payment application {$id}*\n\n".$this->lines([
            'Loan number' => $payment['loan_number'] ?? null,
            'Type' => isset($payment['payment_type']) ? Str::headline((string) $payment['payment_type']) : null,
            'Amount' => isset($payment['amount']) ? $this->money($payment['amount']) : null,
            'Beneficiary' => $payment['beneficiary_name'] ?? null,
            'Bank' => trim(($payment['bank_name'] ?? '').' '.(isset($payment['account_number']) ? '••'.substr((string) $payment['account_number'], -4) : '')) ?: null,
            'Status' => isset($payment['status']) ? Str::headline((string) $payment['status']) : null,
            'UTR' => $payment['utr_number'] ?? null,
        ])."\n\n*Reply with a number*\n*1* Payment PDF\n*2* All fields".($this->isApprover() ? "\n*3* Approve\n*4* Reject\n*5* Send back" : '')."\n*0* Back");
    }

    /** Roles the backend lets act on payment applications; it still checks the stage and the branch. */
    private function isApprover(): bool
    {
        return in_array(strtolower($this->session['role'] ?? ''), ['super_admin', 'admin', 'rbm', 'di', 'insurance_manager', 'accountant', 'accounts'], true);
    }

    private function askPaymentAction(string $action): void
    {
        $this->session['step'] = 'payment-action';
        $this->session['action'] = $action;
        $label = ['approve' => 'approve', 'reject' => 'reject', 'send_back' => 'send back'][$action];
        $this->say("To *{$label}* payment application {$this->session['payment']}, send your remarks now. Your message confirms it.\n\n*0* Cancel");
    }

    private function paymentAction(string $remarks): void
    {
        $action = $this->session['action'] ?? null;
        unset($this->session['action']);
        if ($remarks === '0' || ! $action) {
            $this->showPayment($this->session['payment']);

            return;
        }
        $this->session['step'] = 'payment';
        $response = $this->authed()?->post("/api/payments/applications/{$this->session['payment']}/manager-action", ['action' => $action, 'remarks' => mb_substr($remarks, 0, 1000)]);
        if (! $response) {
            return;
        }
        $this->say('✅ Done: '.str_replace('_', ' ', $action)." on payment application {$this->session['payment']}.");
        $this->showPayment($this->session['payment']);
    }

    /** Every filled field of a record, as the backend returns it for this user's role. */
    private function fullDetails(string $path, string $title): void
    {
        $response = $this->authed()?->get($path);
        if (! $response) {
            return;
        }
        $record = (array) $response->json('data', $response->json());
        $hidden = '/(^id$|_id$|password|token|secret|otp|_url$|^created_by|^updated_by)/i';
        $lines = collect($record)
            ->filter(fn ($value, $key) => is_scalar($value) && filled($value) && ! preg_match($hidden, (string) $key))
            ->map(fn ($value, $key) => '*'.Str::headline((string) $key).':* '.$this->value((string) $key, $value));
        $this->say(Str::limit($title."\n\n".$lines->implode("\n"), 3800)."\n\nThe options above still work. Send *menu* for the main menu.");
    }

    private function paymentChoice(string $text): void
    {
        match ($text) {
            '1' => $this->sendPdf("/api/pdf/payment/{$this->session['payment']}", "Payment-{$this->session['payment']}.pdf", "Payment application {$this->session['payment']}"),
            '2' => $this->fullDetails("/api/payments/applications/{$this->session['payment']}", "📋 *Payment application {$this->session['payment']}: all fields*"),
            '3' => $this->isApprover() ? $this->askPaymentAction('approve') : $this->say('Pick a number from the list.'),
            '4' => $this->isApprover() ? $this->askPaymentAction('reject') : $this->say('Pick a number from the list.'),
            '5' => $this->isApprover() ? $this->askPaymentAction('send_back') : $this->say('Pick a number from the list.'),
            '0' => $this->menuChoice('2'),
            default => $this->say('Pick a number from the list, or *0* to go back.'),
        };
    }

    // RC and challans

    private function rcSearch(string $vehicle): void
    {
        $vehicle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle));
        $response = $this->authed()?->post('/api/external/surepass/rc-full', ['id_number' => $vehicle]);
        if (! $response) {
            return;
        }
        $rc = $response->json('data', $response->json());
        $this->say("🚗 *{$vehicle}*\n".$this->lines([
            'Owner' => $rc['owner_name'] ?? null,
            'Vehicle' => trim(($rc['maker_description'] ?? '').' '.($rc['maker_model'] ?? '')) ?: null,
            'Fuel' => $rc['fuel_type'] ?? null,
            'Registered' => $rc['registration_date'] ?? null,
            'RTO' => $rc['registered_at'] ?? null,
            'Financer' => $rc['financer'] ?? null,
            'Insurance up to' => $rc['insurance_upto'] ?? null,
            'Fitness up to' => $rc['fit_up_to'] ?? null,
            'RC status' => $rc['rc_status'] ?? null,
        ])."\n\nSend another vehicle number, or *0* for the menu.");
    }

    private function challanCheck(string $vehicle): void
    {
        $vehicle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle));
        // The challan service also needs the chassis and engine numbers: take them from the RC (cached by the backend).
        $rc = $this->authed()?->post('/api/external/surepass/rc-full', ['id_number' => $vehicle]);
        if (! $rc) {
            return;
        }
        $chassis = $rc->json('data.vehicle_chasi_number') ?? $rc->json('data.chassis_number');
        $engine = $rc->json('data.vehicle_engine_number') ?? $rc->json('data.engine_number');
        if (blank($chassis) || blank($engine)) {
            $this->say("The RC for {$vehicle} has no chassis or engine number, so challans can't be checked.\n\n*0* Back");

            return;
        }
        $response = $this->authed()?->post('/api/external/surepass/challan', ['rc_number' => $vehicle, 'chassis_number' => $chassis, 'engine_number' => $engine]);
        if (! $response) {
            return;
        }
        $data = $response->json('data', $response->json());
        $challans = collect($data['challan_details'] ?? $data['challans'] ?? (array_is_list((array) $data) ? $data : []))->take(15);
        $this->say($challans->isEmpty()
            ? "🚦 *{$vehicle}*\nNo challans found.\n\nSend another vehicle number, or *0* for the menu."
            : "🚦 *{$vehicle}*: {$challans->count()} challan(s)\n".$challans->map(fn ($c, int $i) => ($i + 1).'. '.collect([
                $c['challan_date'] ?? $c['date'] ?? null, isset($c['amount']) ? $this->money($c['amount']) : null,
                $c['offense_details'] ?? $c['offence'] ?? null, $c['challan_status'] ?? $c['status'] ?? null,
            ])->filter()->implode(' · '))->implode("\n")."\n\nSend another vehicle number, or *0* for the menu.");
    }

    // Free text: answered by the local Yogii model, which only chats and never sees the token.

    private function askYogii(string $question): void
    {
        $model = (string) AiSettings::current()->model;
        if (! str_starts_with($model, AiSettings::LOCAL_PREFIX)) {
            $this->menu('Pick one of the numbers.');

            return;
        }
        try {
            $reply = trim((string) Http::timeout(120)->connectTimeout(10)->post('http://coolify-ollama:11434/api/chat', [
                'model' => substr($model, strlen(AiSettings::LOCAL_PREFIX)),
                'stream' => false,
                'think' => false,
                'keep_alive' => -1,
                'options' => ['num_predict' => 250],
                'messages' => [
                    ['role' => 'system', 'content' => 'You are Yogii, the Mehar Advisory WhatsApp assistant for vehicle loan staff and partners. Answer briefly and practically. You cannot look up loans, payments or RCs yourself: for those, the user picks an option from the numbered menu. Never invent loan, payment or customer details.'],
                    ['role' => 'user', 'content' => mb_substr($question, 0, 1000)],
                ],
            ])->throw()->json('message.content'));
        } catch (\Throwable) {
            $reply = '';
        }
        $this->say(($reply !== '' ? $reply : 'I did not get that.')."\n\nSend *menu* for the options.");
    }

    // Files

    private function sendPdf(string $path, string $filename, string $caption): void
    {
        $response = $this->authed(fn (PendingRequest $request) => $request->accept('*/*'))?->get($path);
        if (! $response) {
            return;
        }
        $this->replies[] = ['text' => $caption, 'file' => $response->body(), 'filename' => $filename, 'mimetype' => $response->header('Content-Type') ?: 'application/pdf'];
    }

    // Helpers

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) $this->apiUrl)->acceptJson()->timeout(90)->connectTimeout(10);
    }

    /** A request with the user's token. Null (with a reply already said) when the backend refused it. */
    private function authed(?\Closure $tap = null): ?object
    {
        $request = $this->api()->withToken(Crypt::decryptString($this->session['token']));
        if ($tap) {
            $request = $tap($request);
        }

        return new class($request, $this)
        {
            public function __construct(private PendingRequest $request, private MeharBot $bot) {}

            public function __call(string $method, array $arguments): ?Response
            {
                return $this->bot->checked($this->request->{$method}(...$arguments));
            }
        };
    }

    /** @internal Used by authed(): turns backend errors into a reply. */
    public function checked(Response $response): ?Response
    {
        if ($response->status() === 401) {
            $this->forget('Your login has expired. Send *login* to log in again.');

            return null;
        }
        if (! $response->successful()) {
            $this->say(($response->status() === 403 ? "You don't have access to this." : 'That did not work: '.$this->error($response))."\n\n*0* Back");

            return null;
        }

        return $response;
    }

    private function error(Response $response): string
    {
        return Str::limit((string) ($response->json('error') ?? $response->json('message') ?? 'error '.$response->status()), 200);
    }

    private function phone(): string
    {
        return substr(preg_replace('/\D/', '', $this->number), -10);
    }

    private function money(mixed $amount): string
    {
        // Indian grouping: 5,00,000.
        $digits = (string) round(abs((float) $amount));
        $head = substr($digits, 0, -3);

        return '₹'.((float) $amount < 0 ? '-' : '').($head !== '' ? preg_replace('/\B(?=(\d{2})+$)/', ',', $head).',' : '').substr($digits, -3);
    }

    /** @param  array<string, mixed>  $fields */
    private function lines(array $fields): string
    {
        return collect($fields)->filter(fn ($value) => filled($value))->map(fn ($value, string $label) => "*{$label}:* {$value}")->implode("\n");
    }

    private function say(string $text): void
    {
        $this->replies[] = $text;
    }

    private function forget(string $reply): void
    {
        $this->session = [];
        Cache::forget(self::key($this->number));
        $this->say($reply);
    }

    private function save(): void
    {
        if ($this->session !== []) {
            Cache::put(self::key($this->number), $this->session, now()->addHours(self::SESSION_HOURS));
        }
    }
}
