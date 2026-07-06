<?php

declare(strict_types=1);

namespace Sempia\ExternalAssets;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

/**
 * Composer plugin to download external assets for PHP projects.
 *
 * This plugin handles the "extra.external-assets" configuration in composer.json,
 * downloading external files (JS, CSS, etc.) during package installation.
 *
 * Format:
 * "extra": {
 *     "external-assets": {
 *         "asset/vendor/lib/file.min.js": "https://example.com/v3.4.0/file.min.js",
 *         "asset/vendor/lib/": "https://example.com/v3.4.1/archive.zip",
 *         "asset/vendor/scripts/": "https://example.com/script.js"
 *     }
 * }
 *
 * - If destination ends with a filename, download url and rename to that name.
 * - If destination ends with `/` and url has .zip/.tar.gz/.tgz, extract it.
 *   Note: if the archive contains a single root directory, it is stripped.
 * - If destination ends with `/` and url is a file, copy it into that directory.
 */
class ExternalAssetsPlugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    protected $composer;

    /** @var IOInterface */
    protected $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    /**
     * Handle post-install for packages with external-assets.
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $package = $event->getOperation()->getPackage();
        $this->handleExternalAssets($package);
    }

    /**
     * Handle post-update for packages with external-assets.
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
        $package = $event->getOperation()->getTargetPackage();
        $this->handleExternalAssets($package);
    }

    /**
     * After install/update, download any missing assets for all packages.
     *
     * This covers two cases not handled by per-package events:
     * - Root package assets (root never fires POST_PACKAGE_INSTALL).
     * - Assets deleted after initial install (no package event fires).
     */
    public function onPostInstallOrUpdate(ScriptEvent $event)
    {
        // Process root package.
        $rootPackage = $this->composer->getPackage();
        $rootExtra = $rootPackage->getExtra();
        if (!empty($rootExtra['external-assets']) && is_array($rootExtra['external-assets'])) {
            $rootDir = getcwd();
            $this->downloadMissingAssets($rootExtra['external-assets'], $rootDir, $rootPackage->getPrettyName());
        }

        // Process all installed packages.
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($repo->getPackages() as $package) {
            $extra = $package->getExtra();
            if (empty($extra['external-assets']) || !is_array($extra['external-assets'])) {
                continue;
            }
            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            $this->downloadMissingAssets($extra['external-assets'], $installPath, $package->getPrettyName());
        }
    }

    /**
     * Download and install assets defined in extra.external-assets.
     */
    protected function handleExternalAssets($package)
    {
        $extra = $package->getExtra();
        if (empty($extra['external-assets']) || !is_array($extra['external-assets'])) {
            return;
        }

        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        $this->downloadMissingAssets($extra['external-assets'], $installPath, $package->getPrettyName());
    }

    /**
     * Download assets that are missing from the filesystem.
     */
    protected function downloadMissingAssets(array $assets, string $basePath, string $packageName): void
    {
        $manifestPath = $basePath . '/vendor/external-assets.lock.json';
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];
        $filesystem = new Filesystem();

        foreach ($assets as $destination => $url) {
            $destPath = $basePath . '/' . ltrim($destination, '/');
            $isDirectory = substr($destination, -1) === '/';
            $exists = $isDirectory
                ? (is_dir($destPath) && count(array_diff(
                    scandir($destPath),
                    ['.', '..', '.htaccess', '.gitkeep', '.gitignore', 'index.html']
                )) > 0)
                : file_exists($destPath);
            $urlChanged = ($manifest[$destination] ?? null) !== $url;

            if ($exists && !$urlChanged) {
                continue;
            }

            if ($exists) {
                if ($isDirectory) {
                    foreach (array_diff(scandir($destPath), ['.', '..', '.htaccess', '.gitkeep', '.gitignore', 'index.html']) as $entry) {
                        $path = $destPath . '/' . $entry;
                        is_dir($path) ? $filesystem->removeDirectory($path) : $filesystem->unlink($path);
                    }
                } else {
                    $filesystem->unlink($destPath);
                }
            }

            $isArchive = preg_match('/\.(zip|tar\.gz|tgz)$/i', $url);

            // Fetch the url with a native request to avoid the full stack trace
            // displayed by composer when an asset is missing.
            // Because the check does not share composer config (proxy/auth/CA
            // set only in composer.json, not in env), an non-404/403/410 error
            // go back to normal composer download.
            $status = $this->remoteStatus($url);
            if (in_array($status, [403, 404, 410], true)) {
                $this->io->writeError(sprintf(
                    '<error>External asset %s could not be downloaded for %s (HTTP %d): %s</error>',
                    basename($url),
                    $packageName,
                    $status,
                    $url
                ));
                continue;
            }

            $this->io->write(sprintf(
                '<info>Downloading asset %s for %s...</info>',
                basename($url),
                $packageName
            ));

            try {
                if ($isDirectory && $isArchive) {
                    $this->downloadAndExtract($url, $destPath);
                } elseif ($isDirectory) {
                    $this->downloadFile($url, $destPath . basename($url));
                } else {
                    $this->downloadFile($url, $destPath);
                }
                $manifest[$destination] = $url;
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Failed to download asset %s for %s: %s</error>',
                    basename($url),
                    $packageName,
                    $e->getMessage()
                ));
            }
        }

        // Drop entries removed from composer.json.
        $manifest = array_intersect_key($manifest, $assets);
        $filesystem->ensureDirectoryExists(dirname($manifestPath));
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Get the HTTP status of a url without composer HttpDownloader.
     *
     * A native request is used so a failed lookup does not trigger composer
     * async "Unhandled promise rejection" output.
     *
     * @return int The status code, or 0 when the host is unreachable.
     */
    protected function remoteStatus(string $url): int
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'sempia/external-assets',
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $status;
        }

        $context = stream_context_create(['http' => [
            'method' => 'HEAD',
            'follow_location' => 1,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $headers = @get_headers($url, false, $context);
        if (!$headers) {
            return 0;
        }

        foreach (array_reverse($headers) as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Download a single file using composer HttpDownloader.
     */
    protected function downloadFile(string $url, string $destPath): void
    {
        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists(dirname($destPath));

        $httpDownloader = new HttpDownloader($this->io, $this->composer->getConfig());
        $httpDownloader->copy($url, $destPath);
    }

    /**
     * Download and extract an archive using composer utilities.
     *
     * If the archive contains a single root directory, its contents are
     * extracted directly to the destination (stripping the root directory).
     */
    protected function downloadAndExtract(string $url, string $destPath): void
    {
        $filesystem = new Filesystem();

        $tempFile = sys_get_temp_dir() . '/' . basename($url);
        $tempDir = sys_get_temp_dir() . '/external_extract_' . uniqid();

        $filesystem->ensureDirectoryExists($tempDir);

        $httpDownloader = new HttpDownloader($this->io, $this->composer->getConfig());
        $httpDownloader->copy($url, $tempFile);

        // Use composer archive extractor via process.
        $process = new ProcessExecutor($this->io);

        if (preg_match('/\.zip$/i', $url)) {
            // Try unzip command first, fallback to php ZipArchive.
            $command = sprintf('unzip -o -q %s -d %s 2>&1', escapeshellarg($tempFile), escapeshellarg($tempDir));
            if ($process->execute($command) !== 0) {
                // Fallback to ZipArchive if unzip is not available.
                if (!class_exists('ZipArchive')) {
                    $filesystem->unlink($tempFile);
                    $filesystem->removeDirectory($tempDir);
                    throw new \RuntimeException('Cannot extract zip: unzip command failed and ZipArchive not available');
                }
                $zip = new \ZipArchive();
                if ($zip->open($tempFile) !== true) {
                    $filesystem->unlink($tempFile);
                    $filesystem->removeDirectory($tempDir);
                    throw new \RuntimeException('Failed to open zip archive');
                }
                $zip->extractTo($tempDir);
                $zip->close();
            }
        } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $url)) {
            $command = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($tempFile), escapeshellarg($tempDir));
            if ($process->execute($command) !== 0) {
                // Fallback to PharData.
                $phar = new \PharData($tempFile);
                $phar->extractTo($tempDir);
            }
        }

        $filesystem->unlink($tempFile);

        // Check if archive has a single root directory and strip it.
        $sourceDir = $this->getArchiveSourceDir($tempDir);

        // Move contents to destination.
        $filesystem->ensureDirectoryExists($destPath);
        $this->moveDirectoryContents($sourceDir, $destPath, $filesystem);

        // Cleanup temp directory.
        $filesystem->removeDirectory($tempDir);
    }

    /**
     * Get the source directory for extraction.
     *
     * If the extracted archive contains a single root directory, return that
     * directory path (to strip the root). Otherwise return the temp directory.
     */
    protected function getArchiveSourceDir(string $tempDir): string
    {
        $entries = array_diff(scandir($tempDir), ['.', '..']);

        // If single entry and it's a directory, use it as source (strip root).
        if (count($entries) === 1) {
            $entry = reset($entries);
            $entryPath = $tempDir . '/' . $entry;
            if (is_dir($entryPath)) {
                return $entryPath;
            }
        }

        return $tempDir;
    }

    /**
     * Move contents from source directory to destination.
     */
    protected function moveDirectoryContents(string $source, string $dest, Filesystem $filesystem): void
    {
        $entries = array_diff(scandir($source), ['.', '..']);

        foreach ($entries as $entry) {
            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                $filesystem->ensureDirectoryExists($dstPath);
                $this->moveDirectoryContents($srcPath, $dstPath, $filesystem);
                @rmdir($srcPath);
            } else {
                // Remove existing file if any.
                if (file_exists($dstPath)) {
                    $filesystem->unlink($dstPath);
                }
                rename($srcPath, $dstPath);
            }
        }
    }
}
