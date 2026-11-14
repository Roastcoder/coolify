<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Fork-only: the container file service, but for the server's own file system (the code editor's
 * server workspaces). Every listing, read and write command is the same, only not run in a container.
 */
class HostFilesystemService extends ContainerFilesystemService
{
    public function __construct(private Server $host, private string $root = '/')
    {
        parent::__construct($host, 'host');
    }

    protected function dockerExecShell(string $innerShell): string
    {
        return 'sh -c '.escapeshellarg($innerShell);
    }

    public function defaultRoot(): string
    {
        return $this->root;
    }

    public function upload(string $localTmpPath, string $destPath): void
    {
        validateShellSafePath($destPath, 'upload path');
        $serverTmp = '/tmp/sc-upload-'.Str::random(16);
        instant_scp($localTmpPath, $serverTmp, $this->host);
        instant_remote_process(['cat '.escapeshellarg($serverTmp).' > '.escapeshellarg($destPath).'; rm -f '.escapeshellarg($serverTmp)], $this->host);
    }
}
