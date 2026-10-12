<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceConfig::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceConfigTest extends TestCase
{
    private string $appRoot;

    /**
     * EN: Prepares an isolated application config directory for inheritance tests.
     *
     * HR: Priprema zaseban aplikacijski config direktorij za provjeru nasljeđivanja.
     */
    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/hph_workspace_config_' . uniqid();
        mkdir($this->appRoot . '/config/workspace', 0777, true);
        file_put_contents(
            $this->appRoot . '/config/workspace/workspace.json',
            '{"defaults":{"tree_visible":false,"contents_visible":true},'
            . '"creation":{"users":[7,8,7],"groups":[11,12]}}',
        );
    }

    /**
     * EN: Removes only the temporary files created by this test.
     *
     * HR: Uklanja samo privremene datoteke ovog testa.
     */
    protected function tearDown(): void
    {
        unlink($this->appRoot . '/config/workspace/workspace.json');
        rmdir($this->appRoot . '/config/workspace');
        rmdir($this->appRoot . '/config');
        rmdir($this->appRoot);
    }

    /**
     * EN: A page overrides its Workspace, and the Workspace overrides the system fallback.
     *
     * HR: Stranica nadjačava područje, a područje nadjačava sistemski fallback.
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

    /**
     * EN: Shorts defaults accept all levels and title order.
     *
     * HR: Zadani Sažetci prihvaćaju sve razine i naslovni redoslijed.
     */
    public function testShortsDefaultsAcceptAllLevelsAndTitleOrder(): void
    {
        file_put_contents(
            $this->appRoot . '/config/workspace/workspace.json',
            '{"shorts":{"depth":"all","order":"title_desc"}}',
        );
        $config = new class (new Helper(), [], $this->appRoot) extends Config {
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

        $this->assertSame('all', $workspaceConfig->shortsDefaultDepth());
        $this->assertSame('title_desc', $workspaceConfig->shortsDefaultOrder());
    }

    /**
     * EN: Public-content priority follows the selected, default, English, and remaining locales.
     *
     * HR: Prioritet javnog sadržaja slijedi odabrani, zadani, engleski i ostale jezike.
     */
    public function testContentLanguagePriorityIsDeterministic(): void
    {
        $config = new Config(new Helper(), [
            'app' => [
                'localization' => [
                    'locale' => 'fr',
                    'supported_locales' => ['fr', 'it', 'de', 'hr'],
                ],
            ],
        ]);
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));

        $this->assertSame(
            ['it', 'fr', 'en', 'de', 'hr'],
            $workspaceConfig->contentLanguagePriority('it'),
        );
        $this->assertSame(
            ['pt-br', 'pt', 'fr', 'en', 'it', 'de', 'hr'],
            $workspaceConfig->contentLanguagePriority('pt-BR'),
        );
    }
}
