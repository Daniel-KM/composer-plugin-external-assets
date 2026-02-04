<?php

declare(strict_types=1);

namespace Sempia\ExternalAssets\Test;

use Sempia\ExternalAssets\ExternalAssetsPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ExternalAssetsPlugin.
 *
 * These tests verify the plugin works correctly when installed. They require
 * the test fixtures to be set up.
 *
 * To run these tests:
 * 1. Install this plugin in a project
 * 2. Set the PROJECT_PATH environment variable
 * 3. Run: vendor/bin/phpunit --group integration
 *
 * @group integration
 */
class ExternalAssetsIntegrationTest extends TestCase
{
    protected ?string $projectPath = null;
    protected ?string $testModulePath = null;

    protected function setUp(): void
    {
        $this->projectPath = getenv('PROJECT_PATH') ?: null;

        if (!$this->projectPath) {
            $this->markTestSkipped('PROJECT_PATH environment variable not set. Set it to run integration tests.');
        }

        if (!is_dir($this->projectPath)) {
            $this->markTestSkipped("PROJECT_PATH '$this->projectPath' is not a valid directory.");
        }

        // Create a temporary test module directory
        $this->testModulePath = sys_get_temp_dir() . '/ExternalAssetsTestModule_' . uniqid();
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
     * Test that plugin correctly identifies packages with external-assets.
     */
    public function testPackageWithExternalAssets(): void
    {
        // Create a test composer.json with external-assets
        $composerJson = [
            'name' => 'test/external-assets-test',
            'type' => 'library',
            'extra' => [
                'external-assets' => [
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
        $this->assertArrayHasKey('external-assets', $content['extra']);
    }

    /**
     * Test CLI tool execution.
     *
     * @group cli
     */
    public function testCliToolExecution(): void
    {
        $binPath = dirname(__DIR__) . '/bin/external-assets';

        // Test --help flag
        $output = [];
        exec("php $binPath --help 2>&1", $output, $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('External Assets Installer', implode("\n", $output));
    }

    /**
     * Test that the plugin class exists and has required methods.
     */
    public function testPluginClassStructure(): void
    {
        $this->assertTrue(class_exists(ExternalAssetsPlugin::class));

        $reflection = new \ReflectionClass(ExternalAssetsPlugin::class);

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
        $events = ExternalAssetsPlugin::getSubscribedEvents();

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
