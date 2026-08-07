<?php
declare(strict_types=1);

namespace Bilbofox\Deploy;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\RequestOptions;
use League\Flysystem\FilesystemOperator;
use ScriptFUSION\Byte\Base;
use ScriptFUSION\Byte\ByteFormatter;
use Closure;

final class Deploy
{
    private Closure $output;

    public bool $deletePackLocally = true;

    public bool $deletePackOnRemote = true;

    public function __construct(
        private readonly string $deployUrl,
        private readonly string $remoteDeployTarget,
        private readonly string $remotePacksDir,
        private readonly FilesystemOperator $storage,
        private readonly PackBuilder $packBuilder,
    )
    {

    }

    public function setOutput(callable $output): self
    {
        $this->output = $output;
        return $this;
    }

    public function run(): void
    {
        $output = $this->output ?? function () {
        };

        // -------------------------------------------------------------------
        // PACK

        $output('1. Creating pack of project files...');

        $packFilepath = $this->packBuilder->buildPack();
        $packFilename = pathinfo($packFilepath, PATHINFO_BASENAME);
        $packFilesize = (new ByteFormatter)->setBase(Base::DECIMAL)->format(filesize($packFilepath));

        $output(sprintf('--- pack of size %s successfully created!', $packFilesize));

        // -------------------------------------------------------------------
        // UPLOAD

        $output(sprintf('2. Uploading pack to remote storage dir "%s"...', $this->remotePacksDir));

        $packStoragepath = $this->remotePacksDir . '/' . $packFilename;
        $f = fopen($packFilepath, 'rb');
        $this->storage->writeStream($packStoragepath, $f);
        is_resource($f) && fclose($f);

        $output('--- upload finished!');

        if ($this->deletePackLocally) {
            unlink($packFilepath);
            $output('--- pack file deleted locally!');
        }

        // -------------------------------------------------------------------
        // REMOTE HOOK

        $output('3. Running hook - deploy script on server...');

        $client = new GuzzleHttpClient();
        $response = $client->post($this->deployUrl, [
            RequestOptions::JSON => [
                'pack' => $packFilename,
                'target' => $this->remoteDeployTarget,
            ],
            RequestOptions::VERIFY => true,
        ]);

        if ($this->deletePackOnRemote) {
            $this->storage->delete($packStoragepath);
            $output('--- pack file deleted on remote storage!');
        }
    }
}