<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceIndexService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceIndexService::class)]
final class WorkspaceIndexServiceTest extends TestCase
{
    private WorkspaceIndexService $service;

    protected function setUp(): void
    {
        $this->service = new WorkspaceIndexService();
    }

    /** HR: Glavni popis ima fiksnu stranicu i ograničen prozor navigacije. EN: The primary list has a fixed page and compact navigation window. */
    public function testOrdinaryWorkspaceListIsPaginated(): void
    {
        $rows = [];
        for ($id = 1; $id <= 63; ++$id) {
            $rows[] = $this->workspace($id);
        }

        $result = $this->service->prepare($rows, ['id' => 5], false, false, 2);

        $this->assertCount(WorkspaceIndexService::ITEMS_PER_PAGE, $result['items']);
        $this->assertSame(26, $result['items'][0]['id']);
        $this->assertSame(63, $result['total']);
        $this->assertSame(3, $result['pages']);
        $this->assertSame(26, $result['from']);
        $this->assertSame(50, $result['to']);
        $this->assertSame([1, 2, 3], $result['page_numbers']);
    }

    /** HR: Administrator dobiva osobna područja samo u izričitom odvojenom prikazu. EN: An administrator receives personal Workspaces only in the explicit separate view. */
    public function testAdministratorPersonalViewIsSeparated(): void
    {
        $rows = [
            $this->workspace(1),
            $this->workspace(2, 20),
            $this->workspace(3),
            $this->workspace(4, 40),
        ];

        $ordinary = $this->service->prepare($rows, ['id' => 1], true, false, 1);
        $personal = $this->service->prepare($rows, ['id' => 1], true, true, 1);

        $this->assertSame([1, 3], array_column($ordinary['items'], 'id'));
        $this->assertSame([2, 4], array_column($personal['items'], 'id'));
        $this->assertSame(2, $ordinary['personal_workspace_count']);
        $this->assertTrue($personal['personal_mode']);
        $this->assertSame([], $personal['other_personal_workspaces']);
    }

    /** HR: Korisnikovo osobno područje ostaje među običnima, a samo već vidljiva tuđa ulaze u padajući izbornik. EN: The actor's personal Workspace remains among ordinary rows and only already-visible foreign spaces enter the dropdown. */
    public function testUserKeepsOwnPersonalWorkspaceAndGetsAccessibleForeignDropdown(): void
    {
        $rows = [
            $this->workspace(1),
            $this->workspace(2, 7),
            $this->workspace(3, 8),
        ];

        $result = $this->service->prepare($rows, ['id' => 7], false, false, 1);

        $this->assertSame([1, 2], array_column($result['items'], 'id'));
        $this->assertSame([3], array_column($result['other_personal_workspaces'], 'id'));
        $this->assertFalse($result['personal_mode']);
    }

    /** @return array<string,mixed> */
    private function workspace(int $id, int $ownerUserId = 0): array
    {
        $row = ['id' => $id, 'name' => 'Workspace ' . $id];
        if ($ownerUserId > 0) {
            $row['is_personal_workspace'] = true;
            $row['personal_workspace_owner_user_id'] = $ownerUserId;
        }

        return $row;
    }
}
