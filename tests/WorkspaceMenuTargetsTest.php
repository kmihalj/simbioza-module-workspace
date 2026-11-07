<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(WorkspaceMenuService::class)]
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepositoryRequestCache::class)]
#[UsesClass(WorkspaceValue::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
final class WorkspaceMenuTargetsTest extends TestCase
{
    /**
     * EN: Counts and pages only readable destinations, keeping private names and totals undisclosed.
     *
     * HR: Broji i straniči samo čitljiva odredišta, bez otkrivanja privatnih naziva i ukupnih brojeva.
     */
    public function testAclFilteringPrecedesPagingAndSearch(): void
    {
        require_once __DIR__ . '/Fixtures/OptionalMenuCatalog.php';
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'app' => ['modules' => ['enabled' => ['aaieduhr/heartphrame-module-menu']]],
        ]);
        $database = new Database($config, $helper);
        $database->schema()->create('auth_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('login_identifier');
            $table->boolean('is_active')->default(true);
        });
        $database->schema()->create('auth_user_groups', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('group_id');
        });
        foreach ([1, 2] as $id) {
            $database->table('auth_users')->insert(['id' => $id, 'login_identifier' => 'user' . $id]);
        }

        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $migration->up($database);

        $repository = new WorkspaceRepository($database);
        $managed = $repository->saveWorkspace(
            ['name' => 'Managed', 'slug' => 'managed', 'visibility' => 'restricted'],
            2,
        );
        $secret = $repository->saveWorkspace(
            ['name' => 'SECRET', 'slug' => 'secret', 'visibility' => 'restricted'],
            1,
        );
        $targets = [['id' => 'workspace.' . $secret['id'], 'kind' => 'workspace',
            'workspace_id' => $secret['id'], 'label' => 'SECRET']];
        $targets[] = ['id' => 'workspace.' . $managed['id'], 'kind' => 'workspace',
            'workspace_id' => $managed['id'], 'label' => 'Managed'];
        for ($number = 1; $number <= 30; ++$number) {
            $node = $repository->saveNode((int)$managed['id'], [
                'title' => 'Page ' . $number, 'node_type' => 'internal_link', 'target_url' => '/example/' . $number,
            ], 2);
            $targets[] = ['id' => 'workspace.' . $managed['id'] . '.page.' . $node['id'], 'kind' => 'page',
                'workspace_id' => $managed['id'], 'label' => 'Managed / Page ' . $number,
                'item_label' => 'Page ' . $number];
        }

        $hiddenNode = $repository->saveNode((int)$secret['id'], [
            'title' => 'SECRET PAGE', 'node_type' => 'internal_link', 'target_url' => '/secret',
        ], 1);
        array_unshift($targets, ['id' => 'workspace.' . $secret['id'] . '.page.' . $hiddenNode['id'],
            'kind' => 'page', 'workspace_id' => $secret['id'], 'label' => 'SECRET PAGE']);
        $deniedNode = $repository->saveNode((int)$managed['id'], [
            'title' => 'DENIED PAGE', 'node_type' => 'document', 'document_key' => 'denied-page',
        ], 1);
        $repository->replaceNodeAcl((int)$managed['id'], (int)$deniedNode['id'], [
            WorkspaceRepository::SUBJECT_USER => [2 => ['can_view' => false, 'can_add' => false,
                'can_edit' => false, 'can_publish' => false, 'can_delete' => false, 'can_manage' => false]],
        ]);
        array_unshift($targets, ['id' => 'workspace.' . $managed['id'] . '.page.' . $deniedNode['id'],
            'kind' => 'page', 'workspace_id' => $managed['id'], 'label' => 'DENIED PAGE']);
        $catalog = new class ($targets) {
            public function __construct(private readonly array $items)
            {
            }

            public function targets(): array
            {
                return $this->items;
            }
        };
        $container = new class ($catalog) implements ContainerInterface {
            public function __construct(private readonly object $catalog)
            {
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function get(string $id): object
            {
                return $this->catalog;
            }
        };
        $bridge = $this->createStub(ComposerBridge::class);
        $bridge->method('isInstalled')->willReturn(true);
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
        $service = new WorkspaceMenuService(
            $container,
            $bridge,
            $workspaceConfig,
            $repository,
            $this->createStub(UrlGenerator::class),
        );
        $authn = $this->createStub(AuthnHandlerInterface::class);
        $authn->method('userData')->willReturn(['id' => 2, 'is_admin' => false]);
        $access = new WorkspaceAccessService(
            $repository,
            $authn,
            $workspaceConfig,
            new WorkspaceWorkflowService($repository),
        );
        $first = $service->targetPage($access, 'page', (int)$managed['id'], '', 1, 500);
        $second = $service->targetPage($access, 'page', (int)$managed['id'], '', 2, 25);
        $this->assertCount(25, $first['items']);
        $this->assertSame(30, $first['total']);
        $this->assertTrue($first['hasMore']);
        $this->assertCount(5, $second['items']);
        $this->assertFalse($second['hasMore']);
        $this->assertSame('Page 26', $second['items'][0]['picker_label']);
        $this->assertSame(0, $service->targetPage($access, 'page', 0, 'SECRET')['total']);
        $this->assertSame(0, $service->targetPage($access, 'page', (int)$managed['id'], 'DENIED')['total']);
        $this->assertSame(0, $service->targetPage($access, 'page', (int)$secret['id'])['total']);
        $this->assertSame(['Managed'], array_column($service->targetPage($access, 'workspace')['items'], 'label'));
        $this->assertSame(1, $service->targetPage($access, 'page', (int)$managed['id'], 'Page 30')['total']);
    }
}
