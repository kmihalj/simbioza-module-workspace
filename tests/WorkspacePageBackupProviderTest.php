<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupConfig;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspacePageBackupProvider;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(WorkspacePageBackupProvider::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue::class)]
final class WorkspacePageBackupProviderTest extends TestCase
{
    private string $temporaryRoot = '';

    protected function tearDown(): void
    {
        if ($this->temporaryRoot !== '') {
            (new BackupFilesystem())->removeDirectory($this->temporaryRoot);
        }
    }

    /**
     * HR: Pregazivanje stranice čuva URL, mjesto u stablu i postojeća prava
     *     kada administrator nije odabrao uvoz prava.
     * EN: Replacing a page retains its URL, tree position, and existing ACL
     *     when the administrator did not select ACL import.
     */
    public function testReplaceKeepsTargetIdentityAndOptionalPermissions(): void
    {
        [$database, $backupConfig] = $this->environment();
        $now = '2026-09-12 12:00:00';
        $database->table(ModuleAuth::TABLE_AUTH_USERS)->insert([
            'id' => 1,
            'login_identifier' => 'backup.admin',
            'is_active' => 1,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'id' => 1,
            'uuid' => '48e2e3cf-4721-48f3-8f24-c52b75ce84ee',
            'slug' => 'upute',
            'name' => 'Upute',
            'visibility' => 'restricted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'id' => 10,
            'uuid' => 'd41d260b-7d6a-4e71-965b-c982180f62f6',
            'workspace_id' => 1,
            'node_type' => 'document',
            'slug' => 'izvor',
            'title' => 'Izvorna stranica',
            'document_key' => 'upute-izvor',
            'sort_order' => 100,
            'created_by_user_id' => 1,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'id' => 20,
            'uuid' => '9c1eaf9d-3e21-4254-aa09-0c19222d68c7',
            'workspace_id' => 1,
            'node_type' => 'document',
            'slug' => 'stalni-url',
            'title' => 'Stara ciljna stranica',
            'document_key' => 'upute-cilj',
            'sort_order' => 420,
            'created_by_user_id' => 1,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
            'node_id' => 10,
            'label' => 'izvorna-oznaka',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
            'node_id' => 20,
            'label' => 'stara-oznaka',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)->insert([
            'node_id' => 20,
            'subject_type' => 'user',
            'subject_id' => 1,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_publish' => 0,
            'can_delete' => 0,
            'can_manage' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $filesystem = new BackupFilesystem();
        $provider = new WorkspacePageBackupProvider(
            $database,
            new AuthBackupIdentityResolver($database, new AuthUserService($database)),
            new WorkspaceRepository($database),
        );
        $sourceScope = new BackupScope(BackupScope::PAGE, '10');
        $writer = new BackupArchiveWriter(
            $backupConfig,
            $filesystem,
            $sourceScope,
            ['application' => 'workspace-page-test'],
            'Page backup',
        );
        $writer->beginProvider($provider->metadata());

        $provider->export(new BackupExportContext(
            $sourceScope,
            ['page-transfer'],
            ['page-transfer' => ['include_permissions' => false, 'include_history' => true]],
            1,
        ), $writer);
        $archive = $writer->finish();
        $reader = new BackupArchiveReader($archive, $backupConfig, $filesystem);

        $context = new BackupImportContext(
            new BackupScope(BackupScope::PAGE, '20'),
            BackupImportContext::CONFLICT_REPLACE,
            ['page-transfer'],
            options: ['page-transfer' => ['target_page_id' => 20, 'import_permissions' => false]],
            actorUserId: 1,
        );
        $context->state->map('editor.document-key', 'upute-izvor', 'upute-cilj');
        $this->assertTrue($provider->preflight($context, $reader)->isAllowed());
        $provider->import($context, $reader);

        $target = $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->where('id', '=', 20)->first();
        $this->assertIsArray($target);
        $this->assertSame('9c1eaf9d-3e21-4254-aa09-0c19222d68c7', $target['uuid']);
        $this->assertSame('stalni-url', $target['slug']);
        $this->assertSame(420, $target['sort_order']);
        $this->assertSame('Izvorna stranica', $target['title']);
        $labels = $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
            ->where('node_id', '=', 20)
            ->get();
        $this->assertSame(['izvorna-oznaka'], array_column($labels, 'label'));
        $this->assertNotNull($database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
            ->where('node_id', '=', 20)
            ->first());

        $permissionsContext = new BackupImportContext(
            new BackupScope(BackupScope::PAGE, '20'),
            BackupImportContext::CONFLICT_REPLACE,
            ['page-transfer'],
            options: ['page-transfer' => ['target_page_id' => 20, 'import_permissions' => true]],
            actorUserId: 1,
        );
        $this->assertFalse($provider->preflight($permissionsContext, $reader)->isAllowed());

        $copyContext = new BackupImportContext(
            new BackupScope(BackupScope::PAGE, '10'),
            BackupImportContext::CONFLICT_COPY,
            ['page-transfer'],
            options: ['page-transfer' => [
                'target_workspace' => 1,
                'target_parent_id' => 20,
                'import_permissions' => false,
            ]],
            actorUserId: 1,
        );
        $copyContext->state->map('editor.document-key', 'upute-izvor', 'upute-kopija');
        $this->assertTrue($provider->preflight($copyContext, $reader)->isAllowed());
        $provider->import($copyContext, $reader);

        $copy = $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('document_key', '=', 'upute-kopija')
            ->first();
        $this->assertIsArray($copy);
        $this->assertSame(1, $copy['workspace_id']);
        $this->assertSame(20, $copy['parent_id']);
        $this->assertSame('izvor-2', $copy['slug']);
        $reader->close();
    }

    public function testWithoutHistoryPreservesPublicationButDoesNotPublishNewerDraft(): void
    {
        [$database, $config] = $this->environment();
        $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'id' => 1, 'uuid' => '48e2e3cf-4721-48f3-8f24-c52b75ce84ee',
            'slug' => 'upute', 'name' => 'Upute', 'visibility' => 'public',
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'id' => 10, 'uuid' => 'd41d260b-7d6a-4e71-965b-c982180f62f6',
            'workspace_id' => 1, 'node_type' => 'document', 'slug' => 'izvor',
            'title' => 'Izvor', 'document_key' => 'upute-izvor', 'sort_order' => 100,
        ]);
        foreach (['hr' => 3, 'en' => 4] as $language => $current) {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert([
                'node_id' => 10, 'language_code' => $language, 'status' => 'published',
                'current_version_number' => $current, 'published_version_number' => 3,
            ]);
        }

        $filesystem = new BackupFilesystem();
        $provider = new WorkspacePageBackupProvider(
            $database,
            new AuthBackupIdentityResolver($database, new AuthUserService($database)),
            new WorkspaceRepository($database),
        );
        $scope = new BackupScope(BackupScope::PAGE, '10');
        $writer = new BackupArchiveWriter($config, $filesystem, $scope, [], 'No history');
        $writer->beginProvider($provider->metadata());

        $provider->export(new BackupExportContext(
            $scope,
            ['page-transfer'],
            ['page-transfer' => ['include_history' => false, 'include_permissions' => false]],
        ), $writer);
        $reader = new BackupArchiveReader($writer->finish(), $config, $filesystem);
        $context = new BackupImportContext(
            $scope,
            BackupImportContext::CONFLICT_COPY,
            ['page-transfer'],
            options: ['page-transfer' => ['target_workspace' => 1]],
        );
        $context->state->map('editor.document-key', 'upute-izvor', 'upute-kopija');

        $provider->import($context, $reader);
        $copy = $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('document_key', '=', 'upute-kopija')->first();
        $this->assertIsArray($copy);
        $rows = $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
            ->where('node_id', '=', $copy['id'])->get();
        $rows = array_column($rows, null, 'language_code');
        $this->assertSame('published', $rows['hr']['status']);
        $this->assertSame(3, $rows['hr']['published_version_number']);
        $this->assertSame('draft', $rows['en']['status']);
        $this->assertNull($rows['en']['published_version_number']);
        $reader->close();
    }

    /** @return array{Database,BackupConfig} */
    private function environment(): array
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/workspace-page-backup-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->temporaryRoot, 0770, true));
        $helper = new Helper();
        $config = new class ($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'backup' => [
                'archive_dir' => $this->temporaryRoot . '/archives',
                'staging_dir' => $this->temporaryRoot . '/staging',
                'upload_dir' => $this->temporaryRoot . '/uploads',
                'archive_suffix' => 'page-test.zip',
                'max_archive_size' => 10 * 1024 * 1024,
                'max_uncompressed_size' => 20 * 1024 * 1024,
                'max_archive_entries' => 100,
            ],
        ], $this->temporaryRoot) extends Config {
            /** @param array<string,mixed> $data */
            public function __construct(Helper $helper, array $data, private readonly string $root)
            {
                parent::__construct($helper, $data);
            }

            public function getAppRootDir(): string
            {
                return $this->root;
            }
        };
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);
        $database->execute(
            'CREATE TABLE auth_users ('
            . 'id INTEGER PRIMARY KEY, login_identifier TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)',
        );
        $composer = new class ($this->temporaryRoot) extends ComposerBridge {
            public function __construct(private readonly string $root)
            {
            }

            /** @return array<string,mixed> */
            public function getRootPackage(): array
            {
                return ['install_path' => $this->root];
            }
        };
        $configFile = (new ReflectionClass(BackupConfig::class))->getFileName();
        $this->assertIsString($configFile);

        return [$database, new BackupConfig($config, $composer, dirname($configFile, 3))];
    }
}
