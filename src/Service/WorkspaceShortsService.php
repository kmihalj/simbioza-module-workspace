<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Routing\UrlGenerator;

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_scalar;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function strcmp;
use function strtolower;
use function trim;
use function usort;

/**
 * HR: Gradi ACL-siguran, paginirani prikaz sažetaka objavljenih Workspace stranica.
 * EN: Builds an ACL-safe, limited Shorts view of published Workspace pages.
 */
final readonly class WorkspaceShortsService
{
    private const DEPTHS = [1, 2, 3];

    private const LIMITS = [5, 10, 25, 50];

    private const ORDERS = ['hierarchy', 'newest', 'oldest'];

    /**
     * HR: Prima jedinstvene izvore stabla, ACL-a, objava i HTML sadržaja.
     * EN: Receives the canonical tree, ACL, publication, and HTML-content sources.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceEditorBridge $editor,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Filtrira vidljivo stablo, zatim objavljene stranice odabranih razina,
     *     pa tek nakon sortiranja i ograničenja skupno učitava njihov HTML.
     *
     * EN: Filters the visible tree, then published pages at selected depths,
     *     and only after sorting and limiting batch-loads their HTML.
     *
     * @param array<string, mixed> $workspace
     * @param array<mixed, mixed> $query
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public function viewModel(
        array $workspace,
        string $language,
        array $query,
        ?string $defaultLanguage = null,
        ?array $user = null,
    ): array {
        $defaultLanguage = $this->normalizedLanguage(
            $defaultLanguage ?? $this->config->siteDefaultLanguage(),
            $this->config->siteDefaultLanguage(),
        );
        $depth = $this->allowedInt(
            $query['depth'] ?? null,
            self::DEPTHS,
            $this->config->shortsDefaultDepth(),
        );
        $order = $this->allowedString(
            $query['order'] ?? null,
            self::ORDERS,
            $this->config->shortsDefaultOrder(),
        );
        $requestedLimit = is_scalar($query['limit'] ?? null)
        ? strtolower(trim((string)$query['limit']))
        : '';

        $visibleTree = $this->access->visibleTreeForLanguages(
            $workspace,
            $user,
            array_values(array_unique([$language, $defaultLanguage])),
        );
        $flatNodes = $this->flattenTree($visibleTree);
        $candidateIds = [];
        foreach ($flatNodes as $entry) {
            $node = WorkspaceValue::stringKeyArray($entry['node'] ?? null);
            if (
                WorkspaceValue::int($entry['depth'] ?? 0) <= $depth
                && WorkspaceValue::string($node['node_type'] ?? '') === 'document'
            ) {
                $candidateIds[] = WorkspaceValue::int($node['id'] ?? 0);
            }
        }

        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages($candidateIds);
        $eligible = [];
        foreach ($flatNodes as $entry) {
            $node = WorkspaceValue::stringKeyArray($entry['node'] ?? null);
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if (WorkspaceValue::int($entry['depth'] ?? 0) > $depth) {
                continue;
            }

            if (WorkspaceValue::string($node['node_type'] ?? '') !== 'document') {
                continue;
            }

            $workflow = $this->preferredReadableWorkflow(
                WorkspaceValue::rows($workflows[$nodeId] ?? null),
                $language,
                $defaultLanguage,
            );
            if ($workflow === null) {
                continue;
            }

            $eligible[] = [
                'node' => $node,
                'workflow' => $workflow,
                'language' => WorkspaceValue::string($workflow['language_code'] ?? $language),
                'hierarchy_index' => WorkspaceValue::int($entry['index'] ?? 0),
                'published_at' => WorkspaceValue::string($workflow['published_at'] ?? ''),
            ];
        }

        if ($order !== 'hierarchy') {
            usort(
                $eligible,
                static function (array $left, array $right) use ($order): int {
                    $comparison = strcmp(
                        WorkspaceValue::string($left['published_at'] ?? ''),
                        WorkspaceValue::string($right['published_at'] ?? ''),
                    );
                    if ($comparison === 0) {
                        return WorkspaceValue::int($left['hierarchy_index'] ?? 0)
                        <=> WorkspaceValue::int($right['hierarchy_index'] ?? 0);
                    }

                    return $order === 'newest' ? -$comparison : $comparison;
                },
            );
        }

        $total = count($eligible);
        $allAvailable = $total < 100;
        $limit = $this->allowedInt(
            $requestedLimit,
            self::LIMITS,
            $this->config->shortsDefaultLimit(),
        );
        $selectedLimit = $requestedLimit === 'all' && $allAvailable ? 'all' : (string)$limit;
        $selectedEntries = $selectedLimit === 'all' ? $eligible : array_slice($eligible, 0, $limit);

        $requestedVersions = [];
        foreach ($selectedEntries as $entry) {
            $node = WorkspaceValue::stringKeyArray($entry['node'] ?? null);
            $workflow = WorkspaceValue::stringKeyArray($entry['workflow'] ?? null);
            $documentKey = WorkspaceValue::string($node['document_key'] ?? '');
            $versionNumber = WorkspaceValue::int($workflow['published_version_number'] ?? 0);
            $contentLanguage = WorkspaceValue::string($entry['language'] ?? $language);
            if ($documentKey !== '' && $versionNumber > 0) {
                $requestedVersions[$contentLanguage][$documentKey] = $versionNumber;
            }
        }

        $versions = [];
        foreach ($requestedVersions as $contentLanguage => $versionNumbers) {
            $versions[$contentLanguage] = $this->editor->publishedVersions(
                WorkspaceValue::intMap($versionNumbers),
                $contentLanguage,
            );
        }

        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $articles = [];
        foreach ($selectedEntries as $entry) {
            $node = WorkspaceValue::stringKeyArray($entry['node'] ?? null);
            $documentKey = WorkspaceValue::string($node['document_key'] ?? '');
            $contentLanguage = WorkspaceValue::string($entry['language'] ?? $language);
            $languageVersions = WorkspaceValue::stringKeyArray($versions[$contentLanguage] ?? null);
            $version = WorkspaceValue::stringKeyArray($languageVersions[$documentKey] ?? null);
            if ($version === []) {
                continue;
            }

            $versionTitle = WorkspaceValue::string($version['title'] ?? '');
            $articles[] = [
                'title' => $versionTitle !== ''
                    ? $versionTitle
                    : WorkspaceValue::string($node['title'] ?? ''),
                'html' => WorkspaceValue::string($version['html'] ?? ''),
                'published_at' => WorkspaceValue::string($entry['published_at'] ?? ''),
                'href' => $this->nodePath(
                    $workspaceSlug,
                    WorkspaceValue::string($node['slug'] ?? ''),
                    $contentLanguage,
                ),
                'language' => $contentLanguage,
            ];
        }

        return [
            'tree' => $this->decorateTree($visibleTree, $workspaceSlug, $language),
            'articles' => $articles,
            'depth' => $depth,
            'limit' => $selectedLimit,
            'order' => $order,
            'total' => $total,
            'all_available' => $allAvailable,
            'shorts_path' => $this->shortsPath($workspaceSlug, $language),
        ];
    }

    /**
     * HR: Vraća javnu putanju Sažetaka s odabranim jezikom.
     * EN: Returns the public Shorts path with the selected locale.
     */
    public function shortsPath(string $workspaceSlug, string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.shorts')) {
            return $this->urlGenerator->getPathFor(
                'workspace.shorts',
                ['workspaceSlug' => $workspaceSlug],
                ['lang' => $language],
            );
        }

        return $this->workspacePath($workspaceSlug) . '/shorts?lang=' . rawurlencode($language);
    }

    /**
     * HR: Rekurzivno pretvara vidljivo stablo u stabilan hijerarhijski niz.
     * EN: Recursively flattens the visible tree into a stable hierarchy sequence.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array{node:array<string,mixed>,depth:int,index:int}>
     */
    private function flattenTree(array $tree, int $depth = 1, int &$index = 0): array
    {
        $flat = [];
        foreach ($tree as $node) {
            ++$index;
            $flat[] = ['node' => $node, 'depth' => $depth, 'index' => $index];
            $children = WorkspaceValue::rows($node['children'] ?? null);
            if ($children !== []) {
                $flat = [...$flat, ...$this->flattenTree($children, $depth + 1, $index)];
            }
        }

        return $flat;
    }

    /**
     * HR: Dodaje javnu putanju svakom već ACL-filtriranom čvoru stabla.
     * EN: Adds a public path to every already ACL-filtered tree node.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private function decorateTree(array $tree, string $workspaceSlug, string $language): array
    {
        foreach ($tree as &$node) {
            $node['href'] = WorkspaceValue::string($node['node_type'] ?? '') === 'document'
            ? $this->nodePath(
                $workspaceSlug,
                WorkspaceValue::string($node['slug'] ?? ''),
                $language,
            )
            : WorkspaceValue::string($node['target_url'] ?? '#');
            $node['children'] = $this->decorateTree(
                WorkspaceValue::rows($node['children'] ?? null),
                $workspaceSlug,
                $language,
            );
        }

        unset($node);

        return $tree;
    }

    /**
     * HR: Gradi javnu putanju Područja iz aktivne konfiguracije ruta.
     * EN: Builds the public Workspace path from active route configuration.
     */
    private function workspacePath(string $workspaceSlug): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.show')) {
            return $this->urlGenerator->getPathFor(
                'workspace.show',
                ['workspaceSlug' => $workspaceSlug],
            );
        }

        return rtrim($this->urlGenerator->getBasePath(), '/')
        . '/'
        . trim($this->config->rootPath(), '/')
        . '/'
        . rawurlencode($workspaceSlug);
    }

    /**
     * HR: Gradi kanonsku javnu putanju dokument-čvora i čuva jezik.
     * EN: Builds a document node's canonical public path while preserving locale.
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

        return $this->workspacePath($workspaceSlug)
        . '/'
        . rawurlencode($nodeSlug)
        . '?lang='
        . rawurlencode($language);
    }

    /**
     * HR: Prihvaća samo izričito dopuštenu brojčanu vrijednost filtra.
     * EN: Accepts only an explicitly allowed numeric filter value.
     *
     * @param list<int> $allowed
     */
    private function allowedInt(mixed $value, array $allowed, int $fallback): int
    {
        $number = is_scalar($value) ? (int)$value : 0;

        return in_array($number, $allowed, true) ? $number : $fallback;
    }

    /**
     * HR: Prihvaća samo izričito dopuštenu tekstualnu vrijednost filtra.
     * EN: Accepts only an explicitly allowed string filter value.
     *
     * @param list<string> $allowed
     */
    private function allowedString(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * HR: Bira čitljivu objavu traženog jezika, a zatim zadanog jezika sitea.
     * EN: Chooses a readable publication in the requested locale, then the site default locale.
     *
     * @param list<array<string, mixed>> $workflows
     * @return array<string, mixed>|null
     */
    private function preferredReadableWorkflow(
        array $workflows,
        string $requestedLanguage,
        string $defaultLanguage,
    ): ?array {
        $indexed = [];
        foreach ($workflows as $workflow) {
            if (!$this->workflow->isReadableWorkflow($workflow)) {
                continue;
            }

            $workflowLanguage = $this->normalizedLanguage(
                WorkspaceValue::string($workflow['language_code'] ?? ''),
                '',
            );
            if ($workflowLanguage !== '') {
                $indexed[$workflowLanguage] = $workflow;
            }
        }

        foreach (array_values(array_unique([$requestedLanguage, $defaultLanguage])) as $language) {
            if (isset($indexed[$language])) {
                return $indexed[$language];
            }
        }

        return null;
    }

    /**
     * HR: Normalizira kratku BCP-47 oznaku jezika ili vraća zadanu vrijednost.
     * EN: Normalizes a short BCP-47 locale tag or returns the supplied fallback.
     */
    private function normalizedLanguage(string $language, string $fallback): string
    {
        $language = strtolower(trim($language));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1 ? $language : $fallback;
    }
}
