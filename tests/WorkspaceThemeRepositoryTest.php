<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceThemeRepository::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceThemeRepositoryTest extends TestCase
{
    /**
     * HR: Područje bez retka nasljeđuje sustav, a sistemski izbor ne kopira JSON temu.
     * EN: A workspace without a row inherits the system, while a system selection does not copy theme JSON.
     */
    public function testDefaultAndSystemSelectionsDoNotStorePrivateThemeData(): void
    {
        $repository = new WorkspaceThemeRepository($this->database());

        $this->assertSame(WorkspaceThemeRepository::SELECTION_DEFAULT, $repository->forWorkspace(7)['selection_type']);

        $repository->save(7, WorkspaceThemeRepository::SELECTION_SYSTEM, 'simbioza', 'dark', null, 21);
        $state = $repository->forWorkspace(7);

        $this->assertSame(WorkspaceThemeRepository::SELECTION_SYSTEM, $state['selection_type']);
        $this->assertSame('simbioza', $state['source_theme_id']);
        $this->assertSame('dark', $state['mode_policy']);
        $this->assertNull($state['theme']);

        $repository->delete(7);
        $this->assertSame(WorkspaceThemeRepository::SELECTION_DEFAULT, $repository->forWorkspace(7)['selection_type']);
    }

    /**
     * HR: Privatna tema ostaje izolirana u retku svojeg područja i može se naknadno ažurirati.
     * EN: A private theme remains isolated in its workspace row and can be updated later.
     */
    public function testPrivateThemesAreStoredPerWorkspace(): void
    {
        $repository = new WorkspaceThemeRepository($this->database());
        $first = ['id' => 'workspace-7', 'label' => ['hr' => 'Simbioza – Klub']];
        $second = ['id' => 'workspace-8', 'label' => ['hr' => 'Simbioza – Uprava']];

        $repository->save(7, WorkspaceThemeRepository::SELECTION_CUSTOM, 'simbioza', 'auto', $first, 21);
        $repository->save(8, WorkspaceThemeRepository::SELECTION_CUSTOM, 'simbioza', 'light', $second, 21);

        $this->assertSame($first, $repository->forWorkspace(7)['theme']);
        $this->assertSame($second, $repository->forWorkspace(8)['theme']);

        $first['label']['en'] = 'Symbiosis – Club';
        $repository->save(7, WorkspaceThemeRepository::SELECTION_CUSTOM, 'simbioza', 'dark', $first, 22);

        $this->assertSame($first, $repository->forWorkspace(7)['theme']);
        $this->assertSame('dark', $repository->forWorkspace(7)['mode_policy']);
        $this->assertSame($second, $repository->forWorkspace(8)['theme']);
    }

    /**
     * HR: Priprema prijenosnu memorijsku bazu samo s tablicom tema područja.
     * EN: Prepares a portable in-memory database containing only the workspace-theme table.
     */
    private function database(): Database
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
        $migration->up($database);

        return $database;
    }
}
