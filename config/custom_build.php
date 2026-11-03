<?php

/*
 * Fork-only: identifies which build of this fork the running image is, so the
 * "Update" button can compare it with the newest successful CI build.
 * .github/workflows/custom-build.yml rewrites `sha` before building the image.
 */

return [
    'product_name' => env('PRODUCT_NAME', 'StandaloneCoders Cloud'),
    // Shown on the public home page for plan enquiries.
    'contact_email' => env('CONTACT_EMAIL', 'hello@standalonecoders.com'),
    'repository' => env('CUSTOM_BUILD_REPOSITORY', 'Roastcoder/standalonecoders-cloud'),
    'workflow' => env('CUSTOM_BUILD_WORKFLOW', 'custom-build.yml'),
    'branch' => env('CUSTOM_BUILD_BRANCH', 'main'),
    'token' => env('CUSTOM_BUILD_TOKEN', env('GITHUB_TOKEN')),
    'sha' => null,
    'update_command' => 'nohup setsid flock -n /tmp/coolify-custom-update.lock bash /data/coolify/source/update-custom.sh >> /data/coolify/source/update-custom.log 2>&1 < /dev/null &',
];
