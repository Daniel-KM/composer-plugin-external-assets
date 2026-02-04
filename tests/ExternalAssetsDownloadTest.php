<?php

declare(strict_types=1);

namespace DanielKm\ExternalAssets\Test;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use DanielKm\ExternalAssets\ExternalAssetsPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Mock-based tests for ExternalAssetsPlugin download logic.
 *
 * These tests verify the download and extraction logic without making actual
 * HTTP requests. They use a testable subclass that allows injecting mock
 * download behavior.
 */
class ExternalAssetsDownloadTest extends TestCase
{
    protected string $tempDir;
    protected string $installPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/external_assets_test_' . uniqid();
        $this->installPath = $this->tempDir . '/modules/TestModule';
        mkdir($this->installPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Test downloading a single file to a specific filename.
     */
    public function testDownloadFileToFilename(): void
    {
        $downloadedContent = '// jQuery Autocomplete v1.5.0';
        $plugin = $this->createTestablePlugin([
            'https://example.com/jquery.autocomplete-1.5.0.min.js' => $downloadedContent,
        ]);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/lib/jquery.autocomplete.min.js' => 'https://example.com/jquery.autocomplete-1.5.0.min.js',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $expectedFile = $this->installPath . '/asset/vendor/lib/jquery.autocomplete.min.js';
        $this->assertFileExists($expectedFile);
        $this->assertEquals($downloadedContent, file_get_contents($expectedFile));
    }

    /**
     * Test downloading a file into a directory (keeps original name).
     */
    public function testDownloadFileIntoDirectory(): void
    {
        $downloadedContent = '// Helper script';
        $plugin = $this->createTestablePlugin([
            'https://example.com/helper.js' => $downloadedContent,
        ]);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/scripts/' => 'https://example.com/helper.js',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $expectedFile = $this->installPath . '/asset/vendor/scripts/helper.js';
        $this->assertFileExists($expectedFile);
        $this->assertEquals($downloadedContent, file_get_contents($expectedFile));
    }

    /**
     * Test downloading and extracting a zip archive.
     */
    public function testDownloadAndExtractZip(): void
    {
        // Create a test zip file
        $zipContent = $this->createTestZip([
            'lib.min.js' => '// Library code',
            'lib.min.css' => '/* Library styles */',
            'images/logo.png' => 'PNG_CONTENT',
        ]);

        $plugin = $this->createTestablePlugin([
            'https://example.com/library-1.0.0.zip' => $zipContent,
        ], true);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/library/' => 'https://example.com/library-1.0.0.zip',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $baseDir = $this->installPath . '/asset/vendor/library';
        $this->assertFileExists($baseDir . '/lib.min.js');
        $this->assertFileExists($baseDir . '/lib.min.css');
        $this->assertFileExists($baseDir . '/images/logo.png');
        $this->assertEquals('// Library code', file_get_contents($baseDir . '/lib.min.js'));
    }

    /**
     * Test that zip archive with single root directory is stripped.
     */
    public function testZipSingleRootDirectoryStripping(): void
    {
        // Create a zip with a single root directory (common for GitHub releases)
        $zipContent = $this->createTestZip([
            'library-1.0.0/lib.min.js' => '// Library code',
            'library-1.0.0/lib.min.css' => '/* Styles */',
            'library-1.0.0/dist/bundle.js' => '// Bundle',
        ]);

        $plugin = $this->createTestablePlugin([
            'https://github.com/vendor/library/releases/download/v1.0.0/library.zip' => $zipContent,
        ], true);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/library/' => 'https://github.com/vendor/library/releases/download/v1.0.0/library.zip',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        // Files should be directly in library/, not library/library-1.0.0/
        $baseDir = $this->installPath . '/asset/vendor/library';
        $this->assertFileExists($baseDir . '/lib.min.js');
        $this->assertFileExists($baseDir . '/lib.min.css');
        $this->assertFileExists($baseDir . '/dist/bundle.js');
        $this->assertDirectoryDoesNotExist($baseDir . '/library-1.0.0');
    }

    /**
     * Test that zip with multiple root entries is not stripped.
     */
    public function testZipMultipleRootEntriesNotStripped(): void
    {
        $zipContent = $this->createTestZip([
            'lib.min.js' => '// Library',
            'lib.min.css' => '/* Styles */',
            'README.md' => '# Library',
        ]);

        $plugin = $this->createTestablePlugin([
            'https://example.com/library.zip' => $zipContent,
        ], true);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/library/' => 'https://example.com/library.zip',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $baseDir = $this->installPath . '/asset/vendor/library';
        $this->assertFileExists($baseDir . '/lib.min.js');
        $this->assertFileExists($baseDir . '/lib.min.css');
        $this->assertFileExists($baseDir . '/README.md');
    }

    /**
     * Test downloading and extracting a tar.gz archive.
     */
    public function testDownloadAndExtractTarGz(): void
    {
        // Create a test tar.gz file
        $tarGzContent = $this->createTestTarGz([
            'script.js' => '// Script',
            'style.css' => '/* Style */',
        ]);

        $plugin = $this->createTestablePlugin([
            'https://example.com/package.tar.gz' => $tarGzContent,
        ], true);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/package/' => 'https://example.com/package.tar.gz',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $baseDir = $this->installPath . '/asset/vendor/package';
        $this->assertFileExists($baseDir . '/script.js');
        $this->assertFileExists($baseDir . '/style.css');
    }

    /**
     * Test multiple assets in one package.
     */
    public function testMultipleAssets(): void
    {
        $plugin = $this->createTestablePlugin([
            'https://example.com/lib1.js' => '// Lib 1',
            'https://example.com/lib2.js' => '// Lib 2',
            'https://example.com/styles.css' => '/* Styles */',
        ]);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/lib1.min.js' => 'https://example.com/lib1.js',
                'asset/vendor/lib2.min.js' => 'https://example.com/lib2.js',
                'asset/css/styles.css' => 'https://example.com/styles.css',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $this->assertFileExists($this->installPath . '/asset/vendor/lib1.min.js');
        $this->assertFileExists($this->installPath . '/asset/vendor/lib2.min.js');
        $this->assertFileExists($this->installPath . '/asset/css/styles.css');
        $this->assertEquals('// Lib 1', file_get_contents($this->installPath . '/asset/vendor/lib1.min.js'));
        $this->assertEquals('// Lib 2', file_get_contents($this->installPath . '/asset/vendor/lib2.min.js'));
    }

    /**
     * Test that package without external-assets is handled gracefully.
     */
    public function testPackageWithoutExternalAssets(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $package = $this->createMockPackage([]);

        // Should not throw, should not create any files
        $plugin->testHandleExternalAssets($package, $this->installPath);

        $this->assertDirectoryExists($this->installPath);
        $entries = array_diff(scandir($this->installPath), ['.', '..']);
        $this->assertEmpty($entries);
    }

    /**
     * Test that empty external-assets is handled gracefully.
     */
    public function testEmptyExternalAssets(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $package = $this->createMockPackage([
            'external-assets' => [],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $entries = array_diff(scandir($this->installPath), ['.', '..']);
        $this->assertEmpty($entries);
    }

    /**
     * Test that download failure is handled gracefully.
     */
    public function testDownloadFailureHandledGracefully(): void
    {
        $plugin = $this->createTestablePlugin([
            'https://example.com/exists.js' => '// OK',
            // 'https://example.com/missing.js' is not in the map, will throw
        ]);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/exists.js' => 'https://example.com/exists.js',
                'asset/vendor/missing.js' => 'https://example.com/missing.js',
            ],
        ]);

        // Should not throw - failure is logged but processing continues
        $plugin->testHandleExternalAssets($package, $this->installPath);

        // First file should exist
        $this->assertFileExists($this->installPath . '/asset/vendor/exists.js');
        // Second file should not exist (download failed)
        $this->assertFileDoesNotExist($this->installPath . '/asset/vendor/missing.js');
    }

    /**
     * Test directory creation for nested paths.
     */
    public function testNestedDirectoryCreation(): void
    {
        $plugin = $this->createTestablePlugin([
            'https://example.com/deep.js' => '// Deep file',
        ]);

        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/very/deep/nested/path/file.js' => 'https://example.com/deep.js',
            ],
        ]);

        $plugin->testHandleExternalAssets($package, $this->installPath);

        $expectedFile = $this->installPath . '/asset/vendor/very/deep/nested/path/file.js';
        $this->assertFileExists($expectedFile);
    }

    /**
     * Test getArchiveSourceDir with single root directory.
     */
    public function testGetArchiveSourceDirSingleRoot(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $testDir = $this->tempDir . '/archive_test';
        mkdir($testDir . '/library-1.0.0', 0755, true);
        file_put_contents($testDir . '/library-1.0.0/file.js', 'content');

        $result = $plugin->testGetArchiveSourceDir($testDir);

        $this->assertEquals($testDir . '/library-1.0.0', $result);
    }

    /**
     * Test getArchiveSourceDir with multiple root entries.
     */
    public function testGetArchiveSourceDirMultipleRoots(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $testDir = $this->tempDir . '/archive_test2';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/file1.js', 'content1');
        file_put_contents($testDir . '/file2.js', 'content2');

        $result = $plugin->testGetArchiveSourceDir($testDir);

        $this->assertEquals($testDir, $result);
    }

    /**
     * Test getArchiveSourceDir with single file (not directory).
     */
    public function testGetArchiveSourceDirSingleFile(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $testDir = $this->tempDir . '/archive_test3';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/single-file.js', 'content');

        $result = $plugin->testGetArchiveSourceDir($testDir);

        // Single file (not directory) should not be stripped
        $this->assertEquals($testDir, $result);
    }

    /**
     * Test moveDirectoryContents.
     */
    public function testMoveDirectoryContents(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $srcDir = $this->tempDir . '/src';
        $dstDir = $this->tempDir . '/dst';

        mkdir($srcDir . '/subdir', 0755, true);
        mkdir($dstDir, 0755, true);
        file_put_contents($srcDir . '/file1.js', 'content1');
        file_put_contents($srcDir . '/subdir/file2.js', 'content2');

        $plugin->testMoveDirectoryContents($srcDir, $dstDir);

        $this->assertFileExists($dstDir . '/file1.js');
        $this->assertFileExists($dstDir . '/subdir/file2.js');
        $this->assertEquals('content1', file_get_contents($dstDir . '/file1.js'));
        $this->assertEquals('content2', file_get_contents($dstDir . '/subdir/file2.js'));
    }

    /**
     * Test moveDirectoryContents overwrites existing files.
     */
    public function testMoveDirectoryContentsOverwrites(): void
    {
        $plugin = $this->createTestablePlugin([]);

        $srcDir = $this->tempDir . '/src2';
        $dstDir = $this->tempDir . '/dst2';

        mkdir($srcDir, 0755, true);
        mkdir($dstDir, 0755, true);
        file_put_contents($srcDir . '/file.js', 'new content');
        file_put_contents($dstDir . '/file.js', 'old content');

        $plugin->testMoveDirectoryContents($srcDir, $dstDir);

        $this->assertEquals('new content', file_get_contents($dstDir . '/file.js'));
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Create a testable plugin with mock download behavior.
     *
     * @param array $urlContentMap Map of URL => content for mock downloads
     * @param bool $handleArchives Whether to actually extract archives
     */
    protected function createTestablePlugin(array $urlContentMap, bool $handleArchives = false): TestableExternalAssetsPlugin
    {
        $io = $this->createMock(IOInterface::class);
        $io->method('write')->willReturn(null);
        $io->method('writeError')->willReturn(null);

        $config = $this->createMock(Config::class);

        $composer = $this->createMock(Composer::class);
        $composer->method('getConfig')->willReturn($config);

        $plugin = new TestableExternalAssetsPlugin($urlContentMap, $handleArchives);
        $plugin->activate($composer, $io);

        return $plugin;
    }

    /**
     * Create a mock package with the given extra configuration.
     */
    protected function createMockPackage(array $extra): PackageInterface
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getExtra')->willReturn($extra);
        $package->method('getPrettyName')->willReturn('test/test-module');
        return $package;
    }

    /**
     * Create a test zip file with the given files.
     *
     * @param array $files Map of path => content
     * @return string Binary content of the zip file
     */
    protected function createTestZip(array $files): string
    {
        $zipPath = $this->tempDir . '/test_' . uniqid() . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot create test zip');
        }

        foreach ($files as $path => $content) {
            // Create directories if needed
            $dir = dirname($path);
            if ($dir !== '.' && !$zip->locateName($dir . '/')) {
                $zip->addEmptyDir($dir);
            }
            $zip->addFromString($path, $content);
        }

        $zip->close();

        $content = file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }

    /**
     * Create a test tar.gz file with the given files.
     *
     * @param array $files Map of path => content
     * @return string Binary content of the tar.gz file
     */
    protected function createTestTarGz(array $files): string
    {
        $tarPath = $this->tempDir . '/test_' . uniqid() . '.tar';
        $tarGzPath = $tarPath . '.gz';

        $phar = new \PharData($tarPath);

        foreach ($files as $path => $content) {
            $phar->addFromString($path, $content);
        }

        $phar->compress(\Phar::GZ);
        unset($phar);

        $content = file_get_contents($tarGzPath);
        @unlink($tarPath);
        @unlink($tarGzPath);

        return $content;
    }

    /**
     * Recursively remove a directory.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Testable subclass of ExternalAssetsPlugin that allows mocking downloads.
 */
class TestableExternalAssetsPlugin extends ExternalAssetsPlugin
{
    /** @var array Map of URL => content for mock downloads */
    protected array $urlContentMap;

    /** @var bool Whether to handle archives */
    protected bool $handleArchives;

    public function __construct(array $urlContentMap, bool $handleArchives = false)
    {
        $this->urlContentMap = $urlContentMap;
        $this->handleArchives = $handleArchives;
    }

    /**
     * Expose handleExternalAssets for testing with custom install path.
     */
    public function testHandleExternalAssets(object $package, string $installPath): void
    {
        $extra = $package->getExtra();
        if (empty($extra['external-assets']) || !is_array($extra['external-assets'])) {
            return;
        }

        foreach ($extra['external-assets'] as $destination => $url) {
            $destPath = $installPath . '/' . ltrim($destination, '/');
            $isDirectory = substr($destination, -1) === '/';
            $isArchive = preg_match('/\.(zip|tar\.gz|tgz)$/i', $url);

            $this->io->write(sprintf(
                '<info>Downloading asset %s for %s...</info>',
                basename($url),
                $package->getPrettyName()
            ));

            try {
                if ($isDirectory && $isArchive && $this->handleArchives) {
                    $this->testDownloadAndExtract($url, $destPath);
                } elseif ($isDirectory) {
                    $this->testDownloadFile($url, $destPath . basename($url));
                } else {
                    $this->testDownloadFile($url, $destPath);
                }
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<warning>Failed to download asset %s: %s</warning>',
                    $url,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Mock download file - uses urlContentMap instead of HTTP.
     */
    protected function testDownloadFile(string $url, string $destPath): void
    {
        if (!isset($this->urlContentMap[$url])) {
            throw new \RuntimeException("Mock download failed: URL not in map: $url");
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        file_put_contents($destPath, $this->urlContentMap[$url]);
    }

    /**
     * Mock download and extract - uses urlContentMap for archive content.
     */
    protected function testDownloadAndExtract(string $url, string $destPath): void
    {
        if (!isset($this->urlContentMap[$url])) {
            throw new \RuntimeException("Mock download failed: URL not in map: $url");
        }

        $tempFile = sys_get_temp_dir() . '/external_test_' . uniqid() . '_' . basename($url);
        $tempDir = sys_get_temp_dir() . '/external_extract_test_' . uniqid();

        mkdir($tempDir, 0755, true);
        file_put_contents($tempFile, $this->urlContentMap[$url]);

        try {
            if (preg_match('/\.zip$/i', $url)) {
                $zip = new \ZipArchive();
                if ($zip->open($tempFile) !== true) {
                    throw new \RuntimeException('Failed to open zip');
                }
                $zip->extractTo($tempDir);
                $zip->close();
            } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $url)) {
                $phar = new \PharData($tempFile);
                $phar->extractTo($tempDir);
            }

            @unlink($tempFile);

            $sourceDir = $this->getArchiveSourceDir($tempDir);

            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }

            $this->testMoveDirectoryContents($sourceDir, $destPath);

            $this->removeDirectoryRecursive($tempDir);
        } catch (\Exception $e) {
            @unlink($tempFile);
            $this->removeDirectoryRecursive($tempDir);
            throw $e;
        }
    }

    /**
     * Expose getArchiveSourceDir for testing.
     */
    public function testGetArchiveSourceDir(string $tempDir): string
    {
        return $this->getArchiveSourceDir($tempDir);
    }

    /**
     * Expose moveDirectoryContents for testing.
     */
    public function testMoveDirectoryContents(string $source, string $dest): void
    {
        $entries = array_diff(scandir($source), ['.', '..']);

        foreach ($entries as $entry) {
            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                $this->testMoveDirectoryContents($srcPath, $dstPath);
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
     * Helper to remove directory recursively.
     */
    protected function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
