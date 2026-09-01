<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use function array_slice;
use function array_values;
use function ceil;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function range;

/**
 * HR: Razdvaja generički popis područja prema prezentacijskim oznakama drugih
 *     modula i straniči samo skup koji se stvarno prikazuje.
 * EN: Splits the generic Workspace list by presentation annotations supplied by
 *     other modules and paginates only the collection that is actually shown.
 */
final readonly class WorkspaceIndexService
{
    public const ITEMS_PER_PAGE = 25;

    /**
     * HR: Zadržava vlastito osobno područje u glavnom popisu, a tuđa dostupna
     *     osobna područja predaje zasebnom ACL-već-filtriranom izborniku.
     * EN: Keeps the actor's personal Workspace in the primary list and returns
     *     accessible personal Workspaces of other users in a separate menu.
     *
     * @param list<array<string,mixed>> $workspaces
     * @param array<string,mixed>|null $user
     * @return array{
     *     items:list<array<string,mixed>>,
     *     other_personal_workspaces:list<array<string,mixed>>,
     *     personal_workspace_count:int,
     *     personal_mode:bool,
     *     page:int,
     *     pages:int,
     *     total:int,
     *     from:int,
     *     to:int,
     *     page_numbers:list<int>
     * }
     */
    public function prepare(
        array $workspaces,
        ?array $user,
        bool $isAdministrator,
        bool $personalMode,
        int $requestedPage,
    ): array {
        $userId = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        $primary = [];
        $otherPersonal = [];
        $personal = [];

        foreach ($workspaces as $workspace) {
            if (!(bool)($workspace['is_personal_workspace'] ?? false)) {
                $primary[] = $workspace;
                continue;
            }

            $personal[] = $workspace;
            $ownerUserId = is_numeric($workspace['personal_workspace_owner_user_id'] ?? null)
            ? (int)$workspace['personal_workspace_owner_user_id']
            : 0;
            if ($userId > 0 && $ownerUserId === $userId) {
                if (!$isAdministrator) {
                    $primary[] = $workspace;
                }

                continue;
            }

            $otherPersonal[] = $workspace;
        }

        $personalMode = $isAdministrator && $personalMode;
        $selected = $personalMode ? $personal : $primary;
        $total = count($selected);
        $pages = $total > 0 ? (int)ceil($total / self::ITEMS_PER_PAGE) : 0;
        $page = $pages > 0 ? min(max(1, $requestedPage), $pages) : 1;
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;
        $items = array_values(array_slice($selected, $offset, self::ITEMS_PER_PAGE));
        $from = $items === [] ? 0 : $offset + 1;
        $to = $items === [] ? 0 : $offset + count($items);

        return [
            'items' => $items,
            'other_personal_workspaces' => $isAdministrator ? [] : $otherPersonal,
            'personal_workspace_count' => count($personal),
            'personal_mode' => $personalMode,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'from' => $from,
            'to' => $to,
            'page_numbers' => $this->pageNumbers($page, $pages),
        ];
    }

    /**
     * HR: Vraća mali prozor brojeva stranica kako stotine područja ne bi opet
     *     proizvele stotine navigacijskih elemenata.
     * EN: Returns a compact page-number window so hundreds of Workspaces cannot
     *     produce hundreds of navigation controls again.
     *
     * @return list<int>
     */
    private function pageNumbers(int $page, int $pages): array
    {
        if ($pages <= 0) {
            return [];
        }

        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($end - $start < 4) {
            $start = max(1, $end - 4);
            $end = min($pages, $start + 4);
        }

        return range($start, $end);
    }
}
