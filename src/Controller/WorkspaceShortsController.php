<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBreadcrumbService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceShortsService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function in_array;
use function is_array;
use function is_scalar;
use function preg_match;
use function strtolower;
use function trim;

/**
 * EN: Serves one Workspace's public, ACL-filtered Shorts page.
 *
 * HR: Poslužuje javnu, ACL-filtriranu stranicu Sažetaka jednog područja.
 */
final readonly class WorkspaceShortsController
{
    /**
     * EN: Receives repository, ACL, business logic, and shared view/route services.
     *
     * HR: Prima repozitorij, ACL, poslovni servis i zajedničke view/route servise.
     */
    public function __construct(
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceBreadcrumbService $breadcrumbs,
        private WorkspaceShortsService $shorts,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
        private TranslatorInterface $translator,
        private WorkspaceThemeService $themes,
        private WorkspacePresentationRegistry $presentations,
    ) {
    }

    /**
     * EN: Displays only pages the current user may view and that have a publication.
     *
     * HR: Prikazuje samo stranice koje korisnik smije vidjeti i koje imaju objavu.
     */
    public function index(ServerRequestInterface $request, string $workspaceSlug): ResponseInterface
    {
        if (!$this->repository->tablesReady()) {
            return $this->viewRenderer->render('workspace/access-denied', [
                'title' => __('Područja još nisu instalirana'),
                'message' => __('Pokrenite početnu Workspace migraciju pa ponovno otvorite stranicu.'),
                'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
            ], true, 503);
        }

        $workspace = $this->repository->findWorkspaceBySlug($workspaceSlug);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        $language = $this->language($request);
        $workspace = $this->presentations->one($workspace, $language);
        $permissions = $this->access->workspacePermissions($workspace);
        if (!(bool)($permissions['can_view'] ?? false)) {
            return $this->accessDenied();
        }

        $this->themes->activate($workspace);

        $query = $request->getQueryParams();
        $model = $this->shorts->viewModel(
            $workspace,
            $language,
            $query,
            $this->config->siteDefaultLanguage(),
        );
        $treeVisible = $this->queryVisibility(
            $query['tree'] ?? null,
            $this->config->treeVisibleForWorkspace($workspace),
        );
        $displayOptionsVisible = $this->queryVisibility(
            $query['options'] ?? null,
            $this->config->shortsDisplayOptionsVisibleByDefault(),
        );

        $pageTitle = __('Sažetci') . ' · ' . WorkspaceValue::string($workspace['name'] ?? '');
        $pageDescription = __(
            'Objavljene stranice koje smijete vidjeti, prikazane kao kratki isječci.',
        );
        // EN: Shorts are not the Workspace homepage, so the last crumb remains a link out of the Shorts view.
        //
        // HR: Sažetci nisu naslovnica područja pa zadnji trag ostaje poveznica za izlaz iz prikaza sažetaka.
        $breadcrumbs = $this->breadcrumbs->build($workspace, null, [], $language, '', true);
        $tree = $this->pruneReadableTree(
            WorkspaceValue::rows($model['tree'] ?? null),
            true,
        );

        return $this->viewRenderer->render('workspace/shorts', [
            'title' => $pageTitle,
            'themeTitleContext' => 'integrated',
            'themeHero' => [
                'is_home' => false,
                'title' => $pageTitle,
                'subtitle' => $pageDescription,
            ],
            'workspace' => $workspace,
            'breadcrumbs' => $breadcrumbs,
            'tree' => $tree,
            'articles' => $model['articles'],
            'depth' => $model['depth'],
            'limit' => $model['limit'],
            'order' => $model['order'],
            'total' => $model['total'],
            'allAvailable' => $model['all_available'],
            'pagination' => $model['pagination'],
            'language' => $language,
            'shortsPath' => $model['shorts_path'],
            'workspacePath' => $model['workspace_path'],
            'treeVisibleByDefault' => $treeVisible,
            'displayOptionsVisibleByDefault' => $displayOptionsVisible,
            'treeBranchPath' => $this->pathFor(
                'workspace.tree.branch',
                '/workspaces/tree/branch',
            ),
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
            'assetsJsPath' => $this->pathFor('workspace.assets.js', '/workspaces/assets.js'),
        ]);
    }

    /**
     * EN: Keeps the root and first visible tree level for the initial Shorts
     *     view, leaving deeper branches for ACL-checked loading only when the
     *     user opens them.
     *
     * HR: Za početni prikaz Sažetaka zadržava korijensku i prvu vidljivu
     *     razinu stabla, a dublje grane ostavlja za ACL-provjereno učitavanje
     *     tek kada ih korisnik otvori.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private function pruneReadableTree(array $tree, bool $rootLevel): array
    {
        foreach ($tree as &$node) {
            $children = WorkspaceValue::rows($node['children'] ?? null);
            $node['has_children'] = $children !== [];
            $node['children_loaded'] = !$node['has_children'] || $rootLevel;
            $node['children'] = $rootLevel
            ? $this->pruneReadableTree($children, false)
            : [];
        }

        unset($node);

        return $tree;
    }

    /**
     * EN: Reads the selected or active locale with a safe site-default fallback.
     *
     * HR: Čita odabrani ili aktivni jezik uz sigurni fallback na zadani jezik sitea.
     */
    private function language(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $language = strtolower(WorkspaceValue::string(
            $query['lang'] ?? $this->translator->getLocale(),
        ));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1
        ? $language
        : $this->config->siteDefaultLanguage();
    }

    /**
     * EN: Reads `1/0`, `true/false`, `yes/no`, and `on/off` without ambiguous PHP casting.
     *
     * HR: Čita `1/0`, `true/false`, `yes/no` i `on/off` bez dvosmislenog PHP castanja.
     */
    private function queryVisibility(mixed $value, bool $fallback): bool
    {
        if (!is_scalar($value)) {
            return $fallback;
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return in_array($normalized, ['0', 'false', 'no', 'off'], true) ? false : $fallback;
    }

    /**
     * EN: Returns 403 without exposing content from an inaccessible Workspace.
     *
     * HR: Vraća 403 bez izlaganja sadržaja nedostupnog Područja.
     */
    private function accessDenied(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Nedozvoljen pristup'),
            'message' => __('Nemate pravo pregledavati ovo područje.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 403);
    }

    /**
     * EN: Returns 404 for a missing or removed public Workspace slug.
     *
     * HR: Vraća 404 za nepostojeći ili uklonjeni javni slug Područja.
     */
    private function notFound(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Područje nije pronađeno'),
            'message' => __('Traženo područje ne postoji ili više nije aktivno.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 404);
    }

    /**
     * EN: Generates a named route or stable fallback for an incomplete installation.
     *
     * HR: Generira named rutu ili stabilni fallback za nepotpunu instalaciju.
     */
    private function pathFor(string $routeName, string $fallback): string
    {
        return $this->urlGenerator->namedRouteExists($routeName)
        ? $this->urlGenerator->getPathFor($routeName)
        : $fallback;
    }
}
