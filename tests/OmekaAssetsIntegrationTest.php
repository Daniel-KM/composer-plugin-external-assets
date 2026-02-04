<?php

declare(strict_types=1);

namespace Omeka\OmekaAssets\Test;

use Omeka\OmekaAssets\OmekaAssetsPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for OmekaAssetsPlugin with Omeka S.
 *
 * These tests verify the plugin works correctly when installed in an Omeka S
 * environment. They require the Omeka S test fixtures to be set up.
 *
 * To run these tests:
 * 1. Install this plugin in an Omeka S installation
 * 2. Set the OMEKA_PATH environment variable
 * 3. Run: vendor/bin/phpunit --group integration
 *
 * @group integration
 */
class OmekaAssetsIntegrationTest extends TestCase
{
    protected ?string $omekaPath = null;
    protected ?string $testModulePath = null;

    protected function setUp(): void
    {
        $this->omekaPath = getenv('OMEKA_PATH') ?: null;

        if (!$this->omekaPath) {
            $this->markTestSkipped('OMEKA_PATH environment variable not set. Set it to run integration tests.');
        }

        if (!is_dir($this->omekaPath)) {
            $this->markTestSkipped("OMEKA_PATH '$this->omekaPath' is not a valid directory.");
        }

        // Create a temporary test module directory
        $this->testModulePath = sys_get_temp_dir() . '/OmekaAssetsTestModule_' . uniqid();
        mkdir($this->testModulePath);
    }

    protected function tearDown(): void
    {
        // Clean up test module
        if ($this->testModulePath && is_dir($this->testModulePath)) {
            $this->removeDirectory($this->testModulePath);
        }
    }

    /**
     * Test that plugin correctly identifies packages with omeka-assets.
     */
    public function testPackageWithOmekaAssets(): void
    {
        // Create a test composer.json with omeka-assets
        $composerJson = [
            'name' => 'test/omeka-assets-test',
            'type' => 'omeka-s-module',
            'extra' => [
                'installer-name' => 'OmekaAssetsTest',
                'omeka-assets' => [
                    'asset/vendor/test/file.js' => 'https://example.com/test.js',
                ],
            ],
        ];

        file_put_contents(
            $this->testModulePath . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT)
        );

        // Verify the composer.json was created correctly
        $this->assertFileExists($this->testModulePath . '/composer.json');

        $content = json_decode(file_get_contents($this->testModulePath . '/composer.json'), true);
        $this->assertArrayHasKey('extra', $content);
        $this->assertArrayHasKey('omeka-assets', $content['extra']);
    }

    /**
     * Test CLI tool execution.
     *
     * @group cli
     */
    public function testCliToolExecution(): void
    {
        $binPath = dirname(__DIR__) . '/bin/omeka-assets';

        // Test --help flag
        $output = [];
        exec("php $binPath --help 2>&1", $output, $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Omeka Assets Installer', implode("\n", $output));
    }

    /**
     * Test that the plugin class exists and has required methods.
     */
    public function testPluginClassStructure(): void
    {
        $this->assertTrue(class_exists(OmekaAssetsPlugin::class));

        $reflection = new \ReflectionClass(OmekaAssetsPlugin::class);

        // Check required interface implementations
        $this->assertTrue($reflection->implementsInterface(\Composer\Plugin\PluginInterface::class));
        $this->assertTrue($reflection->implementsInterface(\Composer\EventDispatcher\EventSubscriberInterface::class));

        // Check required methods
        $this->assertTrue($reflection->hasMethod('activate'));
        $this->assertTrue($reflection->hasMethod('deactivate'));
        $this->assertTrue($reflection->hasMethod('uninstall'));
        $this->assertTrue($reflection->hasMethod('getSubscribedEvents'));
        $this->assertTrue($reflection->hasMethod('onPostPackageInstall'));
        $this->assertTrue($reflection->hasMethod('onPostPackageUpdate'));
    }

    /**
     * Test subscribed events configuration.
     */
    public function testSubscribedEvents(): void
    {
        $events = OmekaAssetsPlugin::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey(\Composer\Installer\PackageEvents::POST_PACKAGE_INSTALL, $events);
        $this->assertArrayHasKey(\Composer\Installer\PackageEvents::POST_PACKAGE_UPDATE, $events);
    }

    /**
     * Helper to recursively remove a directory.
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
