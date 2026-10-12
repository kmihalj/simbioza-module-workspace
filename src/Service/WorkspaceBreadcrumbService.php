<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Routing\UrlGenerator;

use function array_key_exists;
use function is_array;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * EN: Builds an ACL-safe breadcrumb trail from the already filtered tree.
 *
 * HR: Gradi ACL-sigurnu navigacijsku putanju iz već filtriranog stabla.
 */
final readonly class WorkspaceBreadcrumbService
{
    /**
     * EN: Receives the repository, config, and URL generator.
     *
     * HR: Prima repozitorij, konfiguraciju i generator URL-ova.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * EN: Returns Home, the Workspace list, the Workspace, and visible ancestors through the current page.
     *
     * Keeps the Workspace as a link.
     *
     * HR: Vraća Početnu, popis područja, područje i vidljive pretke do trenutačne stranice.
     *
     * Zadržava područje kao poveznicu.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $activeNode
     * @param list<array<string,mixed>> $visibleTree
     * @return list<array{label:string,href:string,current:bool,icon?:string}>
     */
    public function build(
        array $workspace,
        ?array $activeNode,
        array $visibleTree,
        string $language,
        string $currentTitle = '',
        bool $linkWorkspace = false,
    ): array {
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $workspace = $this->repository->localizeWorkspace($workspace, $language, $primaryLanguage);
        $activeNode = is_array($activeNode)
        ? $this->repository->localizeNode($activeNode, $language, $primaryLanguage)
        : null;
        $visibleTree = $this->repository->localizeTree($visibleTree, $language, $primaryLanguage);
        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $workspaceName = WorkspaceValue::string($workspace['name'] ?? '');
        $crumbs = [[
            'label' => __('Početna'),
            'href' => $this->homePath(),
            'current' => false,
            'icon' => 'home',
        ]];

        $crumbs[] = [
            'label' => __('Područja'),
            'href' => $this->workspacesPath($language),
            'current' => false,
        ];

        $crumbs[] = [
            'label' => $workspaceName,
            'href' => is_array($activeNode) || $linkWorkspace
                ? $this->workspacePath($workspaceSlug, $language)
                : '',
            'current' => !is_array($activeNode) && !$linkWorkspace,
        ];
        if (!is_array($activeNode)) {
            return $crumbs;
        }

        $activeNodeId = WorkspaceValue::int($activeNode['id'] ?? 0);
        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $visibleById = [];
        $this->indexVisibleTree($visibleTree, $visibleById);
        $ancestorIds = $this->repository->ancestorNodeIds($workspaceId, $activeNodeId);

        foreach ($ancestorIds as $nodeId) {
            if (!array_key_exists($nodeId, $visibleById)) {
                continue;
            }

            $node = $visibleById[$nodeId];
            $current = $nodeId === $activeNodeId;
            $label = $current && trim($currentTitle) !== ''
            ? trim($currentTitle)
            : WorkspaceValue::string($node['title'] ?? '');
            if ($label === '') {
                continue;
            }

            $crumbs[] = [
                'label' => $label,
                'href' => $current ? '' : $this->nodePath(
                    $workspaceSlug,
                    WorkspaceValue::string($node['slug'] ?? ''),
                    $language,
                ),
                'current' => $current,
            ];
        }

        return $crumbs;
    }

    /**
     * EN: Recursively indexes only nodes already allowed by the ACL service.
     *
     * HR: Rekurzivno indeksira samo čvorove koje je ACL servis već dopustio.
     *
     * @param list<array<string,mixed>> $tree
     * @param array<int,array<string,mixed>> $indexed
     */
    private function indexVisibleTree(array $tree, array &$indexed): void
    {
        foreach ($tree as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId > 0) {
                $indexed[$nodeId] = $node;
            }

            $children = $node['children'] ?? [];
            if (is_array($children)) {
                $this->indexVisibleTree(WorkspaceValue::rows($children), $indexed);
            }
        }
    }

    /**
     * EN: Returns the application home path.
     *
     * HR: Vraća početnu putanju aplikacije.
     */
    private function homePath(): string
    {
        if ($this->urlGenerator->namedRouteExists('home')) {
            return $this->urlGenerator->getPathFor('home');
        }

        return rtrim($this->urlGenerator->getBasePath(), '/') . '/';
    }

    /**
     * EN: Returns the localized Workspace-list path.
     *
     * HR: Vraća lokaliziranu putanju popisa područja.
     */
    private function workspacesPath(string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.index')) {
            return $this->urlGenerator->getPathFor('workspace.index', [], ['lang' => $language]);
        }

        return rtrim($this->urlGenerator->getBasePath(), '/')
        . '/' . trim($this->config->rootPath(), '/')
        . 's?lang=' . rawurlencode($language);
    }

    /**
     * EN: Returns the localized Workspace path.
     *
     * HR: Vraća lokaliziranu putanju područja.
     */
    private function workspacePath(string $workspaceSlug, string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.show')) {
            return $this->urlGenerator->getPathFor(
                'workspace.show',
                ['workspaceSlug' => $workspaceSlug],
                ['lang' => $language],
            );
        }

        return rtrim($this->urlGenerator->getBasePath(), '/')
        . '/' . trim($this->config->rootPath(), '/')
        . '/' . rawurlencode($workspaceSlug)
        . '?lang=' . rawurlencode($language);
    }

    /**
     * EN: Returns the localized page path.
     *
     * HR: Vraća lokaliziranu putanju stranice.
     */
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
