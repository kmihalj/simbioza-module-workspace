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
     * EN: Verifies that the single initial migration creates the complete portable Workspace schema on SQLite.
     *
     * HR: Provjerava da jedina početna migracija na SQLiteu kreira cijelu prijenosnu Workspace shemu.
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
                    'purge_started_at',
                    'purge_total_items',
                    'purge_completed_items',
                ],
            ),
        );
        $this->assertFalse($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS));
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
                    'is_tree_hidden',
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
}
