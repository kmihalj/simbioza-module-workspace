<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

#[CoversClass(WorkspaceMenuService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceMenuServiceTest extends TestCase
{
    /**
     * EN: The server discards client IDs and patterns and locks the menu to its Workspace.
     *
     * HR: Server odbacuje klijentov ID i patterne te meni zaključava na zadano područje.
     */
    public function testPostedContextCannotEscapeWorkspaceScope(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'app' => [
                'modules' => [
                    'enabled' => ['aaieduhr/heartphrame-module-menu'],
                ],
            ],
        ]);
        $service = new WorkspaceMenuService(
            $this->createStub(ContainerInterface::class),
            $this->createStub(ComposerBridge::class),
            new WorkspaceConfig($config, dirname(__DIR__)),
            new WorkspaceRepository(new \AaiEduHr\HeartPhrameModuleOrm\Database\Database($config, $helper)),
            $this->createStub(UrlGenerator::class),
        );
        $method = new ReflectionMethod($service, 'lockedContext');

        $locked = $method->invoke(
            $service,
            ['id' => 42, 'slug' => 'qa-finance', 'name' => 'QA Finance'],
            [
                'id' => 'forged',
                'original_id' => 'forged',
                'route_patterns' => 'admin.*',
                'path_patterns' => '/settings/*',
                'label' => ['hr' => 'Krivotvoreno'],
                'left_enabled' => '1',
            ],
            ['id' => 'workspace.42.left', 'owner_target' => 'workspace.42'],
            ['hr' => 'QA Finance', 'en' => 'QA Finance'],
        );

        $this->assertIsArray($locked);
        $this->assertSame('workspace.42.left', $locked['id']);
        $this->assertSame('workspace.42.left', $locked['original_id']);
        $this->assertSame('', $locked['route_patterns']);
        $this->assertSame("/workspace/qa-finance\n/workspace/qa-finance/*", $locked['path_patterns']);
        $this->assertSame(['hr' => 'QA Finance', 'en' => 'QA Finance'], $locked['label']);
        $this->assertSame('1', $locked['left_enabled']);
    }

    /**
     * EN: Permanent cleanup removes exact page owners without GUI and all descendants for a workspace.
     * Shared URL matches and similarly numbered other workspaces remain untouched.
     *
     * HR: Trajno čišćenje uklanja točne vlasnike stranica bez GUI-a i sve potomke područja.
     * Zajednički URL konteksti i druga područja sličnog broja ostaju netaknuti.
     */
    public function testPermanentCleanupUsesOwnersAndPreservesSharedContexts(): void
    {
        require_once __DIR__ . '/Fixtures/OptionalMenuRepository.php';

        $contexts = [
            ['id' => 'workspace.42.top', 'owner_target' => 'workspace.42'],
            ['id' => 'workspace.42.left', 'owner_target' => 'workspace.42'],
            ['id' => 'custom-page', 'owner_target' => 'workspace.42.page.8'],
            ['id' => 'another-page', 'owner_target' => 'workspace.42.page.9'],
            ['id' => 'shared', 'path_patterns' => ['/workspace/owned/*']],
            ['id' => 'other-owner', 'owner_target' => 'workspace.420.page.8'],
        ];
        $files = new class ($contexts) {
            public int $writes = 0;

            public function __construct(public array $contexts)
            {
            }

            public function read(): array
            {
                return $this->contexts;
            }

            public function write(array $contexts): void
            {
                $this->contexts = $contexts;
                ++$this->writes;
            }
        };
        $repository = new class ($files) {
            public function __construct(private readonly object $files)
            {
            }

            public function contextFiles(): object
            {
                return $this->files;
            }
        };
        $container = new class ($repository) implements ContainerInterface {
            public function __construct(private readonly object $repository)
            {
            }

            public function has(string $id): bool
            {
                return str_ends_with($id, '\\MenuConfigRepository');
            }

            public function get(string $id): object
            {
                if (!$this->has($id)) {
                    throw new \RuntimeException('No optional Menu GUI service in this test.');
                }

                return $this->repository;
            }
        };
        $helper = new Helper();
        $config = new Config($helper, ['app' => ['modules' => ['enabled' => ['aaieduhr/heartphrame-module-menu']]]]);
        $composerBridge = $this->createStub(ComposerBridge::class);
        $composerBridge->method('isInstalled')->willReturn(true);
        $service = new WorkspaceMenuService(
            $container,
            $composerBridge,
            new WorkspaceConfig($config, dirname(__DIR__)),
            new WorkspaceRepository(new \AaiEduHr\HeartPhrameModuleOrm\Database\Database($config, $helper)),
            $this->createStub(UrlGenerator::class),
        );
        $this->assertFalse($service->isAvailable());
        $service->deleteForPages([['workspace_id' => 42, 'node_id' => 8]]);
        $this->assertSame(
            ['workspace.42.top', 'workspace.42.left', 'another-page', 'shared', 'other-owner'],
            array_column($files->contexts, 'id'),
        );
        $service->deleteForWorkspace(['id' => 42, 'slug' => 'owned']);
        $this->assertSame(['shared', 'other-owner'], array_column($files->contexts, 'id'));
        $service->deleteForWorkspace(['id' => 42, 'slug' => 'owned']);
        $this->assertSame(2, $files->writes);
    }
}
