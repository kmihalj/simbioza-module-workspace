<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\LocaleSorter;
use HeartPhrame\Localization\TranslatorInterface;

use function array_filter;
use function array_values;
use function is_array;
use function is_numeric;
use function is_scalar;
use function usort;

/**
 * HR: Sažima osobna područja u općim administratorskim popisima, dok ih
 *     specijalizirani modul i popis obrisanih stavki zadržavaju pojedinačno.
 * EN: Collapses personal Workspaces in general administration lists while
 *     their dedicated module and deleted-item list keep individual entries.
 */
final readonly class WorkspaceAdministrationListService
{
    /** HR: Prima prevoditelj za lokalizirani naziv i redoslijed. EN: Receives the translator for localized naming and ordering. */
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * HR: Vraća samo obična aktivna područja za opći administratorski popis.
     * EN: Returns only ordinary active Workspaces for the general admin list.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function regularWorkspaces(array $workspaces): array
    {
        return array_values(array_filter(
            $workspaces,
            static fn(array $workspace): bool => !(bool)($workspace['is_personal_workspace'] ?? false),
        ));
    }

    /**
     * HR: Zbraja statistike svih osobnih područja u jedan lokalizirani red.
     * EN: Aggregates every personal Workspace statistic into one localized row.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function maintenanceRows(array $workspaces): array
    {
        $rows = [];
        $personalStatistics = [];
        $personalCount = 0;
        foreach ($workspaces as $workspace) {
            if (!(bool)($workspace['is_personal_workspace'] ?? false)) {
                $rows[] = $workspace;
                continue;
            }

            ++$personalCount;
            $statistics = is_array($workspace['statistics'] ?? null) ? $workspace['statistics'] : [];
            foreach ($statistics as $key => $value) {
                if (is_numeric($value)) {
                    $name = (string)$key;
                    $personalStatistics[$name] = ($personalStatistics[$name] ?? 0) + (int)$value;
                }
            }
        }

        if ($personalCount > 0) {
            $rows[] = [
                'name' => $this->translator->trans('Osobna područja'),
                'statistics' => $personalStatistics,
                'is_personal_workspace_group' => true,
                'personal_workspace_count' => $personalCount,
            ];
        }

        $locale = $this->translator->getLocale();
        usort($rows, static function (array $left, array $right) use ($locale): int {
            $leftName = is_scalar($left['name'] ?? null) ? (string)$left['name'] : '';
            $rightName = is_scalar($right['name'] ?? null) ? (string)$right['name'] : '';

            return LocaleSorter::compare($leftName, $rightName, $locale);
        });

        return $rows;
    }
}
