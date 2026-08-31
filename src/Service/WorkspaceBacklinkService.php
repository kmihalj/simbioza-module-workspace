<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Routing\UrlGenerator;

use function is_array;
use function rawurlencode;
use function rtrim;
use function strcasecmp;
use function strtolower;
use function trim;
use function usort;

/**
 * HR: Čita backlinkove uz ponovnu ACL i workflow provjeru u trenutku prikaza.
 * EN: Reads backlinks while rechecking ACL and workflow state at display time.
 */
final readonly class WorkspaceBacklinkService
{
    /** HR: Prima izvedeni indeks i autoritativne ACL izvore. EN: Receives the derived index and authoritative ACL sources. */
    public function __construct(
        private Database $database,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceBacklinkIndexer $indexer,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
        private WorkspaceEditorBridge $editor,
    ) {
    }

    /**
     * HR: Vraća samo poveznice sa stranica koje trenutačni korisnik smije vidjeti.
     * EN: Returns only links from pages the current user may currently view.
     *
     * @return list<array{title:string,href:string,workspaceName:string,linkText:string,language:string}>
     */
    public function forTarget(int $targetNodeId, string $language): array
    {
        if ($targetNodeId <= 0 || !$this->database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)) {
            return [];
        }

        $this->indexer->refreshIfDue();
        $rows = WorkspaceValue::rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)
                ->where('target_node_id', '=', $targetNodeId)
                ->orderBy('source_title', 'ASC')
                ->get(),
        );
        if ($rows === []) {
            return [];
        }

        $preferredLanguage = strtolower(trim($language));
        $fallbackLanguage = $this->config->siteDefaultLanguage();
        $chosen = [];
        foreach ($rows as $row) {
            $sourceNodeId = WorkspaceValue::int($row['source_node_id'] ?? 0);
            $rowLanguage = strtolower(WorkspaceValue::string($row['source_language_code'] ?? ''));
            if ($sourceNodeId <= 0) {
                continue;
            }

            if ($rowLanguage !== $preferredLanguage && $rowLanguage !== $fallbackLanguage) {
                continue;
            }

            if (!isset($chosen[$sourceNodeId]) || $rowLanguage === $preferredLanguage) {
                $chosen[$sourceNodeId] = $row;
            }
        }

        $neededWorkspaceIds = [];
        foreach ($chosen as $row) {
            $neededWorkspaceIds[WorkspaceValue::int($row['source_workspace_id'] ?? 0)] = true;
        }

        $workspaces = [];
        $nodesByWorkspace = [];
        foreach ($this->repository->activeWorkspaces() as $workspace) {
            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            if (!isset($neededWorkspaceIds[$workspaceId])) {
                continue;
            }

            $workspaces[$workspaceId] = $workspace;
            $nodesByWorkspace[$workspaceId] = $this->repository->nodesForWorkspace($workspaceId);
        }

        $permissionsByWorkspace = [];
        $nodesById = [];
        foreach ($nodesByWorkspace as $workspaceId => $nodes) {
            $workspace = $workspaces[$workspaceId] ?? null;
            if (!is_array($workspace)) {
                continue;
            }

            $permissionsByWorkspace[$workspaceId] = $this->access->nodePermissionsForNodes($workspace, $nodes);
            foreach ($nodes as $node) {
                $nodesById[WorkspaceValue::int($node['id'] ?? 0)] = $node;
            }
        }

        $nodeIdsByLanguage = [];
        foreach ($chosen as $row) {
            $rowLanguage = strtolower(WorkspaceValue::string($row['source_language_code'] ?? ''));
            $nodeIdsByLanguage[$rowLanguage][] = WorkspaceValue::int($row['source_node_id'] ?? 0);
        }

        $readableByLanguage = [];
        foreach ($nodeIdsByLanguage as $rowLanguage => $nodeIds) {
            foreach ($this->repository->nodeWorkflowsForNodes($nodeIds, $rowLanguage) as $nodeId => $workflow) {
                $readableByLanguage[$rowLanguage][$nodeId] = $this->workflow->isReadableWorkflow($workflow);
            }
        }

        $result = [];
        foreach ($chosen as $row) {
            $sourceNodeId = WorkspaceValue::int($row['source_node_id'] ?? 0);
            $workspaceId = WorkspaceValue::int($row['source_workspace_id'] ?? 0);
            $workspace = $workspaces[$workspaceId] ?? null;
            $node = $nodesById[$sourceNodeId] ?? null;
            $permissions = $permissionsByWorkspace[$workspaceId][$sourceNodeId] ?? null;
            $rowLanguage = strtolower(WorkspaceValue::string($row['source_language_code'] ?? ''));
            if (!is_array($workspace)) {
                continue;
            }

            if (!is_array($node)) {
                continue;
            }

            if (!is_array($permissions)) {
                continue;
            }

            if (!($permissions['can_view'] ?? false)) {
                continue;
            }

            if (!($readableByLanguage[$rowLanguage][$sourceNodeId] ?? false)) {
                continue;
            }

            $result[] = [
                'title' => WorkspaceValue::string($row['source_title'] ?? '')
                    ?: WorkspaceValue::string($node['title'] ?? ''),
                'href' => $this->nodePath(
                    WorkspaceValue::string($workspace['slug'] ?? ''),
                    WorkspaceValue::string($node['slug'] ?? ''),
                    $rowLanguage,
                ),
                'workspaceName' => WorkspaceValue::string($workspace['name'] ?? ''),
                'linkText' => WorkspaceValue::string($row['link_text'] ?? ''),
                'language' => $rowLanguage,
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $workspaceOrder = strcasecmp($left['workspaceName'], $right['workspaceName']);

            return $workspaceOrder !== 0 ? $workspaceOrder : strcasecmp($left['title'], $right['title']);
        });

        return $result;
    }

    /**
     * HR: Vraća stranice koje dinamički uključuju cilj, uz istu završnu ACL i
     *     workflow provjeru kao za obične backlinkove.
     * EN: Returns pages that dynamically include the target, with the same
     *     final ACL and workflow check used for ordinary backlinks.
     *
     * @return list<array{title:string,href:string,workspaceName:string,linkText:string,language:string}>
     */
    public function includedIn(string $targetDocumentKey, string $language): array
    {
        $sources = $this->editor->includeSources($targetDocumentKey, $language);
        if ($sources === []) {
            return [];
        }

        $sourcesByKey = [];
        foreach ($sources as $source) {
            $key = WorkspaceValue::string($source['documentKey'] ?? '');
            if ($key !== '') {
                $sourcesByKey[$key] = $source;
            }
        }

        $result = [];
        foreach ($this->repository->activeWorkspaces() as $workspace) {
            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            $nodes = $this->repository->nodesForWorkspace($workspaceId);
            $permissions = $this->access->nodePermissionsForNodes($workspace, $nodes);
            foreach ($nodes as $node) {
                $documentKey = WorkspaceValue::string($node['document_key'] ?? '');
                $source = $sourcesByKey[$documentKey] ?? null;
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                if (!is_array($source)) {
                    continue;
                }

                if (!($permissions[$nodeId]['can_view'] ?? false)) {
                    continue;
                }

                $sourceLanguage = strtolower(WorkspaceValue::string($source['language'] ?? $language));
                $workflows = $this->repository->nodeWorkflowsForNodes([$nodeId], $sourceLanguage);
                if (!$this->workflow->isReadableWorkflow($workflows[$nodeId] ?? null)) {
                    continue;
                }

                $result[] = [
                    'title' => WorkspaceValue::string($source['title'] ?? '')
                        ?: WorkspaceValue::string($node['title'] ?? ''),
                    'href' => $this->nodePath(
                        WorkspaceValue::string($workspace['slug'] ?? ''),
                        WorkspaceValue::string($node['slug'] ?? ''),
                        $sourceLanguage,
                    ),
                    'workspaceName' => WorkspaceValue::string($workspace['name'] ?? ''),
                    'linkText' => '',
                    'language' => $sourceLanguage,
                ];
            }
        }

        usort($result, static function (array $left, array $right): int {
            $workspaceOrder = strcasecmp($left['workspaceName'], $right['workspaceName']);

            return $workspaceOrder !== 0 ? $workspaceOrder : strcasecmp($left['title'], $right['title']);
        });

        return $result;
    }

    /** HR: Gradi instalacijski neovisnu putanju stranice. EN: Builds an installation-independent page path. */
    private function nodePath(string $workspaceSlug, string $nodeSlug, string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.node.show')) {
            return $this->urlGenerator->getPathFor(
                'workspace.node.show',
                ['workspaceSlug' => $workspaceSlug, 'nodeSlug' => $nodeSlug],
                ['lang' => $language],
            );
        }

        return rtrim($this->urlGenerator->getBasePath(), '/')
        . '/' . trim($this->config->rootPath(), '/')
        . '/' . rawurlencode($workspaceSlug)
        . '/' . rawurlencode($nodeSlug)
        . '?lang=' . rawurlencode($language);
    }
}
