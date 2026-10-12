<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceSettingsService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * EN: Configuration and the GUI use isolated fictional data.
 *
 * HR: Konfiguracija i GUI koriste izolirane izmišljene podatke.
 */
#[CoversClass(WorkspaceConfigurationStore::class)]
#[CoversClass(WorkspaceConfig::class)]
#[CoversClass(WorkspaceSettingsService::class)]
#[CoversClass(WorkspaceHomepageRepository::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue::class)]
final class WorkspaceConfigurationStoreTest extends TestCase
{
    private string $root;

    /** @var array<string,string|false> */
    private array $environment = [];

    /**
     * EN: Prepares two external layers without an application config directory.
     *
     * HR: Priprema dva vanjska sloja bez aplikacijskog configa.
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/workspace-config-' . bin2hex(random_bytes(6));
        foreach (['base/workspace', 'top', 'app'] as $path) {
            mkdir($this->root . '/' . $path, 0770, true);
        }

        foreach (['HPH_CONFIG_PATH', 'HPH_CONFIG_OVERRIDE_PATHS'] as $key) {
            $this->environment[$key] = getenv($key);
        }

        putenv('HPH_CONFIG_PATH=' . $this->root . '/base');
        putenv('HPH_CONFIG_OVERRIDE_PATHS=' . $this->root . '/top');
    }

    /**
     * EN: Deletes only its own fixtures and restores the environment.
     *
     * HR: Briše samo vlastite fixture i vraća okolinu.
     */
    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->root,
            \FilesystemIterator::SKIP_DOTS,
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    /**
     * EN: Two GUI forms preserve the latest homepage, extensions, and manually disabled options.
     *
     * HR: Dva GUI obrasca čuvaju aktualnu naslovnicu, proširenja i ručno isključene opcije.
     */
    public function testLayersAndConcurrentFormSavePreserveOtherSettings(): void
    {
        $source = '{"menu":{"auto_register_top":false},'
        . '"creation":{"users":[7,8]},"extension":{"retained":true}}';
        file_put_contents($this->root . '/base/workspace/workspace.json', $source);
        $config = $this->config();
        $this->assertTrue($config->shouldAutoRegisterSettingsMenu());
        $this->assertSame([7, 8], $config->creatorUserIds());
        $database = new Database(new Config(new Helper(), [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), new Helper());
        $homepage = new WorkspaceHomepageRepository($database, $config);
        $homepage->saveSettings(['type' => 'page', 'node_id' => 17], ['type' => 'default'], false);
        chmod($config->settingsFilePath(), 0640);
        $settings = new WorkspaceSettingsService($config, new Routes(), new WorkspaceRepository($database));
        $settings->saveFromForm(['root_path' => 'knowledge', 'tree_visible' => true]);

        $values = $config->configurationStore()->read();
        $this->assertSame('knowledge', $config->rootPath());
        $this->assertSame(17, $homepage->settings()['public_node_id']);
        $this->assertFalse($homepage->settings()['allow_user_selection']);
        $this->assertFalse($config->shouldAutoRegisterTopMenu());
        $this->assertTrue($config->shouldAutoRegisterSettingsMenu());
        $this->assertSame([], $config->creatorUserIds());
        $this->assertSame(['retained' => true], $values['extension']);
        $this->assertSame($source, file_get_contents($this->root . '/base/workspace/workspace.json'));
        $this->assertSame(0640, fileperms($config->settingsFilePath()) & 0777);
        $this->assertDirectoryDoesNotExist($this->root . '/app/config');
        $this->assertFalse($database->schema()->hasTable('workspace_homepage_settings'));
    }

    /**
     * EN: An empty higher-layer list completely replaces the lower list.
     *
     * HR: Prazna lista višeg sloja potpuno zamjenjuje listu nižeg sloja.
     */
    public function testListReplacementAndReadOnlyReadsDoNotCreateFiles(): void
    {
        file_put_contents($this->root . '/base/workspace/workspace.json', '{"creation":{"users":[7,8]}}');
        mkdir($this->root . '/top/workspace');
        file_put_contents($this->root . '/top/workspace/workspace.json', '{"creation":{"users":[]}}');
        $config = $this->config();
        $this->assertSame([], $config->creatorUserIds());
        $this->assertFileDoesNotExist($config->settingsFilePath() . '.lock');
        $this->assertTrue($config->configurationStore()->isWritable());
        $this->assertFileDoesNotExist($config->settingsFilePath() . '.lock');
    }

    /**
     * EN: Invalid changes are not published and existing files remain untouched.
     *
     * HR: Nevaljana promjena nije objavljena i postojeće datoteke ostaju netaknute.
     */
    public function testInvalidMutationDoesNotPublishAnyChange(): void
    {
        mkdir($this->root . '/top/workspace');
        $path = $this->root . '/top/workspace/workspace.json';
        $source = '{"routing":{"root_path":"knowledge"},"extension":{"retained":true}}';
        file_put_contents($path, $source);
        $store = $this->config()->configurationStore();
        try {
            $store->mutate(static function (array $values): array {
                $values['shorts']['limit'] = 0;
                return $values;
            });
            $this->fail('Invalid Workspace configuration was accepted.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('display', $runtimeException->getMessage());
        }

        $this->assertSame($source, file_get_contents($path));
    }

    /**
     * EN: Read-only JSON remains usable; writes never fall back to a weaker layer.
     *
     * HR: Read-only JSON ostaje čitljiv; pisanje ne prelazi u slabiji sloj.
     */
    public function testReadOnlyConfigurationRemainsUsableAndRejectsWrites(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('Unix non-root permissions are required.');
        }

        mkdir($this->root . '/top/workspace');
        $path = $this->root . '/top/workspace/workspace.json';
        $source = '{"routing":{"root_path":"knowledge"}}';
        file_put_contents($path, $source);
        chmod($path, 0440);
        chmod(dirname($path), 0550);
        clearstatcache();
        try {
            $config = $this->config();
            $this->assertSame('knowledge', $config->rootPath());
            $this->assertTrue($config->shouldAutoRegisterSettingsMenu());
            $this->assertFalse($config->configurationStore()->isWritable());
            try {
                $config->configurationStore()->mutate(static fn(array $values): array => $values);
                $this->fail('Read-only configuration was unexpectedly writable.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('read-only', $exception->getMessage());
            }

            $this->assertSame($source, file_get_contents($path));
            $this->assertFileDoesNotExist($path . '.lock');
            $this->assertFileDoesNotExist($this->root . '/base/workspace/workspace.json');
        } finally {
            chmod(dirname($path), 0770);
            chmod($path, 0660);
        }
    }

    /**
     * EN: Legacy PHP is neither a secondary source nor executed at startup.
     *
     * HR: Stari PHP nije sekundarni izvor niti se izvršava pri pokretanju.
     */
    public function testLegacyPhpIsIgnoredOutsideApplicationUpgrade(): void
    {
        file_put_contents($this->root . '/base/workspace.php', '<?php throw new RuntimeException("legacy");');
        $this->assertSame('workspace', $this->config()->rootPath());
    }

    /**
     * EN: The documented .dist is valid JSON read by the real store without GUI options.
     *
     * HR: Dokumentirani .dist je valjan JSON i čita ga stvarni spremnik bez GUI opcija.
     */
    public function testDocumentedDistributionExampleMatchesCurrentDefaults(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/config/workspace.json.dist');
        $this->assertIsString($source);
        $example = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($example);
        $this->assertArrayNotHasKey('gui', $example);
        file_put_contents($this->root . '/base/workspace/workspace.json', $source);
        $config = $this->config();
        $values = $config->configurationStore()->read();
        $this->assertSame('workspace', $config->rootPath());
        $this->assertTrue($config->shouldAutoRegisterSettingsMenu());
        $this->assertSame($example['_documentation'], $values['_documentation']);
        unset($values['_documentation']);
        $this->assertSame(require dirname(__DIR__) . '/config/workspace.php', $values);
    }

    /**
     * EN: Invalid JSON fails closed instead of silently falling back.
     *
     * HR: Nevaljan JSON zatvara konfiguraciju umjesto tihog fallbacka.
     */
    public function testInvalidJsonFailsClosed(): void
    {
        mkdir($this->root . '/top/workspace');
        file_put_contents($this->root . '/top/workspace/workspace.json', '{"defaults":');
        $this->expectException(\RuntimeException::class);
        $this->config();
    }

    /**
     * EN: Configuration strictly rejects string booleans.
     *
     * HR: Konfiguracija strogo odbija tekstualne boolean vrijednosti.
     */
    public function testWrongTypesAreRejected(): void
    {
        file_put_contents($this->root . '/base/workspace/workspace.json', '{"defaults":{"tree_visible":"false"}}');
        $this->expectException(\RuntimeException::class);
        $this->config();
    }

    /**
     * EN: The module path must not redirect writes outside the declared layer.
     *
     * HR: Putanja modula ne smije preusmjeriti zapis izvan deklariranog sloja.
     */
    public function testModuleSymlinkIsRejected(): void
    {
        symlink($this->root . '/base/workspace', $this->root . '/top/workspace');
        $this->expectException(\RuntimeException::class);
        $this->config();
    }

    /**
     * EN: The GUI remains available and an unknown extension cannot disable it.
     *
     * HR: GUI ostaje dostupan, a nepoznato proširenje ga ne isključuje.
     */
    public function testGuiRoutesRemainUnchangedWithUnknownExtension(): void
    {
        file_put_contents($this->root . '/base/workspace/workspace.json', '{"gui":{"enabled":false}}');
        $config = $this->config();
        $manifest = require dirname(__DIR__) . '/heartphrame-manifest.php';
        $settingsRoutes = 0;
        foreach ($manifest->getBaseRoutes() as $route) {
            if (str_starts_with($route[1], '/settings/workspaces')) {
                ++$settingsRoutes;
            }
        }

        $this->assertGreaterThan(0, $settingsRoutes);
        $this->assertTrue($config->shouldAutoRegisterSettingsMenu());
    }

    /**
     * EN: The optional Backup provider reads current JSON and supplies identity transforms.
     *
     * HR: Opcionalni Backup provider čita aktualni JSON i pruža transformacije identiteta.
     */
    public function testOptionalBackupProviderUsesCurrentConfiguration(): void
    {
        $config = $this->config();
        $this->assertSame('workspace', $config->rootPath());
        $services = require dirname(__DIR__) . '/config/services.php';
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->atLeast(2))->method('get')->willReturnMap([
            [WorkspaceConfig::class, $config],
            [\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class,
                new \AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem()],
        ]);
        $provider = $services['heartphrame.backup.provider.workspace-configuration']($container);
        $this->assertSame('workspace-configuration', $provider->metadata()->id);
    }

    /**
     * EN: Receives an explicit root without initializing Simbioza.
     *
     * HR: Prima eksplicitni root bez inicijalizacije Simbioze.
     */
    private function config(): WorkspaceConfig
    {
        return new WorkspaceConfig(new Config(new Helper(), [], $this->root . '/app'), dirname(__DIR__));
    }
}
