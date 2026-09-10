<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAdministrationListService;
use HeartPhrame\Localization\TranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceAdministrationListService::class)]
final class WorkspaceAdministrationListServiceTest extends TestCase
{
    /** HR: Opći popis skriva osobna područja, a održavanje ih zbraja u jedan red. EN: The general list hides personal Workspaces while maintenance aggregates them. */
    public function testPersonalWorkspacesAreRemovedOrAggregatedByContext(): void
    {
        $service = new WorkspaceAdministrationListService($this->translator());
        $workspaces = [
            ['id' => 1, 'name' => 'Javno', 'statistics' => ['database_bytes' => 50]],
            [
                'id' => 2,
                'name' => 'Područje od: Ana',
                'is_personal_workspace' => true,
                'statistics' => ['database_bytes' => 20, 'history_versions' => 2],
            ],
            [
                'id' => 3,
                'name' => 'Područje od: Ivo',
                'is_personal_workspace' => true,
                'statistics' => ['database_bytes' => 30, 'history_versions' => 4],
            ],
        ];

        $this->assertSame([1], array_column($service->regularWorkspaces($workspaces), 'id'));
        $maintenance = $service->maintenanceRows($workspaces);
        $this->assertCount(2, $maintenance);
        $personal = array_values(array_filter(
            $maintenance,
            static fn(array $row): bool => (bool)($row['is_personal_workspace_group'] ?? false),
        ))[0];
        $this->assertSame('Osobna područja', $personal['name']);
        $this->assertSame(2, $personal['personal_workspace_count']);
        $this->assertSame(50, $personal['statistics']['database_bytes']);
        $this->assertSame(6, $personal['statistics']['history_versions']);
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }

            public function getLocale(): string
            {
                return 'hr';
            }

            public function setLocale(string $locale): void
            {
            }
        };
    }
}
