<?php

declare(strict_types=1);

namespace Sempia\ExternalAssets\Test;

use Sempia\ExternalAssets\AssetInstaller;

/**
 * Testable installer: only the transport and the output are replaced.
 *
 * Everything else is the code used in production, so the tests exercise the
 * real decisions, instead of a copy that can drift from them.
 */
class TestableAssetInstaller extends AssetInstaller
{
    /** @var array Map of url => content, used instead of a download. */
    protected array $urlContentMap;

    /** @var array Map of url => status, to simulate a missing asset. */
    protected array $urlStatusMap;

    /** @var string[] The logged messages, as "level: message". */
    public array $messages = [];

    public function __construct(array $urlContentMap, array $urlStatusMap = [])
    {
        $this->urlContentMap = $urlContentMap;
        $this->urlStatusMap = $urlStatusMap;
    }

    /**
     * Install the assets of a package, as the composer plugin does.
     */
    public function testHandleExternalAssets(object $package, string $installPath): bool
    {
        $extra = $package->getExtra();
        if (empty($extra['external-assets']) || !is_array($extra['external-assets'])) {
            return true;
        }

        return $this->installAssets($extra['external-assets'], $installPath, $package->getPrettyName());
    }

    public function testGetArchiveSourceDir(string $tempDir): string
    {
        return $this->getArchiveSourceDir($tempDir);
    }

    public function testMoveDirectoryContents(string $source, string $dest): void
    {
        $this->moveDirectoryContents($source, $dest);
    }

    public function testClearDirectory(string $destPath): void
    {
        $this->clearDirectory($destPath);
    }

    protected function fetch(string $url, string $destFile): void
    {
        if (!isset($this->urlContentMap[$url])) {
            throw new \RuntimeException("Mock download failed: URL not in map: $url");
        }
        file_put_contents($destFile, $this->urlContentMap[$url]);
    }

    /**
     * Never hit the network: an url without a declared status is reachable,
     * so the download decides, as it does for any error but a missing asset.
     */
    protected function remoteStatus(string $url): int
    {
        return $this->urlStatusMap[$url] ?? 200;
    }

    protected function log(string $level, string $message): void
    {
        $this->messages[] = $level . ': ' . $message;
    }
}
