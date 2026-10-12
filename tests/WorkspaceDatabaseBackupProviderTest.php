<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupConfig;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceDatabaseBackupProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceDatabaseBackupProvider::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceBackupLinks::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceDatabaseBackupProviderTest extends TestCase
{
    private string $root;

    private WorkspaceConfig $config;

    private Database $database;

    private BackupConfig $backup;

    /** @var array<string,string|false> */
    private array $environment = [];

    /**
     * EN: Uses isolated fixtures only, never real configuration.
     *
     * HR: Samo izolirane fixture, bez stvarne konfiguracije.
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/workspace-current-restore-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0770, true);
        foreach (['HPH_CONFIG_PATH', 'HPH_CONFIG_OVERRIDE_PATHS'] as $key) {
            $this->environment[$key] = getenv($key);
        }

        putenv('HPH_CONFIG_PATH=' . $this->root . '/config');
        putenv('HPH_CONFIG_OVERRIDE_PATHS');
        $host = new Config(new Helper(), [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'backup' => ['archive_dir' => $this->root . '/archives', 'staging_dir' => $this->root . '/staging',
                'upload_dir' => $this->root . '/uploads'],
        ], $this->root);
        $this->config = new WorkspaceConfig($host, dirname(__DIR__));
        $this->database = new Database($host, new Helper());
        $bridge = new class ($this->root) extends ComposerBridge {
            public function __construct(private readonly string $root)
            {
            }

            /** @return array<string,mixed> */
            public function getRootPackage(): array
            {
                return ['install_path' => $this->root];
            }
        };
        $this->backup = new BackupConfig(
            $host,
            $bridge,
            dirname(__DIR__) . '/vendor/aaieduhr/heartphrame-module-backup',
        );
    }

    /**
     * EN: Removes test data only and restores the environment.
     *
     * HR: Uklanja samo testne podatke i vraća okolinu.
     */
    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }

        (new BackupFilesystem())->removeDirectory($this->root);
    }

    /**
     * EN: The current schema has no historical settings dataset or accepted schema aliases.
     *
     * HR: Aktualna shema nema povijesni skup postavki niti prihvaća alternativne stare sheme.
     */
    public function testCurrentExportDeclaresExactSchemaAndRoutes(): void
    {
        $provider = $this->provider();
        $this->assertFalse($provider->metadata()->acceptsImportSchema(2));
        $this->assertTrue($provider->metadata()->acceptsImportSchema(3));
        $writer = $this->writer();
        $writer->beginProvider($provider->metadata());

        $provider->export(new BackupExportContext(new BackupScope(BackupScope::SITE), []), $writer);
        $reader = new BackupArchiveReader($writer->finish(), $this->backup, new BackupFilesystem());
        try {
            $this->assertSame(3, $reader->providerManifest('workspace')['schema_version']);
            $this->assertSame([], iterator_to_array($reader->records('workspace', 'homepage-settings')));
            $this->assertSame('/' . trim($this->config->rootPath(), '/'), $reader
                ->manifest()['source']['link_route_roots']['workspace']);
        } finally {
            $reader->close();
        }
    }

    /**
     * EN: The provider exports current DB definitions only.
     *
     * HR: Provider izvozi samo aktualne DB definicije.
     */
    private function provider(): WorkspaceDatabaseBackupProvider
    {
        return new WorkspaceDatabaseBackupProvider(
            $this->database,
            new BackupProviderMetadata(
                'workspace',
                'test/workspace',
                3,
                ['hr' => 'Test', 'en' => 'Test'],
            ),
            [],
            $this->config,
        );
    }

    /**
     * EN: Each archive has isolated staging.
     *
     * HR: Svaka arhiva ima svoj izolirani staging.
     */
    private function writer(): BackupArchiveWriter
    {
        return new BackupArchiveWriter(
            $this->backup,
            new BackupFilesystem(),
            new BackupScope(BackupScope::SITE),
            [],
            'Test',
        );
    }
}
