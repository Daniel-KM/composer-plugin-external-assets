<?php declare(strict_types=1);

namespace Sempia\ExternalAssets\Test;

use PHPUnit\Framework\TestCase;
use Sempia\ExternalAssets\ExternalAssetsPlugin;

/**
 * Tests for the option "exclude" of an asset.
 *
 * The methods of the plugin are called directly: a test rewriting their logic
 * would keep passing after the logic changed.
 *
 * @covers \Sempia\ExternalAssets\AssetInstaller::normalizeAsset
 * @covers \Sempia\ExternalAssets\AssetInstaller::applyExcludes
 */
class ExternalAssetsExcludeTest extends TestCase
{
    /**
     * @var \Sempia\ExternalAssets\ExternalAssetsPlugin
     */
    protected $plugin;

    /**
     * @var string
     */
    protected $tempDir;

    protected function setUp(): void
    {
        $this->plugin = new ExternalAssetsPlugin();
        $this->tempDir = sys_get_temp_dir() . '/external_assets_exclude_' . uniqid();
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function createFiles(array $files): void
    {
        foreach ($files as $file) {
            $path = $this->tempDir . '/' . $file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, 'content');
        }
    }

    protected function remainingFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($this->tempDir) + 1);
        }
        sort($files);
        return $files;
    }

    // Normalization of an asset.

    public function testStringIsTheUrl(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com/a.js', 'exclude' => []],
            $this->plugin->normalizeAsset('https://example.com/a.js')
        );
    }

    public function testObjectWithUrlAndExclude(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com/a.zip', 'exclude' => ['index.html', '*.map']],
            $this->plugin->normalizeAsset([
                'url' => 'https://example.com/a.zip',
                'exclude' => ['index.html', '*.map'],
            ])
        );
    }

    public function testObjectWithoutExclude(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com/a.zip', 'exclude' => []],
            $this->plugin->normalizeAsset(['url' => 'https://example.com/a.zip'])
        );
    }

    public function testExcludeAsAStringIsAccepted(): void
    {
        $asset = $this->plugin->normalizeAsset([
            'url' => 'https://example.com/a.zip',
            'exclude' => 'index.html',
        ]);
        $this->assertSame(['index.html'], $asset['exclude']);
    }

    /**
     * @dataProvider provideInvalidAssets
     */
    public function testInvalidAssetHasNoUrl($asset): void
    {
        $this->assertSame('', $this->plugin->normalizeAsset($asset)['url']);
    }

    public function provideInvalidAssets(): array
    {
        return [
            'empty array' => [[]],
            'no url' => [['exclude' => ['a']]],
            'url not a string' => [['url' => ['https://example.com']]],
            'null' => [null],
            'int' => [42],
        ];
    }

    public function testEmptyExcludesAreDropped(): void
    {
        $asset = $this->plugin->normalizeAsset([
            'url' => 'https://example.com/a.zip',
            'exclude' => ['index.html', '', '   ', null, ['nested']],
        ]);
        $this->assertSame(['index.html'], $asset['exclude']);
    }

    // Application of the excludes.

    public function testRemovesTheExcludedFile(): void
    {
        $this->createFiles(['index.html', 'lib.js', 'lib.css']);

        $removed = $this->plugin->applyExcludes($this->tempDir, ['index.html']);

        $this->assertSame(['index.html'], $removed);
        $this->assertSame(['lib.css', 'lib.js'], $this->remainingFiles());
    }

    public function testRemovesWithAGlob(): void
    {
        $this->createFiles(['lib.js', 'lib.js.map', 'lib.css.map']);

        $removed = $this->plugin->applyExcludes($this->tempDir, ['*.map']);

        sort($removed);
        $this->assertSame(['lib.css.map', 'lib.js.map'], $removed);
        $this->assertSame(['lib.js'], $this->remainingFiles());
    }

    public function testRemovesADirectory(): void
    {
        $this->createFiles(['lib.js', 'docs/index.html', 'docs/sub/page.html']);

        $removed = $this->plugin->applyExcludes($this->tempDir, ['docs']);

        $this->assertSame(['docs'], $removed);
        $this->assertSame(['lib.js'], $this->remainingFiles());
    }

    public function testMissingExcludeIsNotAnError(): void
    {
        $this->createFiles(['lib.js']);

        $this->assertSame([], $this->plugin->applyExcludes($this->tempDir, ['absent.html']));
        $this->assertSame(['lib.js'], $this->remainingFiles());
    }

    public function testNoExcludeDoesNothing(): void
    {
        $this->createFiles(['index.html', 'lib.js']);

        $this->assertSame([], $this->plugin->applyExcludes($this->tempDir, []));
        $this->assertSame(['index.html', 'lib.js'], $this->remainingFiles());
    }

    public function testMissingDirectoryIsNotAnError(): void
    {
        $this->assertSame([], $this->plugin->applyExcludes($this->tempDir . '/absent', ['a']));
    }

    /**
     * An asset declaration must never remove a file outside its destination.
     *
     * @dataProvider provideEscapingPatterns
     */
    public function testPatternEscapingTheDestinationIsSkipped(string $pattern): void
    {
        $outside = dirname($this->tempDir) . '/external_assets_outside_' . uniqid() . '.txt';
        file_put_contents($outside, 'content');
        $this->createFiles(['lib.js']);

        try {
            $removed = $this->plugin->applyExcludes($this->tempDir, [$pattern]);
            $this->assertSame([], $removed);
            $this->assertFileExists($outside, 'A file outside the destination was removed.');
            $this->assertSame(['lib.js'], $this->remainingFiles());
        } finally {
            @unlink($outside);
        }
    }

    public function provideEscapingPatterns(): array
    {
        return [
            'parent' => ['../*.txt'],
            'nested parent' => ['sub/../../*.txt'],
            'absolute' => ['/tmp/*.txt'],
            'only parent' => ['..'],
        ];
    }
}
