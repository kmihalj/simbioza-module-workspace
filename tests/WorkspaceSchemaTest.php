<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspaceSchemaTest extends TestCase
{
    /**
     * HR: Provjerava da jedina početna migracija na SQLiteu kreira cijelu prijenosnu Workspace shemu.
     * EN: Verifies that the single initial migration creates the complete portable Workspace schema on SQLite.
     */
    public function testInitialMigrationCreatesCompletePortableSchema(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';

        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        $schema = $database->schema();
        foreach (
            [
                ModuleWorkspace::TABLE_WORKSPACES,
                ModuleWorkspace::TABLE_WORKSPACE_ACL,
                ModuleWorkspace::TABLE_WORKSPACE_NODES,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS,
                ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                ModuleWorkspace::TABLE_WORKSPACE_THEMES,
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS,
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE,
            ] as $table
        ) {
            $this->assertTrue($schema->hasTable($table), $table . ' was not created.');
        }

        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_THEMES,
                ['workspace_id', 'selection_type', 'source_theme_id', 'mode_policy', 'theme_json'],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACES,
                [
                    'slug',
                    'visibility',
                    'tree_visibility',
                    'contents_visibility',
                    'is_archived',
                    'is_deleted',
                ],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                [
                    'public_node_id',
                    'public_target_type',
                    'public_workspace_id',
                    'public_show_tree',
                    'public_show_display_options',
                    'authenticated_node_id',
                    'authenticated_target_type',
                    'authenticated_workspace_id',
                    'authenticated_show_tree',
                    'authenticated_show_display_options',
                    'allow_user_selection',
                ],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                [
                    'user_id',
                    'node_id',
                    'target_type',
                    'workspace_id',
                    'show_tree',
                    'show_display_options',
                ],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_NODES,
                [
                    'workspace_id',
                    'parent_id',
                    'node_type',
                    'document_key',
                    'sort_order',
                    'contents_visibility',
                ],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_ACL,
                ['workspace_id', 'subject_type', 'subject_id', 'can_publish', 'can_manage'],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL,
                ['node_id', 'subject_type', 'subject_id', 'can_view', 'can_publish', 'can_manage'],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS,
                ['node_id', 'user_id', 'can_view', 'can_edit', 'can_publish'],
            ),
        );
        $this->assertTrue(
            $schema->hasColumns(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS,
                [
                    'node_id',
                    'language_code',
                    'status',
                    'current_version_number',
                    'published_version_number',
                ],
            ),
        );

        $migration->down($database);
        $this->assertFalse($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES));
    }

    /**
     * HR: Provjerava samostalnu nadogradnju za izravna prava postojećih instalacija.
     * EN: Verifies the standalone direct-permission upgrade for existing installations.
     */
    public function testDirectPermissionUpgradeMigrationIsPortableAndReversible(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__)
        . '/resources/migrations/20260826130000_add_workspace_node_direct_permissions.php';

        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);
        $this->assertTrue(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS),
        );

        $migration->down($database);
        $this->assertFalse(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS),
        );
    }

    /**
     * HR: Provjerava da zasebna nadogradnja radi na postojećoj instalaciji i da je reverzibilna.
     * EN: Verifies that the standalone upgrade works on an existing installation and is reversible.
     */
    public function testHomepageUpgradeMigrationIsPortableAndReversible(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__)
        . '/resources/migrations/add_workspace_homepage_preferences.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);

        $migration->up($database);
        $this->assertTrue(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS),
        );
        $this->assertTrue(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES),
        );

        $migration->down($database);
        $this->assertFalse(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS),
        );
    }

    /**
     * HR: Nadogradnja tema područja radi samostalno i može se sigurno vratiti.
     * EN: The workspace-theme upgrade works independently and can be safely rolled back.
     */
    public function testWorkspaceThemeUpgradeMigrationIsPortableAndReversible(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/add_workspace_themes.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);

        $migration->up($database);
        $this->assertTrue($database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_THEMES));
        $migration->down($database);
        $this->assertFalse($database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_THEMES));
    }

    /**
     * HR: Nadogradnja backlinkova dodaje samo izvedeni indeks i može se vratiti.
     * EN: The backlink upgrade adds only the derived index and can be rolled back.
     */
    public function testBacklinkUpgradeMigrationIsPortableAndReversible(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/add_workspace_backlinks.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);

        $migration->up($database);
        $this->assertTrue($database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS));
        $this->assertTrue(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE),
        );
        $migration->down($database);
        $this->assertFalse($database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS));
        $this->assertFalse(
            $database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE),
        );
    }

    /**
     * HR: Nadogradnja opcija radi nad starim tablicama bez gubitka odabira stranica.
     * EN: The view-options upgrade works on legacy tables without losing page selections.
     */
    public function testHomepageViewOptionsUpgradePreservesLegacyTables(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $schema = $database->schema();
        $schema->create(
            ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
            static function (\AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->bigInteger('public_node_id')->unsigned()->nullable();
                $table->bigInteger('authenticated_node_id')->unsigned()->nullable();
                $table->boolean('allow_user_selection')->default(true);
                $table->timestamps();
            },
        );
        $schema->create(
            ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
            static function (\AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->bigInteger('user_id')->unsigned()->unique();
                $table->bigInteger('node_id')->unsigned();
                $table->timestamps();
            },
        );
        $migration = require dirname(__DIR__)
        . '/resources/migrations/add_workspace_homepage_view_options.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);

        $migration->up($database);

        $this->assertTrue($schema->hasColumn(
            ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
            'public_show_display_options',
        ));
        $this->assertTrue($schema->hasColumn(
            ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
            'show_display_options',
        ));
        $migration->down($database);
        $this->assertFalse($schema->hasColumn(
            ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
            'public_target_type',
        ));
    }
}
