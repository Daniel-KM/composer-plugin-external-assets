<?php

declare(strict_types=1);

namespace Sempia\ExternalAssets\Test;

use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the install logic, without any http request.
 *
 * The tests use the code of AssetInstaller: only the transport and the output
 * are replaced, so a change of the decisions is caught.
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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
     * A failed download must not remove the assets already installed.
     */
    public function testAssetsKeptWhenDownloadFails(): void
    {
        $destPath = $this->installPath . '/asset/vendor/library';
        mkdir($destPath, 0755, true);
        file_put_contents($destPath . '/lib.min.js', '// Installed version');
        file_put_contents($destPath . '/.htaccess', 'Deny from all');

        // The url is not in the map, so the download throws.
        $plugin = $this->createTestablePlugin([]);
        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/library/' => 'https://example.com/library-2.0.0.zip',
            ],
        ]);

        $this->assertFalse($plugin->testHandleExternalAssets($package, $this->installPath));

        $this->assertFileExists($destPath . '/lib.min.js');
        $this->assertEquals('// Installed version', file_get_contents($destPath . '/lib.min.js'));
        $this->assertFileExists($destPath . '/.htaccess');
    }

    /**
     * An asset missing for sure must not remove the assets already installed.
     */
    public function testAssetsKeptWhenAssetIsMissing(): void
    {
        $destPath = $this->installPath . '/asset/vendor/library';
        mkdir($destPath, 0755, true);
        file_put_contents($destPath . '/lib.min.js', '// Installed version');

        $url = 'https://example.com/library-2.0.0.zip';
        $plugin = $this->createTestablePlugin([], [$url => 404]);
        $package = $this->createMockPackage([
            'external-assets' => ['asset/vendor/library/' => $url],
        ]);

        $this->assertFalse($plugin->testHandleExternalAssets($package, $this->installPath));

        $this->assertFileExists($destPath . '/lib.min.js');
        $this->assertEquals('// Installed version', file_get_contents($destPath . '/lib.min.js'));
        $this->assertStringContainsString('HTTP 404', implode("\n", $plugin->messages));
    }

    /**
     * A failed download must not truncate a single file already installed.
     */
    public function testFileKeptWhenDownloadFails(): void
    {
        $destFile = $this->installPath . '/asset/vendor/lib.min.js';
        mkdir(dirname($destFile), 0755, true);
        file_put_contents($destFile, '// Installed version');

        $plugin = $this->createTestablePlugin([]);
        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/lib.min.js' => 'https://example.com/lib-2.0.0.min.js',
            ],
        ]);

        $this->assertFalse($plugin->testHandleExternalAssets($package, $this->installPath));
        $this->assertEquals('// Installed version', file_get_contents($destFile));
    }

    /**
     * The previous assets are replaced, but the files of the package are kept.
     */
    public function testAssetsReplacedAndKeptFilesPreserved(): void
    {
        $destPath = $this->installPath . '/asset/vendor/library';
        mkdir($destPath . '/old', 0755, true);
        file_put_contents($destPath . '/removed.js', '// Previous version');
        file_put_contents($destPath . '/old/nested.js', '// Previous nested');
        file_put_contents($destPath . '/.htaccess', 'Deny from all');

        $zipContent = $this->createTestZip(['lib.min.js' => '// New version']);
        $plugin = $this->createTestablePlugin([
            'https://example.com/library-2.0.0.zip' => $zipContent,
        ]);
        $package = $this->createMockPackage([
            'external-assets' => [
                'asset/vendor/library/' => 'https://example.com/library-2.0.0.zip',
            ],
        ]);

        $this->assertTrue($plugin->testHandleExternalAssets($package, $this->installPath));

        $this->assertFileExists($destPath . '/lib.min.js');
        $this->assertFileDoesNotExist($destPath . '/removed.js');
        $this->assertDirectoryDoesNotExist($destPath . '/old');
        $this->assertFileExists($destPath . '/.htaccess');
    }

    /**
     * An asset already installed from the same url is not downloaded again.
     */
    public function testUpToDateAssetIsSkipped(): void
    {
        $url = 'https://example.com/library-1.0.0.zip';
        $zipContent = $this->createTestZip(['lib.min.js' => '// Version 1']);
        $package = $this->createMockPackage([
            'external-assets' => ['asset/vendor/library/' => $url],
        ]);

        $plugin = $this->createTestablePlugin([$url => $zipContent]);
        $this->assertTrue($plugin->testHandleExternalAssets($package, $this->installPath));

        $destFile = $this->installPath . '/asset/vendor/library/lib.min.js';
        file_put_contents($destFile, '// Edited');

        // The url did not change, so the asset is kept as it is.
        $plugin = $this->createTestablePlugin([]);
        $this->assertTrue($plugin->testHandleExternalAssets($package, $this->installPath));
        $this->assertEquals('// Edited', file_get_contents($destFile));
    }

    /**
     * The files of the package are not removed with the assets.
     */
    public function testClearDirectoryKeepsPackageFiles(): void
    {
        $destPath = $this->installPath . '/asset/vendor/library';
        mkdir($destPath . '/sub', 0755, true);
        file_put_contents($destPath . '/asset.js', '// Asset');
        file_put_contents($destPath . '/sub/nested.js', '// Nested');
        foreach (['.htaccess', '.gitkeep', '.gitignore', 'index.html'] as $kept) {
            file_put_contents($destPath . '/' . $kept, 'kept');
        }

        $plugin = $this->createTestablePlugin([]);
        $plugin->testClearDirectory($destPath);

        $this->assertFileDoesNotExist($destPath . '/asset.js');
        $this->assertDirectoryDoesNotExist($destPath . '/sub');
        foreach (['.htaccess', '.gitkeep', '.gitignore', 'index.html'] as $kept) {
            $this->assertFileExists($destPath . '/' . $kept);
        }
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
    protected function createTestablePlugin(array $urlContentMap, array $urlStatusMap = []): TestableAssetInstaller
    {
        return new TestableAssetInstaller($urlContentMap, $urlStatusMap);
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
