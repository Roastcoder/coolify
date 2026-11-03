{{-- Fork-only: StandaloneCoders branded email shell. Inline styles and tables, since mail apps ignore stylesheets. --}}
@php
    $brandName = data_get(instanceSettings(), 'smtp_from_name') ?: 'StandaloneCoders';
    $baseUrl = rtrim(base_url(), '/');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $brandName }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                    <tr>
                        <td style="padding:0 4px 20px;">
                            <a href="{{ $baseUrl }}" style="text-decoration:none;color:#0f172a;">
                                <img src="{{ $baseUrl }}/standalonecoders-apple-touch-icon.png" width="36" height="36" alt=""
                                    style="vertical-align:middle;border:0;border-radius:8px;">
                                <span style="vertical-align:middle;margin-left:10px;font-size:18px;font-weight:700;letter-spacing:-0.01em;">{{ $brandName }}</span>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #e2e8f0;border-top:4px solid #22c55e;border-radius:12px;padding:32px;font-size:15px;line-height:1.6;">
                            <x-emails.header />
                            {{ Illuminate\Mail\Markdown::parse($slot) }}
                            <x-emails.footer />
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 4px 0;font-size:12px;line-height:1.5;color:#64748b;text-align:center;">
                            Sent by {{ $brandName }} from <a href="{{ $baseUrl }}" style="color:#15803d;">{{ preg_replace('#^https?://#', '', $baseUrl) }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
