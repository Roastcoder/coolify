<?php

/*
 * Fork-only: identifies which build of this fork the running image is, so the
 * "Update" button can compare it with the newest successful CI build.
 * .github/workflows/custom-build.yml rewrites `sha` before building the image.
 */

return [
    'repository' => env('CUSTOM_BUILD_REPOSITORY', 'Roastcoder/coolify'),
    'workflow' => env('CUSTOM_BUILD_WORKFLOW', 'custom-build.yml'),
    'branch' => env('CUSTOM_BUILD_BRANCH', 'main'),
    'sha' => null,
    'update_command' => 'nohup setsid flock -n /tmp/coolify-custom-update.lock bash /data/coolify/source/update-custom.sh >> /data/coolify/source/update-custom.log 2>&1 < /dev/null &',
];
