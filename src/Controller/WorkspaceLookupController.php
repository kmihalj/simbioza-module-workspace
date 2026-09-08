<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceLookupService;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * HR: Izlaže ACL-sigurne udaljene imenike područja i stranica.
 * EN: Exposes ACL-safe remote Workspace and page directories.
 */
final readonly class WorkspaceLookupController
{
    /**
     * HR: Inicijalizira kontroler udaljenih imenika područja i stranica.
     * EN: Initializes the remote Workspace and page directory controller.
     */
    public function __construct(
        private ResponseFactory $responses,
        private WorkspaceLookupService $lookups,
        private WorkspaceAccessService $access,
    ) {
    }

    /**
     * HR: Vraća ograničenu stranicu područja dostupnih korisniku.
     * EN: Returns a bounded page of Workspaces available to the user.
     */
    public function workspaces(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response($request, 'workspace');
    }

    /**
     * HR: Vraća ograničenu stranicu sadržaja dostupnog korisniku.
     * EN: Returns a bounded page of content available to the user.
     */
    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response($request, 'page');
    }

    /**
     * HR: Pretvara parametre imenika u ACL-siguran JSON odgovor.
     * EN: Converts directory parameters into an ACL-safe JSON response.
     */
    private function response(ServerRequestInterface $request, string $kind): ResponseInterface
    {
        $query = $request->getQueryParams();
        $audience = is_scalar($query['audience'] ?? null) ? trim((string)$query['audience']) : 'current';
        if (in_array($audience, ['public', 'authenticated'], true) && !$this->access->isAdministrator()) {
            return $this->responses->json(['ok' => false, 'error' => __('Pristup nije dozvoljen.')], 403);
        }

        $search = is_scalar($query['q'] ?? null) ? trim((string)$query['q']) : '';
        $page = is_numeric($query['page'] ?? null) ? max(1, (int)$query['page']) : 1;
        $perPage = is_numeric($query['per_page'] ?? null) ? (int)$query['per_page'] : 25;
        $workspaceId = is_numeric($query['workspace_id'] ?? null) && (int)$query['workspace_id'] > 0
        ? (int)$query['workspace_id']
        : null;
        $publishedOnly = is_scalar($query['published'] ?? null) && (string)$query['published'] === '1';
        $includeShorts = is_scalar($query['include_shorts'] ?? null) && (string)$query['include_shorts'] === '1';
        $includeContainers = is_scalar($query['include_containers'] ?? null)
        && (string)$query['include_containers'] === '1';
        $requireCanAdd = is_scalar($query['require_can_add'] ?? null)
        && (string)$query['require_can_add'] === '1';
        $excludeNodeId = is_numeric($query['exclude_node_id'] ?? null) && (int)$query['exclude_node_id'] > 0
        ? (int)$query['exclude_node_id']
        : null;
        try {
            $result = $kind === 'page'
            ? $this->lookups->pagePage(
                $search,
                $workspaceId,
                $page,
                $perPage,
                $audience,
                $publishedOnly,
                $includeShorts,
                $includeContainers,
                $requireCanAdd,
                $excludeNodeId,
            )
            : $this->lookups->workspacePage($search, $page, $perPage, $audience, $workspaceId);

            return $this->responses->json(['ok' => true, ...$result], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return $this->responses->json([
                'ok' => false,
                'error' => __('Popis za odabir trenutačno nije moguće dohvatiti.'),
            ], 500, ['Cache-Control' => 'no-store']);
        }
    }
}
