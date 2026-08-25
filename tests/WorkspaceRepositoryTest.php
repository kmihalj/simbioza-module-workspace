<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\QueryExecuted;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepositoryRequestCache;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceContentChanged::class)]
#[UsesClass(WorkspacePagesPermanentlyDeleting::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceRepositoryTest extends TestCase
{
    /**
     * HR: Pronalaženje po slugu puni request cache po ID-u, a promjena ga odmah poništava.
     * EN: A slug lookup primes the request cache by ID, and a mutation invalidates it immediately.
     */
    public function testNodeRequestCacheAvoidsRepeatedReadsAndInvalidatesAfterMutation(): void
    {
        $database = $this->database();
        $repository = new WorkspaceRepository(
            $database,
            requestCache: new WorkspaceRepositoryRequestCache(),
        );
        $now = '2026-08-25 16:00:00';
        $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'id' => 1,
            'uuid' => '10000000-0000-4000-8000-000000000001',
            'slug' => 'cache',
            'name' => 'Cache',
            'visibility' => 'public',
            'owner_user_id' => 1,
            'is_archived' => false,
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'id' => 1,
            'uuid' => '10000000-0000-4000-8000-000000000002',
            'workspace_id' => 1,
            'node_type' => 'document',
            'slug' => 'page',
            'title' => 'Page',
            'document_key' => 'page',
            'sort_order' => 100,
            'is_homepage' => true,
            'is_enabled' => true,
            'contents_visibility' => 'inherit',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $nodeSelects = 0;
        $ancestorSelects = 0;
        $database->listen(static function (QueryExecuted $query) use (&$nodeSelects, &$ancestorSelects): void {
            if (str_starts_with($query->sql, 'SELECT * FROM "workspace_nodes"')) {
                ++$nodeSelects;
            }

            if (
                str_contains($query->sql, '"workspace_id" = ?')
                && str_contains($query->sql, '"id" = ?')
            ) {
                ++$ancestorSelects;
            }
        });

        $this->assertIsArray($repository->findNodeBySlug(1, 'page'));
        $this->assertIsArray($repository->findNodeById(1));
        $this->assertIsArray($repository->findNodeById(1));
        $this->assertSame(1, $nodeSelects);
        $this->assertCount(1, $repository->ancestorNodes(1, 1));
        $this->assertCount(1, $repository->ancestorNodes(1, 1));
        $this->assertSame(1, $ancestorSelects);

        $repository->updateNodeContentsVisibility(1, 'shown', 1);
        $updated = $repository->findNodeById(1);
        $this->assertIsArray($updated);
        $this->assertSame('shown', $updated['contents_visibility']);
        $this->assertCount(1, $repository->ancestorNodes(1, 1));
        $this->assertSame(4, $nodeSelects);
        $this->assertSame(2, $ancestorSelects);
    }

    /**
     * HR: Dokazuje da se trajno može ukloniti samo soft-obrisano područje te
     *     da svi Workspace odnosi nestaju bez diranja drugih područja.
     * EN: Proves that only a soft-deleted Workspace can be permanently removed
     *     and that all Workspace-owned relations disappear without touching another Workspace.
     */
    public function testPermanentlyDeletingWorkspaceRemovesOwnedRelations(): void
    {
        $database = $this->database();
        $repository = new WorkspaceRepository($database);
        $now = '2026-08-21 10:00:00';
        foreach ([[1, 'remove', true], [2, 'keep', false]] as [$id, $slug, $deleted]) {
            $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
                'id' => $id,
                'uuid' => sprintf('20000000-0000-4000-8000-%012d', $id),
                'slug' => $slug,
                'name' => ucfirst($slug),
                'visibility' => 'restricted',
                'owner_user_id' => 1,
                'is_archived' => false,
                'is_deleted' => $deleted,
                'deleted_at' => $deleted ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)->insert([
                'workspace_id' => $id,
                'subject_type' => WorkspaceRepository::SUBJECT_USER,
                'subject_id' => 1,
                'can_view' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
                'id' => $id,
                'uuid' => sprintf('30000000-0000-4000-8000-%012d', $id),
                'workspace_id' => $id,
                'node_type' => 'document',
                'slug' => 'page-' . $id,
                'title' => 'Page ' . $id,
                'document_key' => 'document-' . $id,
                'sort_order' => 100,
                'is_homepage' => true,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert([
            'node_id' => 1,
            'language_code' => 'hr',
            'status' => 'published',
            'current_version_number' => 1,
            'published_version_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)->insert([
            'workspace_id' => 1,
            'selection_type' => 'default',
            'mode_policy' => 'auto',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)->insert([
            'user_id' => 9,
            'node_id' => 1,
            'target_type' => 'page',
            'workspace_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([1, 2] as $nodeId) {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
                'node_id' => $nodeId,
                'label' => 'file-list',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $repository->permanentlyDeleteWorkspace(1);

        $this->assertNull($repository->findWorkspaceById(1, true));
        $this->assertNotNull($repository->findWorkspaceById(2));
        $this->assertSame([], $database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', 1)->get());
        $this->assertSame([], $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', 1)->get());
        $this->assertSame([], $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
            ->where('node_id', '=', 1)->get());
        $this->assertSame([], $repository->nodeLabels(1));
        $this->assertSame(['file-list'], $repository->nodeLabels(2));
        $this->assertSame([], $database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', 1)->get());
        $this->assertSame([], $database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
            ->where('workspace_id', '=', 1)->get());
        $this->assertCount(1, $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', 2)->get());
    }

    /** HR: Oznake se normaliziraju, dohvaćaju skupno i filtriraju stranice područja. EN: Labels are normalized, batch-loaded, and filter Workspace pages. */
    public function testStoresAndQueriesPageLabels(): void
    {
        $database = $this->database();
        $repository = new WorkspaceRepository($database);
        $now = '2026-08-21 16:00:00';
        $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'id' => 1,
            'uuid' => '40000000-0000-4000-8000-000000000001',
            'slug' => 'test',
            'name' => 'Test',
            'visibility' => 'public',
            'owner_user_id' => 1,
            'is_archived' => false,
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([1, 2] as $nodeId) {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
                'id' => $nodeId,
                'uuid' => sprintf('50000000-0000-4000-8000-%012d', $nodeId),
                'workspace_id' => 1,
                'node_type' => 'document',
                'slug' => 'page-' . $nodeId,
                'title' => 'Page ' . $nodeId,
                'document_key' => 'page-' . $nodeId,
                'sort_order' => $nodeId * 100,
                'is_homepage' => false,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $repository->replaceNodeLabels(1, [' File-List ', 'file-list', 'news']);
        $repository->replaceNodeLabels(2, ['news']);

        $this->assertSame(['file-list', 'news'], $repository->nodeLabels(1));
        $this->assertSame([1 => ['file-list', 'news'], 2 => ['news']], $repository->nodeLabelsForNodes([1, 2]));
        $this->assertSame([1], array_column($repository->nodesWithLabel(1, 'file-list'), 'id'));
    }

    /**
     * HR: Trajno brisanje neobjavljene stranice uklanja sve njezine pomoćne
     *     retke, dok izravnu djecu čuva premještanjem na istog roditelja.
     * EN: Permanent deletion of an unpublished page removes all of its
     *     supporting rows while preserving direct children under its parent.
     */
    public function testPermanentlyDeletingUnpublishedNodeRemovesRelationsAndReparentsChildren(): void
    {
        $database = $this->database();
        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $repository = new WorkspaceRepository($database, $dispatcher);
        $now = '2026-07-18 21:00:00';
        $database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'slug' => 'test',
            'name' => 'Test',
            'visibility' => 'restricted',
            'owner_user_id' => 1,
            'is_archived' => false,
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'workspace_id' => 1,
            'parent_id' => null,
            'node_type' => 'document',
            'slug' => 'novi-nacrt',
            'title' => 'Novi nacrt',
            'document_key' => 'novi-nacrt',
            'sort_order' => 100,
            'is_homepage' => false,
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000003',
            'workspace_id' => 1,
            'parent_id' => 1,
            'node_type' => 'document',
            'slug' => 'dijete',
            'title' => 'Dijete',
            'document_key' => 'dijete',
            'sort_order' => 100,
            'is_homepage' => false,
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)->insert([
            'node_id' => 1,
            'subject_type' => WorkspaceRepository::SUBJECT_USER,
            'subject_id' => 1,
            'can_view' => true,
            'can_add' => true,
            'can_edit' => true,
            'can_publish' => true,
            'can_delete' => true,
            'can_manage' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert([
            'node_id' => 1,
            'language_code' => 'hr',
            'status' => 'draft',
            'current_version_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $repository->deleteUnpublishedNodePermanently(1, 1, 1);

        $this->assertNull($repository->findNodeById(1));
        $this->assertNull($repository->nodeWorkflow(1, 'hr'));
        $this->assertSame([], $repository->nodeAclRows(1));
        $child = $repository->findNodeById(2);
        $this->assertIsArray($child);
        $this->assertNull($child['parent_id'] ?? null);
        $event = $dispatcher->events[0] ?? null;
        $this->assertInstanceOf(WorkspacePagesPermanentlyDeleting::class, $event);
        $this->assertSame([[
            'workspace_id' => 1,
            'node_id' => 1,
            'document_key' => 'novi-nacrt',
        ]], $event->pages);
    }

    /**
     * HR: Priprema prijenosnu SQLite bazu s aktualnom inicijalnom Workspace shemom.
     * EN: Prepares a portable SQLite database with the current initial Workspace schema.
     */
    private function database(): Database
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

        return $database;
    }
}
