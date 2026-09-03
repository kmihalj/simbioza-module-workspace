<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

use function array_chunk;
use function array_values;
use function date;
use function is_array;
use function is_int;
use function strtolower;
use function strtotime;
use function time;
use function trim;

/**
 * HR: Održava izvedeni indeks internih poveznica iz objavljenih verzija.
 *     Indeks ne sadrži ACL odluke i može se u svakom trenutku ponovno izgraditi.
 * EN: Maintains a derived internal-link index from published versions. The
 *     index contains no ACL decisions and can be rebuilt at any time.
 */
final class WorkspaceBacklinkIndexer
{
    private int $lastRefreshTimestamp = 0;

    /** HR: Prima izvore stranica, objava i HTML-a. EN: Receives page, publication, and HTML sources. */
    public function __construct(
        private readonly Database $database,
        private readonly WorkspaceRepository $repository,
        private readonly WorkspaceWorkflowService $workflow,
        private readonly WorkspaceEditorBridge $editor,
        private readonly WorkspaceLinkExtractor $extractor,
        private readonly WorkspaceConfig $config,
    ) {
    }

    /**
     * HR: Lijeno obnavlja zastarjeli indeks; oznaka u bazi koordinira PHP procese.
     * EN: Lazily rebuilds a stale index; the database marker coordinates PHP processes.
     */
    public function refreshIfDue(): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        $interval = $this->config->backlinkRefreshSeconds();
        if (time() - $this->lastRefreshTimestamp < $interval) {
            return;
        }

        $state = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE)
            ->where('id', '=', 1)
            ->first();
        $rebuiltAt = is_array($state)
        ? strtotime(WorkspaceValue::string($state['rebuilt_at'] ?? ''))
        : false;
        if (is_int($rebuiltAt) && $rebuiltAt > 0 && time() - $rebuiltAt < $interval) {
            $this->lastRefreshTimestamp = $rebuiltAt;

            return;
        }

        $this->rebuild();
    }

    /**
     * HR: Atomski ponovno gradi sve backlinkove iz stvarno objavljenog sadržaja.
     * EN: Atomically rebuilds all backlinks from actually published content.
     *
     * @return array{indexed:int}
     */
    public function rebuild(): array
    {
        if (!$this->tablesReady()) {
            return ['indexed' => 0];
        }

        $rows = $this->buildRows();
        $now = date('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($rows, $now): void {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)->upsert(
                    $chunk,
                    ['source_node_id', 'source_language_code', 'target_node_id'],
                    ['source_version_number', 'source_title', 'link_text', 'indexed_at', 'updated_at'],
                );
            }

            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE)->upsert([
                'id' => 1,
                'rebuilt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['id'], ['rebuilt_at', 'updated_at']);
        });
        $this->lastRefreshTimestamp = time();

        return ['indexed' => count($rows)];
    }

    /**
     * HR: Nakon objave ciljano osvježava jednu izvornu stranicu i jezik.
     * EN: Refreshes one source page and locale after publication.
     */
    public function synchronizeNode(int $workspaceId, int $nodeId, string $language): void
    {
        if (!$this->tablesReady() || $workspaceId <= 0 || $nodeId <= 0) {
            return;
        }

        $language = strtolower(trim($language));
        if ($language === '') {
            return;
        }

        $state = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE)
            ->where('id', '=', 1)
            ->first();
        if (!is_array($state)) {
            $this->rebuild();

            return;
        }

        $rows = $this->buildRows($workspaceId, $nodeId, $language);
        $now = date('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($nodeId, $language, $rows, $now): void {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)
                ->where('source_node_id', '=', $nodeId)
                ->where('source_language_code', '=', $language)
                ->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)->upsert(
                    $chunk,
                    ['source_node_id', 'source_language_code', 'target_node_id'],
                    ['source_version_number', 'source_title', 'link_text', 'indexed_at', 'updated_at'],
                );
            }

            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE)->upsert([
                'id' => 1,
                'rebuilt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['id'], ['rebuilt_at', 'updated_at']);
        });
        $this->lastRefreshTimestamp = time();
    }

    /**
     * HR: Strukturne promjene mogu promijeniti slug odredišta pa traže punu obnovu.
     * EN: Structural changes can alter target slugs and therefore require a full rebuild.
     */
    public function synchronizeWorkspace(int $workspaceId, ?string $language = null): void
    {
        unset($workspaceId, $language);
        $this->rebuild();
    }

    /**
     * HR: Priprema retke izvan transakcije kako čitanje Editora ne drži DB lock.
     * EN: Prepares rows outside the transaction so Editor reads do not hold a DB lock.
     *
     * @return list<array<string,mixed>>
     */
    private function buildRows(
        ?int $onlyWorkspaceId = null,
        ?int $onlyNodeId = null,
        ?string $onlyLanguage = null,
    ): array {
        $workspaces = $this->repository->activeWorkspaces();
        $workspacesById = [];
        foreach ($workspaces as $workspace) {
            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            if ($workspaceId > 0) {
                $workspacesById[$workspaceId] = $workspace;
            }
        }

        $targets = [];
        $sourceNodesById = [];
        $sourceNodesByDocument = [];
        foreach ($this->repository->activeDocumentNodes() as $node) {
            $workspaceId = WorkspaceValue::int($node['workspace_id'] ?? 0);
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $workspace = $workspacesById[$workspaceId] ?? null;
            if (!is_array($workspace) || $nodeId <= 0) {
                continue;
            }

            $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
            $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
            if ($workspaceSlug !== '' && $nodeSlug !== '') {
                $targets[$workspaceSlug . '/' . $nodeSlug] = [
                    'workspaceId' => $workspaceId,
                    'nodeId' => $nodeId,
                ];
            }

            if (
                ($onlyWorkspaceId !== null && $workspaceId !== $onlyWorkspaceId)
                || ($onlyNodeId !== null && $nodeId !== $onlyNodeId)
            ) {
                continue;
            }

            $sourceNodesById[$nodeId] = $node;
            $documentKey = WorkspaceValue::string($node['document_key'] ?? '');
            if ($documentKey !== '') {
                $sourceNodesByDocument[$documentKey] = $node;
            }
        }

        $versionsByLanguage = [];
        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages(array_keys($sourceNodesById));
        foreach ($workflows as $nodeId => $nodeWorkflows) {
            foreach ($nodeWorkflows as $workflow) {
                $language = strtolower(WorkspaceValue::string($workflow['language_code'] ?? ''));
                $node = $sourceNodesById[$nodeId] ?? null;
                $documentKey = is_array($node) ? WorkspaceValue::string($node['document_key'] ?? '') : '';
                $version = WorkspaceValue::int($workflow['published_version_number'] ?? 0);
                if (
                    !$this->workflow->isReadableWorkflow($workflow)
                    || $language === ''
                    || $documentKey === ''
                    || $version <= 0
                    || ($onlyLanguage !== null && $language !== $onlyLanguage)
                ) {
                    continue;
                }

                $versionsByLanguage[$language][$documentKey] = $version;
            }
        }

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($versionsByLanguage as $language => $versions) {
            foreach ($this->editor->publishedVersionsForIndexing($versions, $language) as $documentKey => $version) {
                $source = $sourceNodesByDocument[(string)$documentKey] ?? null;
                if (!is_array($source)) {
                    continue;
                }

                $sourceNodeId = WorkspaceValue::int($source['id'] ?? 0);
                $sourceWorkspaceId = WorkspaceValue::int($source['workspace_id'] ?? 0);
                foreach ($this->extractor->extract(WorkspaceValue::string($version['html'] ?? '')) as $link) {
                    $target = $targets[$link['workspaceSlug'] . '/' . $link['nodeSlug']] ?? null;
                    if (!is_array($target) || $target['nodeId'] === $sourceNodeId) {
                        continue;
                    }

                    $key = $sourceNodeId . ':' . $language . ':' . $target['nodeId'];
                    $rows[$key] = [
                        'source_workspace_id' => $sourceWorkspaceId,
                        'source_node_id' => $sourceNodeId,
                        'source_language_code' => $language,
                        'source_version_number' => WorkspaceValue::int($version['versionNumber'] ?? 0),
                        'source_title' => WorkspaceValue::string($version['title'] ?? '')
                            ?: WorkspaceValue::string($source['title'] ?? ''),
                        'target_workspace_id' => $target['workspaceId'],
                        'target_node_id' => $target['nodeId'],
                        'link_text' => $link['linkText'] !== '' ? $link['linkText'] : null,
                        'indexed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return array_values($rows);
    }

    /** HR: Provjerava obje tablice izvedenog indeksa. EN: Checks both derived-index tables. */
    private function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE);
    }
}
