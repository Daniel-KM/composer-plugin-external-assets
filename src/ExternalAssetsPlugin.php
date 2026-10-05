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
use Composer\Util\HttpDownloader;

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
 *         "asset/vendor/scripts/": "https://example.com/script.js",
 *         "asset/vendor/other/": {
 *             "url": "https://example.com/archive.zip",
 *             "exclude": ["index.html", "docs", "*.map"]
 *         }
 *     }
 * }
 *
 * - If destination ends with a filename, download url and rename to that name.
 * - If destination ends with `/` and url has .zip/.tar.gz/.tgz, extract it.
 *   Note: if the archive contains a single root directory, it is stripped.
 * - If destination ends with `/` and url is a file, copy it into that directory.
 * - A value may be an object with the keys `url` and `exclude`. Each exclude is
 *   a path relative to the destination directory, with an optional glob, that
 *   is removed after the download: an archive often ships a demo page or maps
 *   that should not be published. Excludes are applied even when the assets are
 *   already downloaded, so adding one is enough to remove the file.
 */
class ExternalAssetsPlugin extends AssetInstaller implements PluginInterface, EventSubscriberInterface
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
            $this->installAssets($rootExtra['external-assets'], $rootDir, $rootPackage->getPrettyName());
        }

        // Process all installed packages.
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($repo->getPackages() as $package) {
            $extra = $package->getExtra();
            if (empty($extra['external-assets']) || !is_array($extra['external-assets'])) {
                continue;
            }
            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            $this->installAssets($extra['external-assets'], $installPath, $package->getPrettyName());
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
        $this->installAssets($extra['external-assets'], $installPath, $package->getPrettyName());
    }

    /**
     * Download with the http downloader of composer, so the proxy, the
     * authentication and the certificates of its config are used.
     */
    protected function fetch(string $url, string $destFile): void
    {
        $httpDownloader = new HttpDownloader($this->io, $this->composer->getConfig());
        $httpDownloader->copy($url, $destFile);
    }

    protected function log(string $level, string $message): void
    {
        if (!$this->io) {
            return;
        }
        $message = "<$level>" . $message . "</$level>";
        $level === 'info'
            ? $this->io->write($message)
            : $this->io->writeError($message);
    }
}
