<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceConfigTest extends TestCase
{
    private string $appRoot;

    /**
     * HR: Priprema zaseban aplikacijski config direktorij za provjeru nasljeđivanja.
     * EN: Prepares an isolated application config directory for inheritance tests.
     */
    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/hph_workspace_config_' . uniqid();
        mkdir($this->appRoot . '/config', 0777, true);
        file_put_contents(
            $this->appRoot . '/config/workspace.php',
            "<?php return ["
            . "'defaults' => ['tree_visible' => false, 'contents_visible' => true],"
            . "'creation' => ['users' => [7, '8', 7, 0], 'groups' => [11, 'bad', 12]],"
            . "];",
        );
    }

    /**
     * HR: Uklanja samo privremene datoteke ovog testa.
     * EN: Removes only the temporary files created by this test.
     */
    protected function tearDown(): void
    {
        unlink($this->appRoot . '/config/workspace.php');
        rmdir($this->appRoot . '/config');
        rmdir($this->appRoot);
    }

    /**
     * HR: Stranica nadjačava područje, a područje nadjačava sistemski fallback.
     * EN: A page overrides its Workspace, and the Workspace overrides the system fallback.
     */
    public function testDisplayPoliciesResolveInPageWorkspaceSystemOrder(): void
    {
        $configData = ['app' => ['localization' => ['locale' => 'hr']]];
        $config = new class (new Helper(), $configData, $this->appRoot) extends Config {
            /**
             * @param array<string,mixed> $data
             */
            public function __construct(Helper $helper, array $data, private readonly string $appRoot)
            {
                parent::__construct($helper, $data);
            }

            public function getAppRootDir(): string
            {
                return $this->appRoot;
            }
        };
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));

        $this->assertSame([7, 8], $workspaceConfig->creatorUserIds());
        $this->assertSame([11, 12], $workspaceConfig->creatorGroupIds());
        $this->assertFalse($workspaceConfig->treeVisibleForWorkspace([]));
        $this->assertTrue($workspaceConfig->treeVisibleForWorkspace(['tree_visibility' => 'shown']));
        $this->assertFalse($workspaceConfig->treeVisibleForWorkspace(['tree_visibility' => 'hidden']));

        $this->assertTrue($workspaceConfig->contentsVisibleForPage([], null));
        $this->assertFalse($workspaceConfig->contentsVisibleForPage(
            ['contents_visibility' => 'hidden'],
            ['contents_visibility' => 'inherit'],
        ));
        $this->assertTrue($workspaceConfig->contentsVisibleForPage(
            ['contents_visibility' => 'hidden'],
            ['contents_visibility' => 'shown'],
        ));
        $this->assertFalse($workspaceConfig->contentsVisibleForPage(
            ['contents_visibility' => 'shown'],
            ['contents_visibility' => 'hidden'],
        ));
    }
}
