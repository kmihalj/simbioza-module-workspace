<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Routing\UrlGenerator;
use Throwable;

use function rawurlencode;
use function trim;

/**
 * HR: Izlaže područja i njihove stvarne stranice kao jasna odredišta editoru
 *     menija, bez stvaranja obavezne ovisnosti Workspace modula o Menu modulu.
 * EN: Exposes workspaces and their actual pages as clear menu-editor targets
 *     without creating a required Workspace-to-Menu dependency.
 */
final readonly class WorkspaceMenuNavigationTargetProvider
{
    /**
     * HR: Prima repozitorij područja te generatore prenosivih URL odredišta.
     * EN: Receives the Workspace repository and generators of portable URL destinations.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Vraća konkretne URL-ove područja i dokumentnih stranica. Područje
     *     dodatno nosi path patterne za automatsko vezanje posebnog menija.
     * EN: Returns concrete workspace and document-page URLs. A workspace also
     *     carries path patterns for automatic special-menu scoping.
     *
     * @return list<array<string,mixed>>
     */
    public function targets(): array
    {
        try {
            if (!$this->repository->tablesReady()) {
                return [];
            }

            $workspaces = $this->repository->activeWorkspaces();
        } catch (Throwable) {
            return [];
        }

        $languages = $this->config->supportedLanguages();
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $targets = [];
        foreach ($workspaces as $workspace) {
            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
            $workspaceLabels = [];
            foreach ($languages as $language) {
                $localizedWorkspace = $this->repository->localizeWorkspace(
                    $workspace,
                    $language,
                    $primaryLanguage,
                );
                $workspaceLabels[$language] = WorkspaceValue::string(
                    $localizedWorkspace['name'] ?? $workspaceSlug,
                );
            }

            $workspaceName = $workspaceLabels[$primaryLanguage]
            ?? WorkspaceValue::string($workspace['name'] ?? $workspaceSlug);
            if ($workspaceId < 1) {
                continue;
            }

            if ($workspaceSlug === '') {
                continue;
            }

            if ($workspaceName === '') {
                continue;
            }

            $workspacePath = $this->workspacePath($workspaceSlug);
            $targets[] = [
                'id' => 'workspace.' . $workspaceId,
                'group' => 'Workspaces',
                'label' => $workspaceName,
                'labels' => $workspaceLabels,
                'url' => $workspacePath,
                'context_paths' => [$workspacePath, rtrim($workspacePath, '/') . '/*'],
            ];

            try {
                $nodes = $this->repository->nodesForWorkspace($workspaceId);
            } catch (Throwable) {
                continue;
            }

            foreach ($nodes as $node) {
                if (WorkspaceValue::string($node['node_type'] ?? '') !== 'document') {
                    continue;
                }

                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
                $nodeLabels = [];
                foreach ($languages as $language) {
                    $localizedNode = $this->repository->localizeNode($node, $language, $primaryLanguage);
                    $nodeTitle = WorkspaceValue::string($localizedNode['title'] ?? $nodeSlug);
                    $localizedWorkspaceName = $workspaceLabels[$language] ?? $workspaceName;
                    $nodeLabels[$language] = $localizedWorkspaceName . ' / ' . $nodeTitle;
                }

                $nodeTitle = WorkspaceValue::string(
                    ($this->repository->localizeNode($node, $primaryLanguage, $primaryLanguage))['title']
                        ?? $nodeSlug,
                );
                if ($nodeId < 1) {
                    continue;
                }

                if ($nodeSlug === '') {
                    continue;
                }

                if ($nodeTitle === '') {
                    continue;
                }

                $label = $nodeLabels[$primaryLanguage] ?? ($workspaceName . ' / ' . $nodeTitle);
                $nodePath = $this->nodePath($workspaceSlug, $nodeSlug);
                $targets[] = [
                    'id' => 'workspace.' . $workspaceId . '.page.' . $nodeId,
                    'group' => 'Workspace pages',
                    'label' => $label,
                    'labels' => $nodeLabels,
                    'url' => $nodePath,
                    'context_paths' => [$nodePath],
                ];
            }
        }

        return $targets;
    }

    /**
     * HR: Gradi URL područja kroz registriranu rutu uz siguran fallback.
     * EN: Builds a workspace URL through the registered route with a safe fallback.
     */
    private function workspacePath(string $workspaceSlug): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.show')) {
            return $this->contextPath(
                $this->urlGenerator->getPathFor('workspace.show', ['workspaceSlug' => $workspaceSlug]),
            );
        }

        return '/' . trim($this->config->rootPath(), '/')
        . '/' . rawurlencode($workspaceSlug);
    }

    /**
     * HR: Gradi URL jedne stranice područja kroz registriranu rutu.
     * EN: Builds one workspace-page URL through the registered route.
     */
    private function nodePath(string $workspaceSlug, string $nodeSlug): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.node.show')) {
            return $this->contextPath(
                $this->urlGenerator->getPathFor('workspace.node.show', [
                    'workspaceSlug' => $workspaceSlug,
                    'nodeSlug' => $nodeSlug,
                ]),
            );
        }

        return rtrim($this->workspacePath($workspaceSlug), '/') . '/' . rawurlencode($nodeSlug);
    }

    /**
     * HR: Uklanja aplikacijski base path iz providerskog URL-a. Menu sprema
     *     prenosivu putanju i stvarni instalacijski prefiks dodaje pri renderiranju.
     * EN: Removes the application base path from a provider URL. Menu stores a
     *     portable path and adds the actual installation prefix while rendering.
     */
    private function contextPath(string $url): string
    {
        $basePath = rtrim($this->urlGenerator->getBasePath(), '/');
        if ($basePath !== '' && str_starts_with($url, $basePath . '/')) {
            return substr($url, strlen($basePath)) ?: '/';
        }

        return $url;
    }
}
