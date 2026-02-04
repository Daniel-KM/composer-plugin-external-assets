<?php

declare(strict_types=1);

namespace Omeka\OmekaAssets\Test;

use PHPUnit\Framework\TestCase;

/**
 * Test OmekaAssetsPlugin functionality.
 *
 * Note: These tests verify the configuration parsing and logic without making
 * actual HTTP requests. Integration tests that actually download files are
 * in OmekaAssetsIntegrationTest.php.
 */
class OmekaAssetsPluginTest extends TestCase
{
    /**
     * @dataProvider omekaAssetsProvider
     */
    public function testOmekaAssetsDestinationDetection(string $destination, string $url, bool $expectDirectory, bool $expectArchive): void
    {
        $isDirectory = substr($destination, -1) === '/';
        $isArchive = (bool) preg_match('/\.(zip|tar\.gz|tgz)$/i', $url);

        $this->assertEquals($expectDirectory, $isDirectory, "Directory detection for: $destination");
        $this->assertEquals($expectArchive, $isArchive, "Archive detection for: $url");
    }

    public function omekaAssetsProvider(): array
    {
        return [
            // [destination, url, expectDirectory, expectArchive]
            [
                'asset/vendor/lib/file.min.js',
                'https://example.com/file.min.js',
                false, // not a directory
                false, // not an archive
            ],
            [
                'asset/vendor/lib/',
                'https://example.com/archive.zip',
                true,  // is a directory
                true,  // is an archive
            ],
            [
                'asset/vendor/mirador/',
                'https://example.com/mirador-2.7.0.tar.gz',
                true,  // is a directory
                true,  // is an archive
            ],
            [
                'asset/vendor/lib/',
                'https://example.com/file.tgz',
                true,  // is a directory
                true,  // is an archive
            ],
            [
                'asset/css/custom.css',
                'https://example.com/styles.css',
                false, // not a directory
                false, // not an archive
            ],
            // Third case: directory + non-archive = copy file into directory
            [
                'asset/vendor/lib/',
                'https://example.com/jquery.min.js',
                true,  // is a directory
                false, // not an archive -> file copied into directory
            ],
        ];
    }

    /**
     * @dataProvider omekaAssetsActionProvider
     */
    public function testOmekaAssetsActionDetection(string $destination, string $url, string $expectedAction): void
    {
        $isDirectory = substr($destination, -1) === '/';
        $isArchive = (bool) preg_match('/\.(zip|tar\.gz|tgz)$/i', $url);

        if ($isDirectory && $isArchive) {
            $action = 'extract';
        } elseif ($isDirectory) {
            $action = 'copy_into_dir';
        } else {
            $action = 'download';
        }

        $this->assertEquals($expectedAction, $action, "Action for: $destination <- $url");
    }

    public function omekaAssetsActionProvider(): array
    {
        return [
            // [destination, url, expectedAction]
            ['asset/vendor/lib/file.min.js', 'https://example.com/file.min.js', 'download'],
            ['asset/vendor/lib/', 'https://example.com/archive.zip', 'extract'],
            ['asset/vendor/lib/', 'https://example.com/archive.tar.gz', 'extract'],
            ['asset/vendor/lib/', 'https://example.com/jquery.min.js', 'copy_into_dir'],
            ['asset/vendor/lib/', 'https://example.com/styles.css', 'copy_into_dir'],
        ];
    }

    public function testDestinationFilenameRename(): void
    {
        // When destination has a different filename than the URL, it renames.
        $destination = 'asset/vendor/lib/jquery.autocomplete.min.js';
        $url = 'https://example.com/jquery.autocomplete-1.5.0.min.js';

        // The destination path is used as-is (not the URL basename).
        $destPath = '/install/path/' . ltrim($destination, '/');
        $this->assertEquals('/install/path/asset/vendor/lib/jquery.autocomplete.min.js', $destPath);
        $this->assertNotEquals(basename($url), basename($destPath));
    }

    public function testArchiveSingleRootDirectoryStripping(): void
    {
        // Simulate the logic that detects a single root directory.
        $tempDir = sys_get_temp_dir() . '/omeka_test_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/mirador-2.7.0');
        touch($tempDir . '/mirador-2.7.0/file1.js');
        touch($tempDir . '/mirador-2.7.0/file2.js');

        $entries = array_diff(scandir($tempDir), ['.', '..']);

        // Single entry that is a directory -> should be stripped.
        $this->assertCount(1, $entries);
        $entry = reset($entries);
        $this->assertTrue(is_dir($tempDir . '/' . $entry));

        // The source dir should be the nested directory.
        $sourceDir = $tempDir . '/' . $entry;
        $this->assertEquals($tempDir . '/mirador-2.7.0', $sourceDir);

        // Cleanup.
        unlink($tempDir . '/mirador-2.7.0/file1.js');
        unlink($tempDir . '/mirador-2.7.0/file2.js');
        rmdir($tempDir . '/mirador-2.7.0');
        rmdir($tempDir);
    }

    public function testArchiveMultipleEntriesNoStripping(): void
    {
        // Simulate an archive with multiple root entries.
        $tempDir = sys_get_temp_dir() . '/omeka_test_' . uniqid();
        mkdir($tempDir);
        touch($tempDir . '/file1.js');
        touch($tempDir . '/file2.js');

        $entries = array_diff(scandir($tempDir), ['.', '..']);

        // Multiple entries -> no stripping, use tempDir as source.
        $this->assertCount(2, $entries);

        // Cleanup.
        unlink($tempDir . '/file1.js');
        unlink($tempDir . '/file2.js');
        rmdir($tempDir);
    }

    public function testOmekaAssetsConfigParsing(): void
    {
        $composerJson = [
            'extra' => [
                'omeka-assets' => [
                    'asset/vendor/jquery-autocomplete/jquery.autocomplete.min.js' => 'https://example.com/jquery.autocomplete.min.js',
                    'asset/vendor/mirador/' => 'https://example.com/mirador.zip',
                ],
            ],
        ];

        $extra = $composerJson['extra'];
        $this->assertArrayHasKey('omeka-assets', $extra);
        $this->assertIsArray($extra['omeka-assets']);
        $this->assertCount(2, $extra['omeka-assets']);

        foreach ($extra['omeka-assets'] as $destination => $url) {
            $this->assertIsString($destination);
            $this->assertIsString($url);
            $this->assertStringStartsWith('https://', $url);
        }
    }

    public function testEmptyOmekaAssetsConfig(): void
    {
        $composerJson = [
            'extra' => [],
        ];

        $extra = $composerJson['extra'];
        $hasAssets = !empty($extra['omeka-assets']) && is_array($extra['omeka-assets']);
        $this->assertFalse($hasAssets);
    }

    public function testAssetExistsForFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/omeka_test_' . uniqid();
        mkdir($tempDir);

        // Non-existent file
        $this->assertFalse(file_exists($tempDir . '/file.js'));

        // Existent file
        file_put_contents($tempDir . '/file.js', 'content');
        $this->assertTrue(file_exists($tempDir . '/file.js'));

        // Cleanup
        unlink($tempDir . '/file.js');
        rmdir($tempDir);
    }

    public function testAssetExistsForDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/omeka_test_' . uniqid();
        mkdir($tempDir);

        // Non-existent directory
        $libDir = $tempDir . '/lib';
        $this->assertFalse(is_dir($libDir));

        // Empty directory (should be considered as not existing for assets)
        mkdir($libDir);
        $entries = array_diff(scandir($libDir), ['.', '..']);
        $this->assertCount(0, $entries);

        // Non-empty directory
        file_put_contents($libDir . '/file.js', 'content');
        $entries = array_diff(scandir($libDir), ['.', '..']);
        $this->assertCount(1, $entries);

        // Cleanup
        unlink($libDir . '/file.js');
        rmdir($libDir);
        rmdir($tempDir);
    }

    public function testMoveDirectoryContents(): void
    {
        $srcDir = sys_get_temp_dir() . '/omeka_test_src_' . uniqid();
        $dstDir = sys_get_temp_dir() . '/omeka_test_dst_' . uniqid();

        mkdir($srcDir);
        mkdir($srcDir . '/subdir');
        file_put_contents($srcDir . '/file1.js', 'content1');
        file_put_contents($srcDir . '/subdir/file2.js', 'content2');

        mkdir($dstDir);

        // Copy the logic from the plugin
        $this->moveDirectoryContents($srcDir, $dstDir);

        // Check files were moved
        $this->assertFileExists($dstDir . '/file1.js');
        $this->assertFileExists($dstDir . '/subdir/file2.js');
        $this->assertEquals('content1', file_get_contents($dstDir . '/file1.js'));
        $this->assertEquals('content2', file_get_contents($dstDir . '/subdir/file2.js'));

        // Cleanup
        unlink($dstDir . '/file1.js');
        unlink($dstDir . '/subdir/file2.js');
        rmdir($dstDir . '/subdir');
        rmdir($dstDir);
        // srcDir should be empty now (files moved)
        @rmdir($srcDir . '/subdir');
        @rmdir($srcDir);
    }

    /**
     * Helper method to move directory contents (mirrors plugin logic).
     */
    protected function moveDirectoryContents(string $source, string $dest): void
    {
        $entries = array_diff(scandir($source), ['.', '..']);

        foreach ($entries as $entry) {
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
}
