<?php declare(strict_types=1);

namespace Sempia\ExternalAssets;

/**
 * Install the assets declared in "extra.external-assets" of a composer.json.
 *
 * The class holds the whole logic and uses no composer class, so it can be used
 * by the composer plugin as well as by the standalone command, that may run
 * without composer. A subclass only provides the transport and the output.
 */
abstract class AssetInstaller
{
    /**
     * Files kept in a destination directory.
     *
     * They belong to the package, not to the asset, so they don't make the
     * directory a filled one and they are not removed when the assets are
     * replaced.
     */
    const KEPT_FILES = ['.', '..', '.htaccess', '.gitkeep', '.gitignore', 'index.html'];

    /**
     * Statuses meaning that the asset is missing for sure, so the download is
     * useless. Any other error goes to the download, that reports it.
     */
    const MISSING_STATUSES = [403, 404, 410];

    /**
     * Download a url into a local file.
     *
     * @throws \Exception When the url cannot be downloaded.
     */
    abstract protected function fetch(string $url, string $destFile): void;

    /**
     * Output a message, with level "info", "warning" or "error".
     */
    abstract protected function log(string $level, string $message): void;

    /**
     * Install the assets of a package.
     *
     * @param array $assets The content of "extra.external-assets".
     * @param string $basePath The root of the package.
     * @param string $packageName Used in the messages.
     * @param bool $force Download again even when the assets are up-to-date.
     * @return bool False when at least one asset failed.
     */
    public function installAssets(array $assets, string $basePath, string $packageName, bool $force = false): bool
    {
        $manifestPath = $basePath . '/vendor/external-assets.lock.json';
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];

        $success = true;

        foreach ($assets as $destination => $asset) {
            $asset = $this->normalizeAsset($asset);
            $url = $asset['url'];
            if ($url === '') {
                $this->log('error', sprintf(
                    'External asset "%s" of %s is skipped: the url is missing.',
                    $destination,
                    $packageName
                ));
                $success = false;
                continue;
            }

            $destPath = $basePath . '/' . ltrim((string) $destination, '/');
            $isDirectory = substr((string) $destination, -1) === '/';
            $exists = $isDirectory
                ? (is_dir($destPath) && count(array_diff(scandir($destPath), self::KEPT_FILES)) > 0)
                : file_exists($destPath);

            if (!$force && $exists && ($manifest[$destination] ?? null) === $url) {
                // Excludes are applied even when the assets are already there,
                // so adding one to composer.json is enough to remove the file,
                // without downloading the whole archive again.
                $this->applyExcludes($destPath, $asset['exclude'], $packageName);
                continue;
            }

            // Check the url before downloading, only to report a missing asset
            // without the error of the transport. The assets in place are kept
            // whatever the error anyway, since they are replaced only once the
            // new ones are downloaded.
            $status = $this->remoteStatus($url);
            if (in_array($status, self::MISSING_STATUSES, true)) {
                $this->log('error', sprintf(
                    'External asset %s could not be downloaded for %s (HTTP %d): %s',
                    basename($url),
                    $packageName,
                    $status,
                    $url
                ));
                $success = false;
                continue;
            }

            $this->log('info', sprintf('Downloading asset %s for %s...', basename($url), $packageName));

            // The assets in place are replaced only once the new ones are
            // downloaded, so a failed download cannot empty the destination.
            $clearPath = $isDirectory && is_dir($destPath) ? $destPath : null;

            try {
                if ($isDirectory && preg_match('/\.(zip|tar\.gz|tgz)$/i', $url)) {
                    $this->downloadAndExtract($url, $destPath, $clearPath);
                } elseif ($isDirectory) {
                    $this->downloadFile($url, $destPath . basename($url), $clearPath);
                } else {
                    $this->downloadFile($url, $destPath);
                }
                $this->applyExcludes($destPath, $asset['exclude'], $packageName);
                $manifest[$destination] = $url;
            } catch (\Exception $e) {
                $this->log('error', sprintf(
                    'Failed to download asset %s for %s: %s',
                    basename($url),
                    $packageName,
                    $e->getMessage()
                ));
                $success = false;
            }
        }

        // Drop entries removed from composer.json.
        $manifest = array_intersect_key($manifest, $assets);
        if (!is_dir(dirname($manifestPath))) {
            mkdir(dirname($manifestPath), 0755, true);
        }
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $success;
    }

    /**
     * Normalize an asset of "extra.external-assets" to an array.
     *
     * A value is either the url as a string, or an object with the keys `url`
     * and `exclude`. An invalid value returns an empty url, so the caller can
     * report it instead of failing.
     *
     * @return array{url: string, exclude: string[]}
     */
    public function normalizeAsset($asset): array
    {
        if (is_string($asset)) {
            return ['url' => $asset, 'exclude' => []];
        }

        if (!is_array($asset) || !isset($asset['url']) || !is_string($asset['url'])) {
            return ['url' => '', 'exclude' => []];
        }

        $exclude = $asset['exclude'] ?? [];
        if (!is_array($exclude)) {
            $exclude = [$exclude];
        }
        $exclude = array_values(array_filter(
            array_map(function ($pattern) {
                return is_string($pattern) ? trim($pattern) : '';
            }, $exclude),
            'strlen'
        ));

        return ['url' => $asset['url'], 'exclude' => $exclude];
    }

    /**
     * Remove the excluded paths from a destination directory.
     *
     * A pattern is relative to the destination and may hold a glob. A pattern
     * escaping the destination (absolute or with "..") is skipped: an asset
     * declaration must never remove a file outside of its own directory.
     *
     * @return string[] The removed paths, relative to the destination.
     */
    public function applyExcludes(string $destPath, array $exclude, ?string $packageName = null): array
    {
        if (!$exclude || !is_dir($destPath)) {
            return [];
        }

        $base = realpath($destPath);
        if ($base === false) {
            return [];
        }

        $removed = [];
        foreach ($exclude as $pattern) {
            // Windows separators are normalized, so a single check is enough.
            $normalized = str_replace('\\', '/', $pattern);
            if ($pattern === ''
                || strpos($pattern, "\0") !== false
                || substr($normalized, 0, 1) === '/'
                || preg_match('~^[a-zA-Z]:~', $normalized)
                || preg_match('~(^|/)\.\.(/|$)~', $normalized)
            ) {
                $this->log('warning', sprintf(
                    'External asset exclude "%s"%s is skipped: it must be a path inside the destination.',
                    $pattern,
                    $packageName ? ' of ' . $packageName : ''
                ));
                continue;
            }

            foreach ((array) glob($base . '/' . $pattern, GLOB_NOSORT) as $path) {
                $real = realpath($path);
                // Double check: a symlink could point outside the destination.
                if ($real === false || strpos($real, $base . '/') !== 0) {
                    continue;
                }
                is_dir($real) && !is_link($real)
                    ? $this->removeDirectory($real)
                    : @unlink($real);
                $removed[] = substr($real, strlen($base) + 1);
            }
        }

        return $removed;
    }

    /**
     * Get the HTTP status of a url.
     *
     * The request does not share the config of composer (proxy, auth, CA set
     * only in composer.json and not in the environment), so it is only used to
     * detect an asset missing for sure, never to skip a download.
     *
     * @return int The status, or 0 when the host is unreachable. Redirections
     * are followed: the assets of a release are served through one.
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
            // curl_close() is useless here since 7.2, has no effect since php
            // 8.0, and is deprecated since php 8.5.
            return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * Download a single file, then put it in place.
     *
     * @param ?string $clearPath Directory emptied once the file is downloaded.
     */
    protected function downloadFile(string $url, string $destPath, ?string $clearPath = null): void
    {
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Download aside, so a failed or partial download doesn't truncate the
        // file in place.
        $tempFile = sys_get_temp_dir() . '/external_asset_' . uniqid();

        try {
            $this->fetch($url, $tempFile);

            if ($clearPath !== null) {
                $this->clearDirectory($clearPath);
            }
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
            rename($tempFile, $destPath);
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw $e;
        }
    }

    /**
     * Download an archive, extract it, then put its content in place.
     *
     * If the archive contains a single root directory, its content is extracted
     * directly to the destination, stripping the root directory.
     *
     * @param ?string $clearPath Directory emptied once the archive is extracted.
     */
    protected function downloadAndExtract(string $url, string $destPath, ?string $clearPath = null): void
    {
        $tempFile = sys_get_temp_dir() . '/external_asset_' . uniqid();
        $tempDir = sys_get_temp_dir() . '/external_extract_' . uniqid();

        mkdir($tempDir, 0755, true);

        try {
            $this->fetch($url, $tempFile);

            if (preg_match('/\.zip$/i', $url)) {
                $this->extractZip($tempFile, $tempDir);
            } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $url)) {
                $this->extractTarGz($tempFile, $tempDir);
            }

            @unlink($tempFile);

            // Check if the archive has a single root directory and strip it.
            $sourceDir = $this->getArchiveSourceDir($tempDir);

            // The archive is extracted, so the assets in place can be replaced.
            if ($clearPath !== null) {
                $this->clearDirectory($clearPath);
            }

            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
            $this->moveDirectoryContents($sourceDir, $destPath);

            $this->removeDirectory($tempDir);
        } catch (\Exception $e) {
            @unlink($tempFile);
            $this->removeDirectory($tempDir);
            throw $e;
        }
    }

    protected function extractZip(string $zipFile, string $destDir): void
    {
        $command = sprintf('unzip -o -q %s -d %s 2>&1', escapeshellarg($zipFile), escapeshellarg($destDir));
        exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            return;
        }

        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('Cannot extract zip: unzip command failed and ZipArchive not available');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Failed to open zip archive');
        }
        $zip->extractTo($destDir);
        $zip->close();
    }

    protected function extractTarGz(string $tarFile, string $destDir): void
    {
        $command = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($tarFile), escapeshellarg($destDir));
        exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            return;
        }

        $phar = new \PharData($tarFile);
        $phar->extractTo($destDir);
    }

    /**
     * Get the directory holding the content of an extracted archive.
     *
     * When the archive contains a single root directory, that directory is
     * returned, so the root is stripped.
     */
    protected function getArchiveSourceDir(string $tempDir): string
    {
        $entries = array_diff(scandir($tempDir), ['.', '..']);

        if (count($entries) === 1) {
            $entryPath = $tempDir . '/' . reset($entries);
            if (is_dir($entryPath)) {
                return $entryPath;
            }
        }

        return $tempDir;
    }

    /**
     * Move the content of a directory into another one.
     */
    protected function moveDirectoryContents(string $source, string $dest): void
    {
        foreach (array_diff(scandir($source), ['.', '..']) as $entry) {
            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                $this->moveDirectoryContents($srcPath, $dstPath);
                @rmdir($srcPath);
            } else {
                if (file_exists($dstPath)) {
                    @unlink($dstPath);
                }
                rename($srcPath, $dstPath);
            }
        }
    }

    /**
     * Remove the content of a directory, except the files of the package.
     */
    protected function clearDirectory(string $destPath): void
    {
        if (!is_dir($destPath)) {
            return;
        }
        foreach (array_diff(scandir($destPath), self::KEPT_FILES) as $entry) {
            $path = $destPath . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
    }

    /**
     * Remove a directory and its content.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
