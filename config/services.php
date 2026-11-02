<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI'),
        'tenant' => env('AZURE_TENANT_ID'),
        'proxy' => env('AZURE_PROXY'),
    ],

    'authentik' => [
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI'),
    ],

    'clerk' => [
        'client_id' => env('CLERK_CLIENT_ID'),
        'client_secret' => env('CLERK_CLIENT_SECRET'),
        'redirect' => env('CLERK_REDIRECT_URI'),
        'base_url' => env('CLERK_BASE_URL'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'tenant' => env('GOOGLE_TENANT'),
    ],

    'oidc' => [
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('OIDC_REDIRECT_URI'),
        'base_url' => env('OIDC_BASE_URL'),
        'custom_label' => env('OIDC_LOGIN_LABEL'),
    ],

    'zitadel' => [
        'client_id' => env('ZITADEL_CLIENT_ID'),
        'client_secret' => env('ZITADEL_CLIENT_SECRET'),
        'redirect' => env('ZITADEL_REDIRECT_URI'),
        'base_url' => env('ZITADEL_BASE_URL'),
    ],

    /*
    | Domain Connect (Cloudflare automatic DNS).
    | Template: resources/domain-connect/coolify.io.hosting.json
    | Publish public key TXT at {key_id}.{syncPubKeyDomain} and onboard with Cloudflare.
    | @see https://developers.cloudflare.com/dns/reference/domain-connect/
    */
    'domain_connect' => [
        'provider_id' => env('DOMAIN_CONNECT_PROVIDER_ID', 'coolify.io'),
        'service_id' => env('DOMAIN_CONNECT_SERVICE_ID', 'hosting'),
        'key_id' => env('DOMAIN_CONNECT_KEY_ID', '_dcpubkeyv1'),
        'private_key' => env('DOMAIN_CONNECT_PRIVATE_KEY'),
    ],

    /*
    | Fork-only: Zectagon SMS gateway for the login OTP. SMS login codes are off
    | until SMS_USER and SMS_PASSWORD are set.
    */
    'sms' => [
        'base_url' => env('SMS_BASE_URL', 'http://sms.zectagon.cloud'),
        'user' => env('SMS_USER'),
        'password' => env('SMS_PASSWORD'),
        'sender_id' => env('SMS_SENDER_ID'),
        'entity_id' => env('SMS_ENTITY_ID'),
        'otp_template_id' => env('SMS_TEMPLATE_ID_SIGNUP_OTP'),
        // Must match the DLT-registered template word for word.
        'otp_message' => env('SMS_OTP_MESSAGE', 'Dear Customer, your OTP for Mehar Finance signup is :code. Do not share it with anyone. - Mehar Advisory'),
    ],

];
