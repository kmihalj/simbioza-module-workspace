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
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceMenuServiceTest extends TestCase
{
    /**
     * HR: Server odbacuje klijentov ID i patterne te meni zaključava na zadano područje.
     * EN: The server discards client IDs and patterns and locks the menu to its Workspace.
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
            ['id' => 'workspace.42.left'],
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
}
