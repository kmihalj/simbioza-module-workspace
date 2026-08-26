<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspacePermanentlyDeleting;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_scalar;

/**
 * HR: Orkestrira pregled zauzeća i čišćenje područja, dok specijalizirani
 *     Editor servis ostaje jedini vlasnik HTML verzija i privitaka.
 * EN: Orchestrates Workspace storage reporting and cleanup while the
 *     specialized Editor service remains the sole owner of HTML versions and attachments.
 */
final readonly class WorkspaceMaintenanceService
{
    /** HR: Prima spremište područja i izolirani most prema Editor održavanju. EN: Receives Workspace storage and the isolated Editor-maintenance bridge. */
    public function __construct(
        private Database $database,
        private WorkspaceRepository $repository,
        private WorkspaceMaintenanceBridge $editor,
        private WorkspaceMenuService $menus,
        private WorkspaceThemeAssetLibrary $themeAssets,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * HR: Gradi cijelu nadzornu ploču jednim skupnim pozivom editoru.
     * EN: Builds the complete dashboard with one bulk editor call.
     *
     * @return array{workspaces: list<array<string, mixed>>, statistics: array<string, array<string, int>>}
     */
    public function dashboard(): array
    {
        $workspaces = $this->repository->tablesReady() ? $this->repository->allWorkspaces() : [];
        $scopes = ['site' => null];
        $protectedByScope = ['site' => $this->protectedVersionNumbers(0, true)];
        foreach ($workspaces as $workspace) {
            $id = WorkspaceValue::int($workspace['id'] ?? 0);
            $scopes['workspace:' . $id] = $this->documentKeys($id);
            $protectedByScope['workspace:' . $id] = $this->protectedVersionNumbers($id, false);
        }

        $statistics = $this->editor->statisticsForScopes($scopes, $protectedByScope);
        foreach ($workspaces as &$workspace) {
            $workspace['statistics'] = $statistics['workspace:' . WorkspaceValue::int($workspace['id'] ?? 0)] ?? [];
        }

        return ['workspaces' => $workspaces, 'statistics' => $statistics];
    }

    /**
     * HR: Pokreće izradu optimiziranih web-kopija postojećih slika.
     * EN: Starts generation of optimized web copies for existing images.
     *
     * @return array{documents: int, generated: int, skipped: int}
     */
    public function optimizeImages(): array
    {
        $result = $this->editor->optimizeImages();
        if ($result === []) {
            throw new RuntimeException(__(
                'HTML Editor nije dostupan pa optimizacija slika nije moguća.',
            ));
        }

        return $result;
    }

    /**
     * HR: Validira administratorski obrazac, čisti editor i zatim uklanja
     *     metapodatke onih Workspace čvorova čiji je dokument trajno uklonjen.
     * EN: Validates the administrator form, cleans the editor, and then removes
     *     metadata for Workspace nodes whose document was permanently purged.
     *
     * @return array<string, mixed>
     */
    public function clean(
        string $scope,
        int $workspaceId,
        string $historyPolicy,
        int $historyValue,
        int $deletedDays,
        int $actorUserId = 0,
    ): array {
        if (!in_array($scope, ['site', 'workspace'], true)) {
            throw new RuntimeException(__('Odaberite valjani opseg održavanja.'));
        }

        if (!in_array($historyPolicy, ['none', 'all', 'keep', 'older'], true)) {
            throw new RuntimeException(__('Odaberite valjano pravilo povijesti.'));
        }

        if ($historyPolicy === 'keep' && !in_array($historyValue, [3, 5, 10], true)) {
            throw new RuntimeException(__('Broj sačuvanih verzija mora biti 3, 5 ili 10.'));
        }

        if ($historyPolicy === 'older' && !in_array($historyValue, [10, 30, 90], true)) {
            throw new RuntimeException(__('Starost povijesti mora biti 10, 30 ili 90 dana.'));
        }

        if (!in_array($deletedDays, [0, 10, 30, 90], true)) {
            throw new RuntimeException(__('Starost obrisanih stavki mora biti 10, 30 ili 90 dana.'));
        }

        if ($historyPolicy === 'none' && $deletedDays === 0) {
            throw new RuntimeException(__('Odaberite barem jednu radnju održavanja.'));
        }

        $documentKeys = null;
        if ($scope === 'workspace') {
            $workspace = $this->repository->findWorkspaceById($workspaceId);
            if (!is_array($workspace)) {
                throw new RuntimeException(__('Odabrano područje nije pronađeno.'));
            }

            $documentKeys = $this->documentKeys($workspaceId);
        }

        $result = $this->editor->clean(
            $documentKeys,
            $historyPolicy,
            $historyValue,
            $deletedDays,
            $this->protectedVersionNumbers($workspaceId, $scope === 'site'),
        );
        if ($result === []) {
            throw new RuntimeException(__('HTML Editor nije dostupan pa održavanje sadržaja nije moguće.'));
        }

        $purgedKeys = $this->stringList($result['purged_document_keys'] ?? []);
        if ($purgedKeys !== []) {
            $result['purged_workspace_nodes'] = $this->purgeDisabledNodes(
                $scope === 'workspace' ? $workspaceId : null,
                $purgedKeys,
                $deletedDays,
                $actorUserId,
            );
        } else {
            $result['purged_workspace_nodes'] = 0;
        }

        return $result;
    }

    /**
     * HR: Nepovratno uklanja soft-obrisano područje. Dodatni moduli prvo
     *     dobivaju stabilan događaj za svoje podatke, zatim Editor uklanja
     *     dokumente i datoteke, a Workspace na kraju svoje retke i theme assete.
     * EN: Irreversibly removes a soft-deleted Workspace. Optional modules first
     *     receive a stable event for their data, then Editor removes documents
     *     and files, and Workspace finally removes its rows and theme assets.
     *
     * @return array<string, int>
     */
    public function permanentlyDeleteWorkspace(
        int $workspaceId,
        string $confirmedSlug,
        int $actorUserId,
    ): array {
        $workspace = $this->repository->findWorkspaceById($workspaceId, true);
        if (!is_array($workspace) || !(bool)($workspace['is_deleted'] ?? false)) {
            throw new RuntimeException(__('Obrisano područje nije pronađeno.'));
        }

        $slug = WorkspaceValue::string($workspace['slug'] ?? '');
        if ($slug === '' || $confirmedSlug !== $slug) {
            throw new RuntimeException(__('Za potvrdu trajnog brisanja upišite točan slug područja.'));
        }

        $nodeIds = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->select(['id'])
            ->where('workspace_id', '=', $workspaceId)
            ->get() as $row
        ) {
            $row = is_array($row) ? $row : [];
            $nodeId = WorkspaceValue::int($row['id'] ?? 0);
            if ($nodeId > 0) {
                $nodeIds[] = $nodeId;
            }
        }

        $documentKeys = $this->documentKeys($workspaceId);

        $this->events->dispatch(new WorkspacePermanentlyDeleting(
            $workspaceId,
            $slug,
            array_values(array_unique($nodeIds)),
            $documentKeys,
            $actorUserId,
        ));

        $editorResult = $this->editor->purgeDocuments($documentKeys);
        if ($documentKeys !== [] && $editorResult === []) {
            throw new RuntimeException(__(
                'HTML Editor nije dostupan pa dokumente područja nije moguće trajno ukloniti.',
            ));
        }

        $this->menus->deleteForWorkspace($workspace);
        $this->themeAssets->purgeWorkspace($workspaceId);
        $this->repository->permanentlyDeleteWorkspace($workspaceId);

        return [
            'purged_workspace' => 1,
            'purged_nodes' => count($nodeIds),
            'purged_documents' => WorkspaceValue::int($editorResult['purged_documents'] ?? 0),
            'purged_versions' => WorkspaceValue::int($editorResult['purged_versions'] ?? 0),
            'purged_assets' => WorkspaceValue::int($editorResult['purged_assets'] ?? 0),
            'failed_files' => WorkspaceValue::int($editorResult['failed_files'] ?? 0),
        ];
    }

    /**
     * HR: Vraća sve aktivne i onemogućene ključeve dokumenata nekog područja.
     * EN: Returns every active and disabled document key belonging to a Workspace.
     *
     * @return list<string>
     */
    private function documentKeys(int $workspaceId): array
    {
        if (!$this->repository->tablesReady() || $workspaceId <= 0) {
            return [];
        }

        $rows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)
            ->get();
        $keys = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $key = WorkspaceValue::string($row['document_key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * HR: Čuva radne i objavljene verzije na koje pokazuju Workspace workflowi.
     * EN: Protects working and published versions referenced by Workspace workflows.
     *
     * @return array<string, array<string, list<int>>>
     */
    private function protectedVersionNumbers(int $workspaceId, bool $allWorkspaces): array
    {
        if (!$this->repository->tablesReady()) {
            return [];
        }

        $nodesQuery = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES);
        if (!$allWorkspaces) {
            $nodesQuery->where('workspace_id', '=', $workspaceId);
        }

        $nodes = $nodesQuery->get();
        $keyByNode = [];
        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $key = WorkspaceValue::string($node['document_key'] ?? '');
            if ($nodeId > 0 && $key !== '') {
                $keyByNode[$nodeId] = $key;
            }
        }

        $result = [];
        foreach ($this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->get() as $workflow) {
            $workflow = is_array($workflow) ? $workflow : [];
            $key = $keyByNode[WorkspaceValue::int($workflow['node_id'] ?? 0)] ?? '';
            $language = WorkspaceValue::string($workflow['language_code'] ?? '');
            if ($key === '') {
                continue;
            }

            if ($language === '') {
                continue;
            }

            foreach (['current_version_number', 'published_version_number'] as $column) {
                $version = WorkspaceValue::int($workflow[$column] ?? 0);
                if ($version > 0) {
                    $result[$key][$language][] = $version;
                }
            }

            $result[$key][$language] = array_values(array_unique($result[$key][$language] ?? []));
        }

        return $result;
    }

    /**
     * HR: Uklanja samo dovoljno stare onemogućene čvorove čiji su dokumenti već trajno obrisani.
     * EN: Removes only old disabled nodes whose documents were already permanently purged.
     *
     * @param list<string> $documentKeys
     */
    private function purgeDisabledNodes(
        ?int $workspaceId,
        array $documentKeys,
        int $deletedDays,
        int $actorUserId,
    ): int {
        if ($deletedDays <= 0 || !$this->repository->tablesReady()) {
            return 0;
        }

        $cutoff = (new DateTimeImmutable())->modify('-' . $deletedDays . ' days')->getTimestamp();
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('is_enabled', '=', false);
        if ($workspaceId !== null) {
            $query->where('workspace_id', '=', $workspaceId);
        }

        $pages = [];
        foreach ($query->get() as $row) {
            $row = is_array($row) ? $row : [];
            $updatedAt = is_scalar($row['updated_at'] ?? null) ? strtotime((string)$row['updated_at']) : false;
            if (
                in_array(WorkspaceValue::string($row['document_key'] ?? ''), $documentKeys, true)
                && $updatedAt !== false
                && $updatedAt < $cutoff
            ) {
                $nodeId = WorkspaceValue::int($row['id'] ?? 0);
                $rowWorkspaceId = WorkspaceValue::int($row['workspace_id'] ?? 0);
                if ($nodeId > 0 && $rowWorkspaceId > 0) {
                    $pages[$nodeId] = [
                        'workspace_id' => $rowWorkspaceId,
                        'node_id' => $nodeId,
                        'document_key' => WorkspaceValue::string($row['document_key'] ?? ''),
                    ];
                }
            }
        }

        $pages = array_values($pages);
        if ($pages !== []) {
            $this->events->dispatch(new WorkspacePagesPermanentlyDeleting($pages, $actorUserId));
        }

        $nodeIds = array_values(array_unique(array_map(
            static fn (array $page): int => $page['node_id'],
            $pages,
        )));
        $this->database->transaction(static function (Database $database) use ($nodeIds): void {
            foreach ($nodeIds as $nodeId) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)->where('node_id', '=', $nodeId)->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->where('id', '=', $nodeId)->delete();
            }
        });
        return count($nodeIds);
    }

    /**
     * HR: Normalizira rezultat opcionalnog bridgea na jedinstven popis ključeva.
     * EN: Normalizes an optional bridge result to a unique key list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = WorkspaceValue::string($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }
}
