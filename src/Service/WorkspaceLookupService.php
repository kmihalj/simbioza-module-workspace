<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\LocaleSorter;
use HeartPhrame\Localization\TranslatorInterface;

/**
 * HR: Gradi ograničene, ACL-sigurne stranice rezultata za udaljene birače.
 * EN: Builds bounded, ACL-safe result pages for remote pickers.
 */
final readonly class WorkspaceLookupService
{
    private const PAGE_SIZE = 25;

    /**
     * HR: Inicijalizira ACL-sigurnu uslugu udaljenih imenika.
     * EN: Initializes the ACL-safe remote directory service.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspacePresentationRegistry $presentations,
        private WorkspaceWorkflowService $workflow,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * HR: Pretražuje korisniku dostupna područja i vraća jednu stranicu rezultata.
     * EN: Searches Workspaces available to the user and returns one result page.
     *
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,hasMore:bool}
     */
    public function workspacePage(
        string $search = '',
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
        string $audience = 'current',
        ?int $workspaceId = null,
    ): array {
        [$page, $perPage] = $this->pagination($page, $perPage);
        $workspaces = $this->presentations->many(
            $this->access->visibleWorkspaces($this->audienceUser($audience)),
        );
        $needle = mb_strtolower(mb_substr(trim($search), 0, 190));
        $items = [];
        foreach ($workspaces as $workspace) {
            $id = WorkspaceValue::int($workspace['id'] ?? 0);
            $name = WorkspaceValue::string($workspace['name'] ?? '');
            $slug = WorkspaceValue::string($workspace['slug'] ?? '');
            if ($id <= 0 || $name === '' || ($workspaceId !== null && $id !== $workspaceId)) {
                continue;
            }

            if (
                $needle !== ''
                && !str_contains(mb_strtolower($name . ' ' . $slug), $needle)
            ) {
                continue;
            }

            $items[] = ['id' => $id, 'label' => $name, 'slug' => $slug];
        }

        $locale = $this->translator->getLocale();
        usort($items, static fn(array $left, array $right): int => LocaleSorter::compare(
            (string)($left['label'] ?? ''),
            (string)($right['label'] ?? ''),
            $locale,
        ));

        return $this->resultPage($items, $page, $perPage);
    }

    /**
     * HR: Pretražuje korisniku dostupan sadržaj i vraća jednu stranicu rezultata.
     * EN: Searches content available to the user and returns one result page.
     *
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,hasMore:bool}
     */
    public function pagePage(
        string $search = '',
        ?int $workspaceId = null,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
        string $audience = 'current',
        bool $publishedOnly = false,
        bool $includeShorts = false,
        bool $includeContainers = false,
        bool $requireCanAdd = false,
        ?int $excludeNodeId = null,
    ): array {
        [$page, $perPage] = $this->pagination($page, $perPage);
        $user = $this->audienceUser($audience);
        $workspaces = $this->presentations->many($this->access->visibleWorkspaces($user));
        $needle = mb_strtolower(mb_substr(trim($search), 0, 190));
        $items = [];
        foreach ($workspaces as $workspace) {
            $currentWorkspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            if ($currentWorkspaceId <= 0 || ($workspaceId !== null && $currentWorkspaceId !== $workspaceId)) {
                continue;
            }

            $workspaceName = WorkspaceValue::string($workspace['name'] ?? '');
            $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
            $nodes = $this->repository->localizeNodes(
                $this->repository->nodesForWorkspace($currentWorkspaceId),
                $this->translator->getLocale(),
                WorkspaceValue::string($workspace['primary_language'] ?? 'hr'),
            );
            $permissions = $this->access->nodePermissionsForNodes($workspace, $nodes, $user);
            $workflowRows = $publishedOnly
            ? $this->repository->nodeWorkflowsForNodesAllLanguages(array_values(array_filter(array_map(
                static fn(array $node): int => WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                    ? WorkspaceValue::int($node['id'] ?? 0)
                    : 0,
                $nodes,
            ))))
            : [];
            $shortsLabel = __('Sažetci');
            if (
                $includeShorts
                && ($needle === ''
                    || str_contains(
                        mb_strtolower($workspaceName . ' ' . $workspaceSlug . ' ' . $shortsLabel),
                        $needle,
                    ))
            ) {
                $items[] = [
                    'id' => $currentWorkspaceId,
                    'value' => 'shorts:' . $currentWorkspaceId,
                    'label' => $workspaceId === null
                    ? $workspaceName . ' / ' . $shortsLabel
                    : $shortsLabel,
                    'workspaceId' => $currentWorkspaceId,
                    'workspaceLabel' => $workspaceName,
                    'workspaceSlug' => $workspaceSlug,
                ];
            }

            foreach ($nodes as $node) {
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                $title = WorkspaceValue::string($node['title'] ?? '');
                $slug = WorkspaceValue::string($node['slug'] ?? '');
                $nodeType = WorkspaceValue::string($node['node_type'] ?? '');
                if (
                    $nodeId <= 0
                    || ($nodeType !== 'document' && (!$includeContainers || $nodeType !== 'separator'))
                    || ($excludeNodeId !== null && $nodeId === $excludeNodeId)
                    || !(bool)($node['is_enabled'] ?? false)
                    || ($requireCanAdd
                        ? !(bool)($permissions[$nodeId]['can_add'] ?? false)
                        : !(bool)($permissions[$nodeId]['can_view'] ?? false))
                    || ($publishedOnly && !$this->hasReadableWorkflow($workflowRows[$nodeId] ?? []))
                ) {
                    continue;
                }

                if (
                    $needle !== ''
                    && !str_contains(
                        mb_strtolower($workspaceName . ' ' . $workspaceSlug . ' ' . $title . ' ' . $slug),
                        $needle,
                    )
                ) {
                    continue;
                }

                $items[] = [
                    'id' => $nodeId,
                    'value' => 'page:' . $nodeId,
                    'label' => $workspaceId === null ? $workspaceName . ' / ' . $title : $title,
                    'workspaceId' => $currentWorkspaceId,
                    'workspaceLabel' => $workspaceName,
                    'workspaceSlug' => $workspaceSlug,
                    'slug' => $slug,
                    'documentKey' => WorkspaceValue::string($node['document_key'] ?? ''),
                ];
            }
        }

        $locale = $this->translator->getLocale();
        usort($items, static fn(array $left, array $right): int => LocaleSorter::compare(
            (string)($left['label'] ?? ''),
            (string)($right['label'] ?? ''),
            $locale,
        ));

        return $this->resultPage($items, $page, $perPage);
    }

    /**
     * HR: Provjerava ima li stranica barem jedan čitljiv tijek objave.
     * EN: Checks whether a page has at least one readable publication workflow.
     *
     * @param list<array<string,mixed>> $workflows
     */
    private function hasReadableWorkflow(array $workflows): bool
    {
        foreach ($workflows as $workflow) {
            if ($this->workflow->isReadableWorkflow($workflow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * HR: Vraća odabrano područje samo ako je vidljivo zadanoj publici.
     * EN: Returns the selected Workspace only when it is visible to the given audience.
     *
     * @return array<string,mixed>|null
     */
    public function selectedWorkspace(int $workspaceId, string $audience = 'current'): ?array
    {
        foreach ($this->workspacePage('', 1, self::PAGE_SIZE, $audience)['items'] as $item) {
            if (WorkspaceValue::int($item['id'] ?? 0) === $workspaceId) {
                return $item;
            }
        }

        $workspace = $this->repository->findWorkspaceById($workspaceId);
        if (!is_array($workspace)) {
            return null;
        }

        foreach ($this->access->visibleWorkspaces($this->audienceUser($audience)) as $visible) {
            if (WorkspaceValue::int($visible['id'] ?? 0) !== $workspaceId) {
                continue;
            }

            $presented = $this->presentations->one($workspace);
            return [
                'id' => $workspaceId,
                'label' => WorkspaceValue::string($presented['name'] ?? ''),
                'slug' => WorkspaceValue::string($presented['slug'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * HR: Ograničava broj stranice i veličinu rezultata na podržane vrijednosti.
     * EN: Bounds the page number and result size to supported values.
     *
     * @return array{0:int,1:int}
     */
    private function pagination(int $page, int $perPage): array
    {
        return [max(1, $page), max(1, min(self::PAGE_SIZE, $perPage))];
    }

    /**
     * HR: Izdvaja traženu stranicu rezultata i označava postoje li daljnji zapisi.
     * EN: Extracts the requested result page and indicates whether more records exist.
     *
     * @param list<array<string,mixed>> $items
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,hasMore:bool}
     */
    private function resultPage(array $items, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage + 1);
        $hasMore = count($slice) > $perPage;
        if ($hasMore) {
            array_pop($slice);
        }

        return [
            'items' => array_values($slice),
            'page' => $page,
            'perPage' => $perPage,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * HR: Određuje korisnički kontekst za javnu, prijavljenu ili trenutačnu publiku.
     * EN: Resolves the user context for public, authenticated, or current audiences.
     *
     * @return array<string,mixed>|null
     */
    private function audienceUser(string $audience): ?array
    {
        return match ($audience) {
            'public' => [],
            'authenticated' => ['id' => -1, 'is_admin' => false, 'group_ids' => []],
            default => $this->access->currentUser(),
        };
    }
}
