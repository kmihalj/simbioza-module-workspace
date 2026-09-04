<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentViewed;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBacklinkService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBreadcrumbService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceIndexService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceNotificationBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_values;
use function http_build_query;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function mb_strlen;
use function rawurlencode;
use function rtrim;
use function str_repeat;
use function str_starts_with;
use function strtok;
use function strtolower;
use function trim;

final readonly class WorkspaceController
{
    /**
     * HR: Inicijalizira korisničke stranice, stablo, ACL akcije i editor most.
     * EN: Initializes user pages, tree operations, ACL actions, and the editor bridge.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceEditorBridge $editor,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceNotificationBridge $notifications,
        private TranslatorInterface $translator,
        private WorkspaceThemeService $themes,
        private WorkspaceMenuService $menus,
        private WorkspaceBreadcrumbService $breadcrumbs,
        private WorkspaceBacklinkService $backlinks,
        private WorkspacePresentationRegistry $presentations,
        private WorkspaceIndexService $workspaceIndex,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * HR: Prikazuje javna i korisniku dodijeljena područja.
     * EN: Displays public workspaces and workspaces assigned to the current user.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $tablesReady = $this->repository->tablesReady();
        $user = $this->access->currentUser();
        $isAdministrator = $this->access->isAdministrator($user);
        $query = $request->getQueryParams();
        $requestedPage = is_numeric($query['page'] ?? null) ? (int)$query['page'] : 1;
        $personalMode = $isAdministrator && $this->stringValue($query['personal'] ?? '') === '1';
        $prepared = $this->workspaceIndex->prepare(
            $tablesReady ? $this->decorateWorkspaces($this->access->visibleWorkspaces($user)) : [],
            $user,
            $isAdministrator,
            $personalMode,
            $requestedPage,
        );
        $indexPath = $this->pathFor('workspace.index', '/workspaces');

        return $this->viewRenderer->render('workspace/index', [
            'title' => $prepared['personal_mode'] ? __('Osobna područja') : __('Područja'),
            'tablesReady' => $tablesReady,
            'workspaces' => $prepared['items'],
            'otherPersonalWorkspaces' => $prepared['other_personal_workspaces'],
            'personalWorkspaceCount' => $prepared['personal_workspace_count'],
            'personalMode' => $prepared['personal_mode'],
            'pagination' => $prepared,
            'indexPath' => $indexPath,
            'personalPath' => $indexPath . '?personal=1',
            'canCreateWorkspace' => $tablesReady && $this->access->canCreateWorkspace(),
            'managePath' => $this->pathFor('workspace.manage', '/workspaces/manage'),
            'settingsPath' => $this->pathFor('workspace.settings', '/settings/workspaces'),
            'isAdministrator' => $isAdministrator,
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
        ]);
    }

    /**
     * HR: Prikazuje početnu stranicu područja ili pregled kada stablo nema početnu stranicu.
     * EN: Displays the workspace homepage or an overview when the tree has no homepage.
     */
    public function show(ServerRequestInterface $request, string $workspaceSlug): ResponseInterface
    {
        $workspace = $this->repository->findWorkspaceBySlug($workspaceSlug);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        $language = $this->language($request);
        $workspace = $this->presentations->one($workspace, $language);
        $homepageCandidate = $this->repository->homepageNode(
            $this->intValue($workspace['id'] ?? 0),
        );
        $homepageId = is_array($homepageCandidate)
        ? $this->intValue($homepageCandidate['id'] ?? 0)
        : 0;
        $visibleTree = $this->access->visibleTreeWindowForLanguages(
            $workspace,
            null,
            [$language],
            $homepageId,
        );
        $workflows = $this->repository->nodeWorkflowsForNodes(
            $this->treeNodeIds($visibleTree),
            $language,
        );
        $tree = $this->decorateTree(
            $visibleTree,
            $workspace,
            $workflows,
            $language,
        );
        $homepage = $homepageId > 0 ? $this->treeNodeById($tree, $homepageId) : null;

        return $this->renderWorkspace($request, $workspace, $tree, $homepage, $workflows);
    }

    /**
     * HR: Prikazuje odabranu stranicu ili slijedi link čvor nakon ACL provjere.
     * EN: Displays a selected page or follows a link node after an ACL check.
     */
    public function showNode(
        ServerRequestInterface $request,
        string $workspaceSlug,
        string $nodeSlug,
    ): ResponseInterface {
        $workspace = $this->repository->findWorkspaceBySlug($workspaceSlug);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        $language = $this->language($request);
        $workspace = $this->presentations->one($workspace, $language);

        $node = $this->repository->findNodeBySlug($this->intValue($workspace['id'] ?? 0), $nodeSlug);
        if (!is_array($node)) {
            return $this->accessDenied();
        }

        $nodePermissions = $this->access->nodePermissions($workspace, $node);
        if (!$nodePermissions['can_view']) {
            return $this->accessDenied();
        }

        $node['permissions'] = $nodePermissions;

        if ($this->stringValue($node['node_type'] ?? '') !== 'document') {
            return $this->redirectLinkNode($node);
        }

        $visibleTree = $this->access->visibleTreeWindowForLanguages(
            $workspace,
            null,
            [$language],
            $this->intValue($node['id'] ?? 0),
        );
        $workflows = $this->repository->nodeWorkflowsForNodes(
            $this->treeNodeIds($visibleTree),
            $language,
        );
        $tree = $this->decorateTree(
            $visibleTree,
            $workspace,
            $workflows,
            $language,
        );

        return $this->renderWorkspace($request, $workspace, $tree, $node, $workflows);
    }

    /**
     * HR: Prikazuje podatke područja, članstva i ACL prema efektivnim pravima.
     *     Stablo se uređuje izravno u lijevom panelu otvorenog područja.
     * EN: Displays Workspace data, membership, and ACL according to effective
     *     permissions. The tree is edited directly in the open Workspace sidebar.
     */
    public function manage(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->repository->tablesReady()) {
            return $this->migrationMissing();
        }

        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());
        $workspace = $this->workspaceFromInput($query);
        if (!is_array($workspace) && !$this->access->canCreateWorkspace()) {
            return $this->accessDenied();
        }

        $workspacePermissions = is_array($workspace)
        ? $this->access->workspacePermissions($workspace)
        : $this->emptyPermissions();
        if (
            is_array($workspace)
            && !$workspacePermissions['can_add']
            && !$workspacePermissions['can_edit']
            && !$workspacePermissions['can_delete']
            && !$workspacePermissions['can_manage']
        ) {
            return $this->accessDenied();
        }

        $language = $this->language($request);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $supportedLanguages = $this->config->supportedLanguages();
        $workspaceId = is_array($workspace) ? $this->intValue($workspace['id'] ?? 0) : 0;
        $isAdministrator = $this->access->isAdministrator();
        $workspaceThemeState = null;
        if (is_array($workspace) && $this->themes->isAvailable()) {
            $this->themes->activate($workspace);
            $workspaceThemeState = $this->themes->state($workspace);
        }

        $renderWorkspace = is_array($workspace)
        ? $this->repository->localizeWorkspace($workspace, $language, $primaryLanguage)
        : null;

        return $this->viewRenderer->render('workspace/manage', [
            'title' => is_array($renderWorkspace)
                ? $this->stringValue($renderWorkspace['name'] ?? '')
                : __('Novo područje'),
            'workspace' => $renderWorkspace,
            'activeLanguage' => $language,
            'primaryLanguage' => $primaryLanguage,
            'supportedLanguages' => $supportedLanguages,
            'localeFlagPaths' => $this->localeFlagPaths($supportedLanguages),
            'workspaceNameTranslations' => is_array($renderWorkspace)
                ? WorkspaceValue::stringKeyArray($renderWorkspace['name_translations_map'] ?? null)
                : [],
            'workspaceDescriptionTranslations' => is_array($renderWorkspace)
                ? WorkspaceValue::stringKeyArray($renderWorkspace['description_translations_map'] ?? null)
                : [],
            'workspacePermissions' => $workspacePermissions,
            'workspaceAclSubjects' => $workspaceId > 0
                ? $this->repository->workspaceAclSubjects($workspaceId)
                : [],
            'isAdministrator' => $isAdministrator,
            'currentUser' => $this->access->currentUser(),
            'savePath' => $this->pathFor('workspace.save', '/workspaces/save'),
            'deletePath' => $this->pathFor('workspace.delete', '/workspaces/delete'),
            'aclSavePath' => $this->pathFor('workspace.acl.save', '/workspaces/acl'),
            'subjectSearchPath' => $this->pathFor(
                'workspace.acl.subjects',
                '/workspaces/acl/subjects',
            ),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
            'workspaceViewPath' => is_array($workspace)
                ? $this->workspacePath($this->stringValue($workspace['slug'] ?? ''))
                : '',
            'exportPath' => is_array($workspace)
                && ($isAdministrator || $workspacePermissions['can_manage'])
                ? $this->pathFor('workspace.export', '/workspaces/export')
                    . '?workspace=' . rawurlencode($this->stringValue($workspace['slug'] ?? ''))
                : '',
            'workspaceThemePath' => is_array($workspace) && is_array($workspaceThemeState)
                ? $this->pathFor('workspace.theme', '/workspaces/theme')
                    . '?workspace=' . rawurlencode($this->stringValue($workspace['slug'] ?? ''))
                : '',
            'workspaceThemeState' => $workspaceThemeState,
            'workspaceThemeLabel' => is_array($workspaceThemeState)
                ? $this->localizedThemeLabel($workspaceThemeState)
                : '',
            'workspaceMenuPath' => is_array($workspace)
                && $workspacePermissions['can_manage']
                && $this->menus->isAvailable()
                ? $this->pathFor('workspace.menu', '/workspaces/menu')
                    . '?workspace=' . rawurlencode($this->stringValue($workspace['slug'] ?? ''))
                : '',
            'workspaceBackupPath' => is_array($workspace)
                && $workspacePermissions['can_manage']
                && class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class)
                && $this->urlGenerator->namedRouteExists('workspace.backup')
                ? $this->pathFor('workspace.backup', '/workspaces/backup')
                    . '?workspace=' . rawurlencode($this->stringValue($workspace['slug'] ?? ''))
                : '',
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
            'assetsJsPath' => $this->pathFor('workspace.assets.js', '/workspaces/assets.js'),
        ]);
    }

    /**
     * HR: Kreira ili mijenja područje nakon provjere prava upravljanja.
     * EN: Creates or updates a workspace after checking manage permission.
     */
    public function saveWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspaceId = $this->intValue($body['id'] ?? 0);
        $existing = $workspaceId > 0 ? $this->repository->findWorkspaceById($workspaceId) : null;
        if (
            (is_array($existing) && !$this->access->workspacePermissions($existing)['can_manage'])
            || (!is_array($existing) && !$this->access->canCreateWorkspace())
        ) {
            return $this->accessDenied();
        }

        try {
            $body['primary_language'] = $this->config->siteDefaultLanguage();
            $body['supported_languages'] = $this->config->supportedLanguages();
            if (!is_array($existing) && !array_key_exists('visibility', $body)) {
                $body['visibility'] = $this->config->defaultVisibility();
            }

            $workspace = $this->repository->saveWorkspace($body, $this->currentUserId());
            $this->success(__('Područje je spremljeno.'));

            return $this->responseFactory->redirect(
                $this->managePath($this->stringValue($workspace['slug'] ?? '')),
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace save failed.', [
                'module' => 'workspace',
                'action' => 'workspace.save',
                'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());

            return $this->responseFactory->redirect($this->managePath(
                is_array($existing) ? $this->stringValue($existing['slug'] ?? '') : '',
            ));
        }
    }

    /**
     * HR: Soft-briše područje kada korisnik ima manage pravo.
     * EN: Soft-deletes a workspace when the user has manage permission.
     */
    public function deleteWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromInput($this->body($request));
        if (!is_array($workspace) || !$this->access->workspacePermissions($workspace)['can_manage']) {
            return $this->accessDenied();
        }

        $this->repository->softDeleteWorkspace(
            $this->intValue($workspace['id'] ?? 0),
            $this->currentUserId(),
        );
        $this->success(__('Područje je obrisano.'));

        return $this->responseFactory->redirect($this->pathFor('workspace.index', '/workspaces'));
    }

    /**
     * HR: Administrator vraća soft-obrisano područje uz automatsko rješavanje slug konflikta.
     * EN: An administrator restores a soft-deleted workspace with automatic slug conflict resolution.
     */
    public function restoreWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $body = $this->body($request);
        try {
            $workspace = $this->repository->restoreWorkspace(
                $this->intValue($body['workspace_id'] ?? 0),
                $this->stringValue($body['slug'] ?? ''),
                $this->currentUserId(),
            );
            $this->success(__('Područje je vraćeno.'));

            return $this->responseFactory->redirect(
                $this->managePath($this->stringValue($workspace['slug'] ?? '')),
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace restore failed.', [
                'module' => 'workspace',
                'action' => 'workspace.restore',
                'workspace_id' => $this->intValue($body['workspace_id'] ?? 0) ?: null,
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());

            return $this->responseFactory->redirect(
                $this->pathFor('workspace.settings.deleted', '/settings/workspaces/deleted'),
            );
        }
    }

    /**
     * HR: Sprema korisnička i grupna prava područja.
     * EN: Saves user and group workspace permissions.
     */
    public function saveWorkspaceAcl(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace) || !$this->access->workspacePermissions($workspace)['can_manage']) {
            return $this->accessDenied();
        }

        $acl = WorkspaceValue::stringKeyArray($body['acl'] ?? null);
        $this->repository->replaceWorkspaceAcl($this->intValue($workspace['id'] ?? 0), $acl);
        $this->access->clearRequestCache();
        $this->success(__('Prava područja su spremljena.'));

        return $this->responseFactory->redirect(
            $this->managePath($this->stringValue($workspace['slug'] ?? '')),
        );
    }

    /**
     * HR: Vraća najviše mali broj korisnika ili grupa koji odgovaraju upitu
     *     ACL ili administracijskog pickera. Rezultat nikada ne izlaže cijeli Auth imenik.
     * EN: Returns a small bounded set of users or groups matching an ACL or administration
     *     picker query. The endpoint never exposes the complete Auth directory.
     */
    public function searchAclSubjects(ServerRequestInterface $request): ResponseInterface
    {
        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());
        $workspace = $this->workspaceFromInput($query);
        if (is_array($workspace)) {
            if (!$this->access->workspacePermissions($workspace)['can_manage']) {
                return $this->responseFactory->json(['ok' => false, 'error' => __('Nedozvoljen pristup')], 403);
            }
        } elseif (!$this->access->isAdministrator()) {
            return $this->responseFactory->json(['ok' => false, 'error' => __('Nedozvoljen pristup')], 403);
        }

        $category = $this->stringValue($query['type'] ?? '');
        if (
            !in_array(
                $category,
                [WorkspaceRepository::SUBJECT_USER, WorkspaceRepository::SUBJECT_GROUP],
                true,
            )
        ) {
            return $this->responseFactory->json(['ok' => false, 'error' => __('Neispravan tip subjekta.')], 422);
        }

        $mode = $this->stringValue($query['mode'] ?? '');
        $search = trim($this->stringValue($query['q'] ?? ''));
        if (
            in_array($mode, ['acl', 'creator', 'restriction', 'direct-permission'], true)
            && mb_strlen($search) < 2
        ) {
            return $this->responseFactory->json(['ok' => true, 'results' => []]);
        }

        if (
            is_array($workspace)
            && $category === WorkspaceRepository::SUBJECT_USER
            && $mode === 'restriction'
        ) {
            $nodeId = $this->intValue($query['node_id'] ?? 0);
            $node = $this->repository->findNodeById($nodeId);
            if (
                !is_array($node)
                || $this->intValue($node['workspace_id'] ?? 0)
                    !== $this->intValue($workspace['id'] ?? 0)
            ) {
                return $this->responseFactory->json(
                    ['ok' => false, 'error' => __('Stavka stabla nije pronađena.')],
                    404,
                );
            }

            return $this->responseFactory->json([
                'ok' => true,
                'results' => $this->repository->searchRestrictionUsers(
                    $this->intValue($workspace['id'] ?? 0),
                    $nodeId,
                    $search,
                ),
            ]);
        }

        $results = $this->repository->searchDirectorySubjects($category, $search);
        if ($mode === 'creator') {
            $results = array_values(array_filter(
                $results,
                static fn(array $subject): bool => ($subject['type'] ?? '') === $category,
            ));
        }

        return $this->responseFactory->json([
            'ok' => true,
            'results' => $results,
        ]);
    }

    /**
     * HR: Iz otvorenog područja kreira običnu stranicu, povezuje novi HTML
     * dokument i odmah vodi korisnika u editor. Prva stranica automatski postaje
     * početna kako prazno područje ne bi zahtijevalo dodatno administriranje.
     *
     * EN: Creates a regular page from an open Workspace, links a new HTML
     * document, and immediately opens the editor. The first page automatically
     * becomes the homepage so an empty Workspace needs no extra administration.
     */
    public function createPage(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        $workspacePath = $this->workspacePath($this->stringValue($workspace['slug'] ?? ''));
        $parentId = $this->intValue($body['parent_id'] ?? 0);
        if (!$this->canCreatePageUnderParent($workspace, $parentId)) {
            return $this->accessDenied();
        }

        $documentKey = '';
        try {
            $language = $this->language($request);
            $primaryLanguage = $this->config->siteDefaultLanguage();
            $supportedLanguages = $this->config->supportedLanguages();
            $titleTranslations = $this->repository->translationMap(
                $body['title_translations'] ?? [],
            );
            $legacyTitle = $this->stringValue($body['title'] ?? '');
            if ($titleTranslations === [] && $legacyTitle !== '') {
                $titleTranslations[$primaryLanguage] = $legacyTitle;
            }

            $title = $this->repository->localizedValue(
                $titleTranslations,
                $language,
                $primaryLanguage,
            );
            $slug = $this->stringValue($body['slug'] ?? '');
            $documentKey = $this->editor->createDocument($title, $slug, $language);
            $node = $this->repository->saveNode(
                $this->intValue($workspace['id'] ?? 0),
                [
                    'title' => $title,
                    'title_translations' => $titleTranslations,
                    'primary_language' => $primaryLanguage,
                    'supported_languages' => $supportedLanguages,
                    'slug' => $slug,
                    'node_type' => 'document',
                    'document_key' => $documentKey,
                    'parent_id' => $parentId,
                    'sort_order' => 100,
                    'is_homepage' => !$this->workspaceHasHomepage($workspace),
                ],
                $this->currentUserId(),
            );
            $latestVersion = $this->editor->latestVersionNumber($documentKey, $language);
            if ($latestVersion > 0) {
                $this->editor->markVersionDraft($documentKey, $language, $latestVersion);
                $this->workflow->markDocumentDraft(
                    $documentKey,
                    $language,
                    $latestVersion,
                    $this->currentUserId(),
                );
            }

            $this->success(__('Stranica je kreirana. Sada uredite njezin sadržaj.'));

            return $this->responseFactory->redirect(
                $this->editor->editorPath(
                    $this->stringValue($node['document_key'] ?? $documentKey),
                    $language,
                ),
            );
        } catch (Throwable $throwable) {
            if ($documentKey !== '') {
                $this->editor->deleteDocument($documentKey);
            }

            $this->logger->warning('Workspace page creation failed.', [
                'module' => 'workspace',
                'action' => 'workspace.page.create',
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'parent_node_id' => $parentId > 0 ? $parentId : null,
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());

            return $this->responseFactory->redirect($workspacePath);
        }
    }

    /**
     * HR: Učitava sadržaj zajedničkog modala za uređivanje jednog čvora tek
     * nakon klika u stablu. Time veliko stablo ne renderira desetke skrivenih
     * obrazaca, a svaka akcija i dalje prolazi isti serverski ACL.
     *
     * EN: Loads the shared edit-modal content for one node only after a tree
     * click. This keeps large trees from rendering dozens of hidden forms while
     * every action still passes through the same server-side ACL.
     */
    public function nodeDialog(ServerRequestInterface $request): ResponseInterface
    {
        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());
        $workspace = $this->workspaceFromInput($query);
        if (!is_array($workspace)) {
            return $this->responseFactory->html(
                '<div class="modal-body"><div class="alert alert-danger mb-0">'
                . htmlspecialchars(__('Sadržaj nije pronađen'), ENT_QUOTES, 'UTF-8')
                . '</div></div>',
                404,
            );
        }

        $nodeId = $this->intValue($query['node_id'] ?? 0);
        if ($nodeId <= 0) {
            return $this->createNodeDialog($request, $workspace, $query);
        }

        $node = $this->repository->findNodeById($nodeId);
        if (!is_array($node)) {
            return $this->responseFactory->html('', 404);
        }

        if (
            $this->intValue($node['workspace_id'] ?? 0) !== $this->intValue($workspace['id'] ?? 0)
        ) {
            return $this->responseFactory->html('', 404);
        }

        $permissions = $this->access->nodePermissions($workspace, $node);
        $workspacePermissions = $this->access->workspacePermissions($workspace);
        $canManagePagePermissions = (bool)($workspacePermissions['can_manage'] ?? false);
        if (
            !$permissions['can_edit']
            && !$permissions['can_delete']
            && !$permissions['can_manage']
            && !$canManagePagePermissions
        ) {
            return $this->responseFactory->html(
                '<div class="modal-body"><div class="alert alert-danger mb-0">'
                . htmlspecialchars(
                    __('Nemate potrebna prava za ovo područje ili stranicu.'),
                    ENT_QUOTES,
                    'UTF-8',
                )
                . '</div></div>',
                403,
            );
        }

        $workspaceId = $this->intValue($workspace['id'] ?? 0);
        $allNodes = $this->repository->nodesForWorkspace($workspaceId);
        $permissionsByNode = $this->access->nodePermissionsForNodes($workspace, $allNodes);
        $nodes = [];
        foreach ($allNodes as $candidate) {
            $candidatePermissions = $permissionsByNode[$this->intValue($candidate['id'] ?? 0)]
            ?? $this->permissionArray([]);
            if (!$candidatePermissions['can_view']) {
                continue;
            }

            $candidate['permissions'] = $candidatePermissions;
            $nodes[] = $candidate;
        }

        $language = $this->language($request);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $supportedLanguages = $this->config->supportedLanguages();
        $workspace = $this->repository->localizeWorkspace($workspace, $language, $primaryLanguage);
        $nodes = array_map(
            fn(array $candidate): array => $this->repository->localizeNode(
                $candidate,
                $language,
                $primaryLanguage,
            ),
            $nodes,
        );
        $node = $this->repository->localizeNode($node, $language, $primaryLanguage);
        $node['title_translation_values'] = WorkspaceValue::stringKeyArray(
            $node['title_translations_map'] ?? null,
        );
        $isAdministrator = $this->access->isAdministrator();
        $node['permissions'] = $permissions;
        $node['restrictions'] = $this->repository->nodeAclRows(
            $this->intValue($node['id'] ?? 0),
        );
        $node['labels'] = $this->repository->nodeLabels($this->intValue($node['id'] ?? 0));
        $node['properties'] = $this->repository->nodeProperties($this->intValue($node['id'] ?? 0));

        $html = $this->viewRenderer->renderPartial('workspace/node-dialog', [
            'workspace' => $workspace,
            'restrictionSubjects' => $this->repository->nodeRestrictionSubjects(
                $workspaceId,
                $this->intValue($node['id'] ?? 0),
            ),
            'directPermissionSubjects' => $canManagePagePermissions
                ? $this->repository->nodeDirectPermissionSubjects($this->intValue($node['id'] ?? 0))
                : [],
            'canManagePagePermissions' => $canManagePagePermissions,
            'node' => $node,
            'nodes' => $this->orderNodesForManagement($nodes),
            'editorAvailable' => $this->editor->isAvailable(),
            'editorDocuments' => $isAdministrator
                ? $this->editor->documents($this->language($request))
                : [],
            'canAttachExistingDocuments' => $isAdministrator,
            'nodeSavePath' => $this->pathFor('workspace.node.save', '/workspaces/node/save'),
            'nodeDeletePath' => $this->pathFor('workspace.node.delete', '/workspaces/node/delete'),
            'nodeAclSavePath' => $this->pathFor(
                'workspace.node.acl.save',
                '/workspaces/node/acl',
            ),
            'nodeDirectPermissionSavePath' => $this->pathFor(
                'workspace.node.direct-permissions.save',
                '/workspaces/node/direct-permissions',
            ),
            'subjectSearchPath' => $this->pathFor(
                'workspace.acl.subjects',
                '/workspaces/acl/subjects',
            ),
            'returnNodeId' => $this->intValue($query['return_node_id'] ?? 0),
            'activeLanguage' => $language,
            'primaryLanguage' => $primaryLanguage,
            'supportedLanguages' => $supportedLanguages,
            'localeFlagPaths' => $this->localeFlagPaths($supportedLanguages),
        ]);

        return $this->responseFactory->html($html, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * HR: Učitava organizator cijelog stabla tek kada ga upravitelj otvori.
     *     Velika područja tako ne šalju stotine skrivenih redaka i ikona pri
     *     svakom običnom pregledu stranice.
     *
     * EN: Loads the full tree organizer only when a manager opens it. Large
     *     workspaces therefore do not send hundreds of hidden rows and icons
     *     with every regular page view.
     */
    public function treeOrganizer(ServerRequestInterface $request): ResponseInterface
    {
        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());
        $workspace = $this->workspaceFromInput($query);
        if (!is_array($workspace)) {
            return $this->responseFactory->html('', 404);
        }

        $workspacePermissions = $this->access->workspacePermissions($workspace);
        if (!(bool)($workspacePermissions['can_manage'] ?? false)) {
            return $this->responseFactory->html(
                '<div class="alert alert-danger mb-0">'
                . htmlspecialchars(
                    __('Nemate potrebna prava za ovo područje ili stranicu.'),
                    ENT_QUOTES,
                    'UTF-8',
                )
                . '</div>',
                403,
            );
        }

        $workspaceId = $this->intValue($workspace['id'] ?? 0);
        $allNodes = $this->repository->nodesForWorkspace($workspaceId);
        $permissionsByNode = $this->access->nodePermissionsForNodes($workspace, $allNodes);
        $managementNodes = [];
        foreach ($allNodes as $candidate) {
            $permissions = $permissionsByNode[$this->intValue($candidate['id'] ?? 0)]
            ?? $this->permissionArray([]);
            if (!(bool)($permissions['can_view'] ?? false) || !(bool)($permissions['can_edit'] ?? false)) {
                return $this->responseFactory->html(
                    '<div class="alert alert-danger mb-0">'
                    . htmlspecialchars(
                        __('Stablo nije moguće uređivati jer ne vidite ili ne smijete uređivati sve stavke.'),
                        ENT_QUOTES,
                        'UTF-8',
                    )
                    . '</div>',
                    403,
                );
            }

            $candidate['permissions'] = $permissions;
            $managementNodes[] = $candidate;
        }

        $language = $this->language($request);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $workspace = $this->repository->localizeWorkspace($workspace, $language, $primaryLanguage);
        $managementNodes = array_map(
            function (array $candidate) use ($language, $primaryLanguage): array {
                $candidate = $this->repository->localizeNode(
                    $candidate,
                    $language,
                    $primaryLanguage,
                );
                $candidate['title_translation_values'] = WorkspaceValue::stringKeyArray(
                    $candidate['title_translations_map'] ?? null,
                );

                return $candidate;
            },
            $managementNodes,
        );

        $html = $this->viewRenderer->renderPartial('workspace/tree-organizer', [
            'workspace' => $workspace,
            'nodes' => $this->orderNodesForManagement($managementNodes),
            'activeNodeId' => $this->intValue($query['active_node_id'] ?? 0),
            'treeOrderSavePath' => $this->pathFor(
                'workspace.tree.order.save',
                '/workspaces/tree/order',
            ),
            'nodeDialogPath' => $this->pathFor(
                'workspace.node.dialog',
                '/workspaces/node/dialog',
            ),
        ]);
        // The organizer contains only controls and escaped labels, so whitespace
        // between tags has no visual meaning. Removing it keeps very large trees
        // from transferring several megabytes of template indentation.
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        return $this->responseFactory->html($html, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * HR: Obrazac za novu stavku učitava tek nakon klika. Popis Editor
     *     dokumenata zato više ne usporava samo otvaranje velikog organizatora.
     * EN: Loads the new-item form only after a click. The Editor document list
     *     therefore no longer slows down opening a large organizer by itself.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $query
     */
    private function createNodeDialog(
        ServerRequestInterface $request,
        array $workspace,
        array $query,
    ): ResponseInterface {
        $workspacePermissions = $this->access->workspacePermissions($workspace);
        if (
            !(bool)($workspacePermissions['can_add'] ?? false)
            && !(bool)($workspacePermissions['can_manage'] ?? false)
        ) {
            return $this->responseFactory->html('', 403);
        }

        $workspaceId = $this->intValue($workspace['id'] ?? 0);
        $allNodes = $this->repository->nodesForWorkspace($workspaceId);
        $permissionsByNode = $this->access->nodePermissionsForNodes($workspace, $allNodes);
        $nodes = [];
        foreach ($allNodes as $candidate) {
            $candidatePermissions = $permissionsByNode[$this->intValue($candidate['id'] ?? 0)]
            ?? $this->permissionArray([]);
            if (!(bool)($candidatePermissions['can_view'] ?? false)) {
                continue;
            }

            $candidate['permissions'] = $candidatePermissions;
            $nodes[] = $candidate;
        }

        $language = $this->language($request);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $supportedLanguages = $this->config->supportedLanguages();
        $localizedWorkspace = $this->repository->localizeWorkspace(
            $workspace,
            $language,
            $primaryLanguage,
        );
        $nodes = array_map(
            fn(array $candidate): array => $this->repository->localizeNode(
                $candidate,
                $language,
                $primaryLanguage,
            ),
            $nodes,
        );

        $html = $this->viewRenderer->renderPartial('workspace/node-create-dialog', [
            'workspace' => $localizedWorkspace,
            'node' => ['node_type' => 'internal_link'],
            'nodes' => $this->orderNodesForManagement($nodes),
            'editorAvailable' => $this->editor->isAvailable(),
            'editorDocuments' => $this->access->isAdministrator()
                ? $this->editor->documents($language)
                : [],
            'canAttachExistingDocuments' => $this->access->isAdministrator(),
            'workspaceCanAdd' => (bool)($workspacePermissions['can_add'] ?? false),
            'nodeSavePath' => $this->pathFor('workspace.node.save', '/workspaces/node/save'),
            'returnNodeId' => $this->intValue($query['return_node_id'] ?? 0),
            'activeLanguage' => $language,
            'primaryLanguage' => $primaryLanguage,
            'supportedLanguages' => $supportedLanguages,
            'localeFlagPaths' => $this->localeFlagPaths($supportedLanguages),
        ]);

        return $this->responseFactory->html($html, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * HR: Učitava jednu ACL-filtriranu granu čitljivog stabla na zahtjev.
     *     Velika područja zato pri svakom pregledu ne šalju cijelo skriveno
     *     stablo, dok aktivna grana i spremljene otvorene grane ostaju dostupne.
     *
     * EN: Loads one ACL-filtered readable-tree branch on demand. Large
     *     Workspaces therefore do not send the complete hidden tree with every
     *     page view while the active and remembered expanded branches remain
     *     available.
     */
    public function treeBranch(ServerRequestInterface $request): ResponseInterface
    {
        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());
        $workspace = $this->workspaceFromInput($query);
        if (!is_array($workspace)) {
            return $this->responseFactory->html('', 404, ['Cache-Control' => 'no-store']);
        }

        if (!$this->access->canAccessWorkspace($workspace)) {
            return $this->responseFactory->html('', 403, ['Cache-Control' => 'no-store']);
        }

        $language = $this->language($request);
        $workspace = $this->presentations->one($workspace, $language);
        $parentId = $this->intValue($query['parent_id'] ?? 0);
        if ($parentId <= 0) {
            return $this->responseFactory->html('', 404, ['Cache-Control' => 'no-store']);
        }

        $visibleTree = $this->access->visibleTreeBranchForLanguages(
            $workspace,
            null,
            [$language],
            $parentId,
        );
        $workflows = $this->repository->nodeWorkflowsForNodes(
            $this->treeNodeIds($visibleTree),
            $language,
        );
        $children = $this->decorateTree($visibleTree, $workspace, $workflows, $language);
        $activeNodeId = $this->intValue($query['active_node_id'] ?? 0);
        $html = $this->viewRenderer->renderPartial('workspace/tree', [
            'nodes' => $children,
            'activeNodeId' => $activeNodeId > 0 ? $activeNodeId : null,
            'level' => max(2, $this->intValue($query['level'] ?? 2)),
            'treeBranchPath' => $this->pathFor('workspace.tree.branch', '/workspaces/tree/branch'),
            'workspaceId' => $this->intValue($workspace['id'] ?? 0),
            'language' => $language,
        ]);

        return $this->responseFactory->html($html, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * HR: Kreira, povezuje ili premješta čvor stabla uz provjeru add/edit prava.
     * EN: Creates, links, or moves a tree node after checking add/edit permission.
     */
    public function saveNode(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        $nodeId = $this->intValue($body['id'] ?? 0);
        $existing = $nodeId > 0 ? $this->repository->findNodeById($nodeId) : null;
        if (is_array($existing)) {
            if (!array_key_exists('parent_id', $body)) {
                $body['parent_id'] = $existing['parent_id'] ?? null;
            }

            if (!array_key_exists('sort_order', $body)) {
                $body['sort_order'] = $existing['sort_order'] ?? 100;
            }
        }

        $parentId = $this->intValue($body['parent_id'] ?? 0);
        if (is_array($existing)) {
            if (
                $this->intValue($existing['workspace_id'] ?? 0) !== $this->intValue($workspace['id'] ?? 0)
                || !$this->access->nodePermissions($workspace, $existing)['can_edit']
            ) {
                return $this->accessDenied();
            }

            $existingParentId = $this->intValue($existing['parent_id'] ?? 0);
            if ($existingParentId !== $parentId && !$this->canAddUnderParent($workspace, $parentId)) {
                return $this->accessDenied();
            }
        } elseif (!$this->canAddUnderParent($workspace, $parentId)) {
            return $this->accessDenied();
        }

        try {
            $language = $this->language($request);
            $primaryLanguage = $this->config->siteDefaultLanguage();
            $body['primary_language'] = $primaryLanguage;
            $body['supported_languages'] = $this->config->supportedLanguages();
            $titleTranslations = $this->repository->translationMap(
                $body['title_translations'] ?? [],
            );
            $legacyTitle = $this->stringValue($body['title'] ?? '');
            if ($titleTranslations === [] && $legacyTitle !== '') {
                $titleTranslations[$primaryLanguage] = $legacyTitle;
            }

            $body['title_translations'] = $titleTranslations;
            $body['title'] = $this->repository->localizedValue(
                $titleTranslations,
                $language,
                $primaryLanguage,
            );
            $nodeType = $this->stringValue($body['node_type'] ?? 'document');
            $documentKey = $this->stringValue($body['document_key'] ?? '');
            $existingDocumentKey = is_array($existing)
            ? $this->stringValue($existing['document_key'] ?? '')
            : '';
            $createdDocument = false;

            /*
             * HR: Obični urednik smije kreirati novi dokument ili zadržati dokument svojeg čvora.
             *     Povezivanje drugog postojećeg dokumenta ostaje administratorska operacija.
             * EN: A regular editor may create a new document or keep their node's current document.
             *     Attaching a different existing document remains an administrator operation.
             */
            if (
                !$this->access->isAdministrator()
                && ($documentKey !== $existingDocumentKey
                    || ($existingDocumentKey !== '' && $nodeType !== 'document'))
            ) {
                return $this->accessDenied();
            }

            if ($nodeType === 'document' && $documentKey !== '' && !$this->editor->hasDocument($documentKey)) {
                throw new RuntimeException(__('HTML dokument nije pronađen.'));
            }

            $this->assertInternalLinkCanResolve($body);
            if (
                $nodeType === 'document'
                && $documentKey === ''
            ) {
                $body['document_key'] = $this->editor->createDocument(
                    $this->stringValue($body['title']),
                    $this->stringValue($body['slug'] ?? ''),
                    $language,
                );
                $createdDocument = true;
            }

            $savedNode = $this->repository->saveNode(
                $this->intValue($workspace['id'] ?? 0),
                $body,
                $this->currentUserId(),
            );
            $savedNodeId = $this->intValue($savedNode['id'] ?? 0);
            if ($savedNodeId > 0 && $nodeType === 'document') {
                $this->repository->replaceNodeLabels(
                    $savedNodeId,
                    preg_split('/[,;\r\n]+/u', $this->stringValue($body['labels'] ?? '')) ?: [],
                );
                $this->repository->replaceNodeProperties(
                    $savedNodeId,
                    $this->propertiesFromBody($body['properties'] ?? []),
                );
            }

            $savedDocumentKey = $this->stringValue($savedNode['document_key'] ?? '');
            if ($createdDocument && $savedDocumentKey !== '') {
                $latestVersion = $this->editor->latestVersionNumber($savedDocumentKey, $language);
                if ($latestVersion > 0) {
                    $this->editor->markVersionDraft($savedDocumentKey, $language, $latestVersion);
                    $this->workflow->markDocumentDraft(
                        $savedDocumentKey,
                        $language,
                        $latestVersion,
                        $this->currentUserId(),
                    );
                }
            }

            if ($existingDocumentKey !== '' && $existingDocumentKey !== $savedDocumentKey) {
                $this->editor->deleteDocument($existingDocumentKey);
            }

            $this->success(__('Stablo stranica je spremljeno.'));
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace tree item save failed.', [
                'module' => 'workspace',
                'action' => 'workspace.node.save',
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'node_id' => $nodeId > 0 ? $nodeId : null,
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());
        }

        return $this->responseFactory->redirect(
            $this->actionReturnPath($workspace, $body),
        );
    }

    /**
     * HR: Pretvara retke korisničke forme u strukturirana svojstva stranice.
     * EN: Converts user-form rows into structured page properties.
     *
     * @return list<array<string,mixed>>
     */
    private function propertiesFromBody(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $labels = is_array($value['label'] ?? null) ? $value['label'] : [];
        $types = is_array($value['type'] ?? null) ? $value['type'] : [];
        $values = is_array($value['value'] ?? null) ? $value['value'] : [];
        $properties = [];
        foreach ($labels as $index => $label) {
            if (!is_scalar($label)) {
                continue;
            }

            if (trim((string)$label) === '') {
                continue;
            }

            $properties[] = [
                'label' => trim((string)$label),
                'type' => is_scalar($types[$index] ?? null) ? (string)$types[$index] : 'text',
                'value' => is_scalar($values[$index] ?? null) ? (string)$values[$index] : '',
                'sort_order' => ((int)$index + 1) * 10,
            ];
        }

        return $properties;
    }

    /**
     * HR: Sprema kompletan vizualno uređeni raspored stabla samo kada korisnik
     *     smije uređivati svaki aktivni čvor područja.
     * EN: Saves the complete visually edited tree arrangement only when the
     *     user may edit every active node in the Workspace.
     */
    public function saveTreeOrder(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace)) {
            return $this->notFound();
        }

        if (!$this->access->workspacePermissions($workspace)['can_manage']) {
            return $this->accessDenied();
        }

        $nodes = $this->repository->nodesForWorkspace($this->intValue($workspace['id'] ?? 0));
        $permissionsByNode = $this->access->nodePermissionsForNodes($workspace, $nodes);
        foreach ($nodes as $node) {
            $permissions = $permissionsByNode[$this->intValue($node['id'] ?? 0)]
            ?? $this->permissionArray([]);
            if (!$permissions['can_edit']) {
                return $this->accessDenied();
            }
        }

        try {
            $this->repository->reorderNodes(
                $this->intValue($workspace['id'] ?? 0),
                WorkspaceValue::rows($body['items'] ?? null),
                $this->currentUserId(),
            );
            $this->success(__('Hijerarhija i redoslijed stranica su spremljeni.'));
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace tree reorder failed.', [
                'module' => 'workspace',
                'action' => 'workspace.tree.reorder',
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());
        }

        return $this->responseFactory->redirect(
            $this->actionReturnPath($workspace, $body),
        );
    }

    /**
     * HR: Briše cijelu podgranu tek kada korisnik ima delete pravo na svaki čvor.
     * EN: Deletes a complete subtree only when the user has delete permission on every node.
     */
    public function deleteNode(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        $node = $this->repository->findNodeById($this->intValue($body['node_id'] ?? 0));
        if (!is_array($workspace) || !is_array($node)) {
            return $this->notFound();
        }

        if ($this->intValue($node['workspace_id'] ?? 0) !== $this->intValue($workspace['id'] ?? 0)) {
            return $this->notFound();
        }

        $subtree = $this->repository->nodesInSubtree(
            $this->intValue($workspace['id'] ?? 0),
            $this->intValue($node['id'] ?? 0),
        );
        foreach ($subtree as $subtreeNode) {
            if (!$this->access->nodePermissions($workspace, $subtreeNode)['can_delete']) {
                return $this->accessDenied();
            }
        }

        try {
            $documentKeys = [];
            foreach ($subtree as $subtreeNode) {
                if (
                    $this->stringValue($subtreeNode['node_type'] ?? '') === 'document'
                    && $this->stringValue($subtreeNode['document_key'] ?? '') !== ''
                ) {
                    $documentKeys[] = $this->stringValue($subtreeNode['document_key'] ?? '');
                }
            }

            $this->repository->disableNodeTree(
                $this->intValue($workspace['id'] ?? 0),
                $this->intValue($node['id'] ?? 0),
                $this->currentUserId(),
            );
            foreach ($documentKeys as $documentKey) {
                $this->editor->deleteDocument($documentKey);
            }

            $this->success(__('Stranica i njezina podgrana su obrisane.'));
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace subtree deletion failed.', [
                'module' => 'workspace',
                'action' => 'workspace.node.delete',
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'node_id' => $this->intValue($node['id'] ?? 0),
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());
        }

        return $this->responseFactory->redirect(
            $this->actionReturnPath($workspace, $body),
        );
    }

    /**
     * HR: Sprema nasljedna ograničenja čvora samo za postojeće članove područja.
     * EN: Saves inherited node restrictions only for existing workspace members.
     */
    public function saveNodeAcl(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        $node = $this->repository->findNodeById($this->intValue($body['node_id'] ?? 0));
        if (!is_array($workspace) || !is_array($node)) {
            return $this->notFound();
        }

        if ($this->intValue($node['workspace_id'] ?? 0) !== $this->intValue($workspace['id'] ?? 0)) {
            return $this->notFound();
        }

        $permissions = $this->access->workspacePermissions($workspace);
        if (!$permissions['can_manage']) {
            return $this->accessDenied();
        }

        $acl = WorkspaceValue::stringKeyArray($body['acl'] ?? null);
        $this->repository->replaceNodeAcl(
            $this->intValue($workspace['id'] ?? 0),
            $this->intValue($node['id'] ?? 0),
            $acl,
        );
        $this->access->clearRequestCache();
        $this->success(__('Ograničenja stranice su spremljena i nasljeđuju ih potomci.'));

        return $this->responseFactory->redirect(
            $this->actionReturnPath($workspace, $body),
        );
    }

    /**
     * HR: Sprema izravna prava korisnika samo za odabranu stranicu.
     * EN: Saves direct user grants for the selected page only.
     */
    public function saveNodeDirectPermissions(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        $node = $this->repository->findNodeById($this->intValue($body['node_id'] ?? 0));
        if (!is_array($workspace) || !is_array($node)) {
            return $this->notFound();
        }

        if ($this->intValue($node['workspace_id'] ?? 0) !== $this->intValue($workspace['id'] ?? 0)) {
            return $this->notFound();
        }

        $workspacePermissions = $this->access->workspacePermissions($workspace);
        if (!(bool)($workspacePermissions['can_manage'] ?? false)) {
            return $this->accessDenied();
        }

        $this->repository->replaceNodeDirectPermissions(
            $this->intValue($workspace['id'] ?? 0),
            $this->intValue($node['id'] ?? 0),
            is_array($body['direct_permissions'] ?? null) ? $body['direct_permissions'] : [],
        );
        $this->access->clearRequestCache();
        $this->success(__('Izravna dopuštenja stranice su spremljena.'));

        return $this->responseFactory->redirect(
            $this->actionReturnPath($workspace, $body),
        );
    }

    /**
     * HR: Mijenja status otvorene jezične stranice nakon provjere nasljednih
     *     edit/manage prava i točnog broja aktualne Editor verzije.
     * EN: Changes the open page-locale status after checking inherited
     *     edit/manage rights and the exact current Editor version number.
     */
    public function transitionWorkflow(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        $node = $this->repository->findNodeById($this->intValue($body['node_id'] ?? 0));
        if (!is_array($workspace) || !is_array($node)) {
            return $this->notFound();
        }

        if (
            $this->intValue($node['workspace_id'] ?? 0)
            !== $this->intValue($workspace['id'] ?? 0)
            || $this->stringValue($node['node_type'] ?? '') !== 'document'
        ) {
            return $this->notFound();
        }

        $permissions = $this->access->nodePermissions($workspace, $node);
        if (
            !$permissions['can_edit']
            && !$permissions['can_publish']
            && !$permissions['can_manage']
        ) {
            return $this->accessDenied();
        }

        $language = strtolower($this->stringValue($body['language'] ?? 'hr'));
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) !== 1) {
            $language = 'hr';
        }

        $documentKey = $this->stringValue($node['document_key'] ?? '');
        $latestVersion = $this->editor->latestVersionNumber($documentKey, $language);
        $action = $this->stringValue($body['action'] ?? '');
        $workspacePath = $this->workspacePath($this->stringValue($workspace['slug'] ?? ''));
        $redirectPath = $this->nodePath(
            $this->stringValue($workspace['slug'] ?? ''),
            $this->stringValue($node['slug'] ?? ''),
        );
        $workflowBeforeTransition = $this->repository->nodeWorkflow(
            $this->intValue($node['id'] ?? 0),
            $language,
        );
        $pageWasPermanentlyDeleted = false;
        try {
            if ($action === 'discard') {
                if (!(bool)$permissions['can_edit']) {
                    return $this->accessDenied();
                }

                $workflowBeforeDiscard = $this->workflow->viewModel(
                    $this->intValue($node['id'] ?? 0),
                    $language,
                    $latestVersion,
                    (bool)$permissions['can_edit'],
                    (bool)$permissions['can_publish'],
                    (bool)$permissions['can_manage'],
                );
                $deleteNewPage = (bool)($workflowBeforeDiscard['is_new_unpublished_page'] ?? false)
                && $this->editor->isDocumentNeverPublished($documentKey);
                if ($deleteNewPage) {
                    if (!(bool)($permissions['can_delete'] ?? false)) {
                        return $this->accessDenied();
                    }

                    $redirectPath = $workspacePath;
                    $this->editor->deleteUnpublishedDocumentPermanently($documentKey);
                    $this->repository->deleteUnpublishedNodePermanently(
                        $this->intValue($workspace['id'] ?? 0),
                        $this->intValue($node['id'] ?? 0),
                        $this->currentUserId(),
                    );
                    $pageWasPermanentlyDeleted = true;
                    $this->success(__('Neobjavljena stranica i njezin nacrt su trajno obrisani.'));
                } else {
                    $this->editor->discardDraft($documentKey, $language);
                    $latestVersion = $this->editor->latestVersionNumber($documentKey, $language);
                    $this->workflow->discardDraft(
                        $this->intValue($node['id'] ?? 0),
                        $language,
                        $latestVersion,
                        $this->currentUserId(),
                    );
                }
            } else {
                if ($action === 'publish') {
                    if (!(bool)$permissions['can_publish']) {
                        return $this->accessDenied();
                    }

                    $this->editor->publishDraft($documentKey, $language, $latestVersion);
                }

                $this->workflow->transition(
                    $this->intValue($node['id'] ?? 0),
                    $language,
                    $action,
                    $latestVersion,
                    $this->currentUserId(),
                    (bool)$permissions['can_edit'],
                    (bool)$permissions['can_publish'],
                    (bool)$permissions['can_manage'],
                );

                if ($action === 'submit') {
                    $this->notifications->pageSubmitted(
                        $workspace,
                        $node,
                        $language,
                        $latestVersion,
                        $this->currentUserId(),
                    );
                } elseif ($action === 'publish') {
                    $this->notifications->pagePublished(
                        $workspace,
                        $node,
                        $language,
                        $latestVersion,
                        $this->intValue($workflowBeforeTransition['submitted_by_user_id'] ?? 0),
                        $this->currentUserId(),
                    );
                }
            }

            if (!$pageWasPermanentlyDeleted) {
                $workflowView = $this->workflow->viewModel(
                    $this->intValue($node['id'] ?? 0),
                    $language,
                    $latestVersion,
                    (bool)$permissions['can_edit'],
                    (bool)$permissions['can_publish'],
                    (bool)$permissions['can_manage'],
                );
                $this->success(
                    $this->stringValue(__('Status stranice je promijenjen: '))
                    . $this->stringValue($workflowView['label'] ?? ''),
                );
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('Workspace page workflow transition failed.', [
                'module' => 'workspace',
                'action' => 'workspace.workflow.' . ($action !== '' ? $action : 'unknown'),
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'node_id' => $this->intValue($node['id'] ?? 0),
                'language' => $language,
                'exception' => $throwable,
            ]);
            $this->failure($throwable->getMessage());
        }

        return $this->responseFactory->redirect(
            $redirectPath . '?lang=' . rawurlencode($language),
        );
    }

    /**
     * HR: Poslužuje mali CSS sloj za stablo i Workspace raspored.
     * EN: Serves the small CSS layer for the tree and Workspace layout.
     */
    public function styles(): ResponseInterface
    {
        return $this->responseFactory->file(
            $this->config->moduleRoot() . '/resources/assets/workspace.css',
            'text/css; charset=utf-8',
        );
    }

    /**
     * HR: Poslužuje JavaScript za organizator stabla, modalne obrasce čvorova
     * i prikaz samo onih polja koja pripadaju odabranoj vrsti čvora.
     *
     * EN: Serves JavaScript for the tree organizer, node dialogs, and showing
     * only the fields that belong to the selected node type.
     */
    public function scripts(): ResponseInterface
    {
        return $this->responseFactory->file(
            $this->config->moduleRoot() . '/resources/assets/workspace.js',
            'text/javascript; charset=utf-8',
        );
    }

    /**
     * HR: Renderira područje s lijevim stablom i odabranim HTML sadržajem.
     * EN: Renders a workspace with its left tree and selected HTML content.
     *
     * @param array<string, mixed> $workspace
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed>|null $node
     * @param array<int, array<string, mixed>> $workflows
     */
    private function renderWorkspace(
        ServerRequestInterface $request,
        array $workspace,
        array $tree,
        ?array $node,
        array $workflows,
    ): ResponseInterface {
        $workspacePermissions = $this->access->workspacePermissions($workspace);
        if (!$this->access->canAccessWorkspace($workspace)) {
            return $this->accessDenied();
        }

        $this->themes->activate($workspace);

        $language = $this->language($request);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $workspace = $this->repository->localizeWorkspace(
            $workspace,
            $language,
            $primaryLanguage,
        );
        if (is_array($node)) {
            $node = $this->repository->localizeNode($node, $language, $primaryLanguage);
        }

        $treeVisible = $this->config->treeVisibleForWorkspace($workspace);
        $contentLanguage = $language;
        $editorView = null;
        $workflowView = null;
        $nodePermissions = $workspacePermissions;
        if (is_array($node)) {
            $preloadedPermissions = $node['permissions'] ?? null;
            $nodePermissions = is_array($preloadedPermissions)
            ? $this->permissionArray($preloadedPermissions)
            : $this->access->nodePermissions($workspace, $node);
            if (!$nodePermissions['can_view']) {
                return $this->accessDenied();
            }

            $documentKey = $this->stringValue($node['document_key'] ?? '');
            if ($documentKey !== '') {
                $viewQuery = $request->getQueryParams();
                if (!array_key_exists('toc', $viewQuery)) {
                    $viewQuery['toc'] = $this->config->contentsVisibleForPage($workspace, $node)
                    ? 'on'
                    : 'off';
                }

                $editorView = $this->editor->documentView(
                    $documentKey,
                    $language,
                    $viewQuery,
                    (bool)($nodePermissions['can_edit'] ?? false),
                    (bool)($nodePermissions['can_edit'] ?? false)
                        || (bool)($nodePermissions['can_publish'] ?? false),
                );
                if (is_array($editorView)) {
                    /*
                     * HR: Editor može vratiti zadani jezik dokumenta kada prijevod
                     *     za jezik sučelja ne postoji. Workflow mora pratiti upravo
                     *     prikazanu inačicu, inače objavljena fallback stranica izgleda
                     *     kao novi neobjavljeni prijevod.
                     * EN: Editor may return the default document language when the UI
                     *     locale has no translation. Workflow must follow that displayed
                     *     variant, or a published fallback page looks like a new draft.
                     */
                    $contentLanguage = $this->stringValue(
                        $editorView['documentLanguage'] ?? $language,
                    );
                    if ($contentLanguage === '') {
                        $contentLanguage = $language;
                    }
                }

                $latestVersion = $this->editor->latestVersionNumber($documentKey, $contentLanguage);
                $workflowView = $this->workflow->viewModel(
                    $this->intValue($node['id'] ?? 0),
                    $contentLanguage,
                    $latestVersion,
                    (bool)($nodePermissions['can_edit'] ?? false),
                    (bool)($nodePermissions['can_publish'] ?? false),
                    (bool)($nodePermissions['can_manage'] ?? false),
                );
            }
        }

        $workflowTransitionPath = $this->pathFor(
            'workspace.workflow.transition',
            '/workspaces/workflow',
        );
        $followUi = $this->followUiData($request, $workspace, $node);
        if (is_array($editorView)) {
            $editorView['leadingActions'] = $this->documentLeadingActions(
                $workspace,
                $node,
                $nodePermissions,
                $workflowView,
                $workflowTransitionPath,
                $contentLanguage,
                (bool)($editorView['isDraftPreview'] ?? false),
                $treeVisible,
                (bool)($workspacePermissions['can_manage'] ?? false),
            );
            if (is_array($followUi)) {
                $editorView['leadingActions'][] = [
                    'type' => 'partial',
                    'label' => __('Prati promjene ovog sadržaja'),
                    'package' => 'aaieduhr/simbioza-module-user',
                    'partial' => 'simbioza-user/follow_button',
                    'data' => [...$followUi, 'compact' => '1'],
                ];
            }
        }

        /*
         * HR: Organizator dobiva sve aktivne čvorove samo kada ih korisnik sve
         *     vidi i smije uređivati. Djelomično stablo ne smije mijenjati
         *     globalni redoslijed jer bi skriveni čvorovi mogli biti izgubljeni.
         * EN: The organizer receives all active nodes only when the user can see
         *     and edit every one of them. A partial tree must not change the
         *     global order because hidden nodes could otherwise be displaced.
         */
        $workspaceId = $this->intValue($workspace['id'] ?? 0);
        $visibleNodes = $this->flattenTree($tree);
        $reviewQueue = [];
        $unpublishedPages = [];
        $canOrganizeTree = (bool)($workspacePermissions['can_manage'] ?? false);

        foreach ($visibleNodes as $candidate) {
            $candidateId = $this->intValue($candidate['id'] ?? 0);
            $candidatePermissions = $this->permissionArray(
                WorkspaceValue::stringKeyArray($candidate['permissions'] ?? null),
            );
            if (!$candidatePermissions['can_view']) {
                $canOrganizeTree = false;
                continue;
            }

            $canOrganizeTree = $canOrganizeTree && (bool)($candidatePermissions['can_edit'] ?? false);

            $candidateWorkflow = $workflows[$candidateId] ?? null;
            $candidateStatus = is_array($candidateWorkflow)
            ? $this->stringValue($candidateWorkflow['status'] ?? '')
            : '';
            $candidateCanWorkWithDraft = (bool)($candidatePermissions['can_edit'] ?? false)
            || (bool)($candidatePermissions['can_publish'] ?? false)
            || (bool)($candidatePermissions['can_manage'] ?? false);
            $candidateIsNewUnpublished = is_array($candidateWorkflow)
            && $this->stringValue($candidate['node_type'] ?? '') === 'document'
            && $this->intValue($candidateWorkflow['current_version_number'] ?? 0) > 0
            && $this->intValue($candidateWorkflow['published_version_number'] ?? 0) <= 0
            && $candidateStatus !== 'archived';
            if ($candidateCanWorkWithDraft && $candidateIsNewUnpublished) {
                $unpublishedPages[] = [
                    'title' => $this->stringValue($candidate['title'] ?? ''),
                    'href' => $this->nodePath(
                        $this->stringValue($workspace['slug'] ?? ''),
                        $this->stringValue($candidate['slug'] ?? ''),
                    ) . '?lang=' . rawurlencode($language),
                    'status' => $candidateStatus,
                    'statusLabel' => $candidateStatus === 'in_review'
                        ? __('Na pregledu')
                        : __('Nacrt'),
                    'updatedAt' => $this->stringValue($candidateWorkflow['updated_at'] ?? ''),
                ];
            }

            if (
                (bool)($candidatePermissions['can_publish'] ?? false)
                && is_array($candidateWorkflow)
                && $candidateStatus === 'in_review'
            ) {
                $reviewQueue[] = [
                    'title' => $this->stringValue($candidate['title'] ?? ''),
                    'href' => $this->nodePath(
                        $this->stringValue($workspace['slug'] ?? ''),
                        $this->stringValue($candidate['slug'] ?? ''),
                    ) . '?lang=' . rawurlencode($language),
                    'submittedAt' => $this->stringValue($candidateWorkflow['submitted_at'] ?? ''),
                ];
            }
        }

        $this->contentViewed($workspace, $node, $contentLanguage);

        $currentTitle = is_array($editorView)
        ? $this->stringValue($editorView['title'] ?? '')
        : (is_array($node) ? $this->stringValue($node['title'] ?? '') : '');
        $breadcrumbs = $this->breadcrumbs->build($workspace, $node, $tree, $language, $currentTitle);
        $backlinks = [];
        $includedIn = [];
        if (is_array($node)) {
            try {
                $backlinks = $this->backlinks->forTarget(
                    $this->intValue($node['id'] ?? 0),
                    $contentLanguage,
                );
                $includedIn = $this->backlinks->includedIn(
                    $this->stringValue($node['document_key'] ?? ''),
                    $contentLanguage,
                );
            } catch (Throwable $throwable) {
                // HR: Izvedeni indeks ne smije onemogućiti čitanje izvorne stranice.
                // EN: A derived index must never prevent reading the source page.
                $this->logger->error('Workspace backlinks could not be loaded.', [
                    'module' => 'workspace',
                    'workspace_id' => $workspaceId,
                    'page_id' => $this->intValue($node['id'] ?? 0),
                    'language' => $contentLanguage,
                    'exception' => $throwable,
                ]);
            }
        }

        $defaultParentId = is_array($node)
        && $this->stringValue($node['node_type'] ?? '') === 'document'
        && (bool)($nodePermissions['can_add'] ?? false)
        ? $this->intValue($node['id'] ?? 0)
        : 0;
        $activeNodeId = is_array($node) ? $this->intValue($node['id'] ?? 0) : 0;
        $activeTreePath = $this->treePathToNode($tree, $activeNodeId);
        $readableTree = $this->pruneReadableTree($tree, $activeTreePath, true);

        return $this->viewRenderer->render('workspace/show', [
            'title' => is_array($editorView)
                ? $this->stringValue($editorView['title'] ?? '')
                : $this->stringValue($workspace['name'] ?? ''),
            'themeTitleContext' => 'integrated',
            'workspace' => $workspace,
            'workspacePermissions' => $workspacePermissions,
            'tree' => $readableTree,
            'activeNode' => $node,
            'editorView' => $editorView,
            'workflow' => $workflowView,
            'breadcrumbs' => $breadcrumbs,
            'backlinks' => $backlinks,
            'includedIn' => $includedIn,
            'followUi' => $followUi,
            'workflowTransitionPath' => $workflowTransitionPath,
            'reviewQueue' => $reviewQueue,
            'unpublishedPages' => $unpublishedPages,
            'language' => $language,
            'activeLanguage' => $language,
            'primaryLanguage' => $primaryLanguage,
            'supportedLanguages' => $this->config->supportedLanguages(),
            'localeFlagPaths' => $this->localeFlagPaths($this->config->supportedLanguages()),
            'treeVisibleByDefault' => $treeVisible,
            'shortsPath' => $this->shortsPath(
                $this->stringValue($workspace['slug'] ?? ''),
                $language,
            ),
            'managePath' => $this->managePath($this->stringValue($workspace['slug'] ?? '')),
            'pageCreatePath' => $this->pathFor('workspace.page.create', '/workspaces/page/create'),
            'pageParentOptions' => $this->pageParentOptions($tree),
            'defaultPageParentId' => $defaultParentId,
            'canCreatePage' => (bool)($workspacePermissions['can_add'] ?? false)
                && $this->editor->isAvailable(),
            'canOrganizeTree' => $canOrganizeTree,
            'canOpenNodeDialog' => $canOrganizeTree
                || (is_array($node) && (bool)($workspacePermissions['can_manage'] ?? false)),
            'nodeSavePath' => $this->pathFor('workspace.node.save', '/workspaces/node/save'),
            'nodeDialogPath' => $this->pathFor(
                'workspace.node.dialog',
                '/workspaces/node/dialog',
            ),
            'treeOrderSavePath' => $this->pathFor(
                'workspace.tree.order.save',
                '/workspaces/tree/order',
            ),
            'treeOrganizerPath' => $this->pathFor(
                'workspace.tree.organizer',
                '/workspaces/tree/organizer',
            ),
            'treeBranchPath' => $this->pathFor(
                'workspace.tree.branch',
                '/workspaces/tree/branch',
            ),
            'fallbackLeadingActions' => $this->documentLeadingActions(
                $workspace,
                $node,
                $nodePermissions,
                $workflowView,
                $workflowTransitionPath,
                $contentLanguage,
                false,
                $treeVisible,
                (bool)($workspacePermissions['can_manage'] ?? false),
            ),
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
            'assetsJsPath' => $this->pathFor('workspace.assets.js', '/workspaces/assets.js'),
        ]);
    }

    /**
     * HR: Priprema opcionalni Simbioza gumb bez hard ovisnosti Workspace modula.
     * EN: Prepares the optional Simbioza button without a hard Workspace dependency.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $node
     * @return array<string,string>|null
     */
    private function followUiData(
        ServerRequestInterface $request,
        array $workspace,
        ?array $node,
    ): ?array {
        /*
         * HR: Javni prikaz ne smije koristiti strogi audit helper koji baca
         *     iznimku za gosta; gumb praćenja jednostavno pripada samo prijavljenima.
         * EN: Public rendering must not use the strict audit helper that throws
         *     for guests; the follow button simply belongs to authenticated users.
         */
        $user = $this->access->currentUser();
        $userId = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if (
            $userId <= 0
            || !$this->urlGenerator->namedRouteExists('simbioza-user.toggle')
            || !$this->urlGenerator->namedRouteExists('simbioza-user.status')
        ) {
            return null;
        }

        $isPage = is_array($node) && $this->intValue($node['id'] ?? 0) > 0;
        $returnUrl = $request->getUri()->getPath();
        if ($request->getUri()->getQuery() !== '') {
            $returnUrl .= '?' . $request->getUri()->getQuery();
        }

        return [
            'targetType' => $isPage ? 'page' : 'workspace',
            'targetId' => (string)($isPage
                ? $this->intValue($node['id'] ?? 0)
                : $this->intValue($workspace['id'] ?? 0)),
            'documentId' => $isPage ? $this->stringValue($node['document_key'] ?? '') : '',
            'label' => $isPage
                ? $this->stringValue($node['title'] ?? '')
                : $this->stringValue($workspace['name'] ?? ''),
            'togglePath' => $this->pathFor('simbioza-user.toggle', '/account/following/toggle'),
            'statusPath' => $this->pathFor('simbioza-user.status', '/account/following/status'),
            'returnUrl' => $returnUrl,
            'assetsCssPath' => $this->pathFor('simbioza-user.assets.css', '/simbioza-user/assets.css'),
        ];
    }

    /**
     * HR: Obavještava opcionalne module o uspješnom pregledu bez utjecaja na
     *     prikaz stranice ako neki izvedeni listener zakaže.
     * EN: Notifies optional modules about a successful view without affecting
     *     page rendering when a derived listener fails.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $node
     */
    private function contentViewed(array $workspace, ?array $node, string $language): void
    {
        try {
            $this->events->dispatch(new WorkspaceContentViewed(
                $this->intValue($workspace['id'] ?? 0),
                $this->stringValue($workspace['name'] ?? ''),
                is_array($node) ? $this->intValue($node['id'] ?? 0) ?: null : null,
                is_array($node) ? $this->stringValue($node['title'] ?? '') ?: null : null,
                $language,
            ));
        } catch (Throwable $throwable) {
            $this->logger->error('Workspace view listeners failed for workspace {workspace_id}.', [
                'module' => 'workspace',
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'node_id' => is_array($node) ? $this->intValue($node['id'] ?? 0) : null,
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Provjerava smije li se nova stranica dodati u korijen ili ispod
     * odabrane dokument-stranice. Linkovi namjerno ne mogu biti roditelji.
     *
     * EN: Checks whether a new page may be added at the root or below the
     * selected document page. Link items intentionally cannot be parents.
     *
     * @param array<string, mixed> $workspace
     */
    private function canCreatePageUnderParent(array $workspace, int $parentId): bool
    {
        if (!$this->editor->isAvailable() || !$this->canAddUnderParent($workspace, $parentId)) {
            return false;
        }

        if ($parentId <= 0) {
            return true;
        }

        $parent = $this->repository->findNodeById($parentId);

        return is_array($parent)
        && $this->intValue($parent['workspace_id'] ?? 0) === $this->intValue($workspace['id'] ?? 0)
        && $this->stringValue($parent['node_type'] ?? '') === 'document';
    }

    /**
     * HR: Provjerava ima li područje već aktivnu početnu dokument-stranicu.
     * EN: Checks whether the Workspace already has an active document homepage.
     *
     * @param array<string, mixed> $workspace
     */
    private function workspaceHasHomepage(array $workspace): bool
    {
        foreach ($this->repository->nodesForWorkspace($this->intValue($workspace['id'] ?? 0)) as $node) {
            if (
                $this->stringValue($node['node_type'] ?? '') === 'document'
                && (bool)($node['is_homepage'] ?? false)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * HR: Pretvara vidljivo stablo u ravan popis dopuštenih roditeljskih
     * stranica, uz uvlačenje naslova koje zadržava hijerarhiju u selectu.
     *
     * EN: Flattens the visible tree into allowed parent pages while indenting
     * labels so the select preserves the hierarchy.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array{id:int,label:string}>
     */
    private function pageParentOptions(array $tree, int $depth = 0): array
    {
        $options = [];
        foreach ($tree as $node) {
            $permissions = WorkspaceValue::stringKeyArray($node['permissions'] ?? null);
            if (
                $this->stringValue($node['node_type'] ?? '') === 'document'
                && (bool)($permissions['can_add'] ?? false)
            ) {
                $options[] = [
                    'id' => $this->intValue($node['id'] ?? 0),
                    'label' => str_repeat('— ', $depth)
                        . $this->stringValue($node['title'] ?? ''),
                ];
            }

            $options = [
                ...$options,
                ...$this->pageParentOptions(WorkspaceValue::rows($node['children'] ?? null), $depth + 1),
            ];
        }

        return $options;
    }

    /**
     * HR: Gradi URL-ove zastavica za zajednički višejezični editor metapodataka.
     * EN: Builds flag URLs for the shared multilingual metadata editor.
     *
     * @param list<string> $locales
     * @return array<string, string>
     */
    private function localeFlagPaths(array $locales): array
    {
        if (!$this->urlGenerator->namedRouteExists('menu.assets.flag')) {
            return [];
        }

        $paths = [];
        foreach ($locales as $locale) {
            $locale = strtolower(trim($locale));
            if ($locale === '') {
                continue;
            }

            $language = strtolower(strtok($locale, '-_') ?: $locale);
            $paths[$locale] = $this->urlGenerator->getPathFor(
                'menu.assets.flag',
                ['file' => $language . '.svg'],
            );
        }

        return $paths;
    }

    /**
     * HR: Pretvara vidljive ravne čvorove u redoslijed prikladan za vizualni
     *     organizator i svakom retku dodaje dubinu u stablu.
     * EN: Converts visible flat nodes into an order suitable for the visual
     *     organizer and adds tree depth to every row.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function orderNodesForManagement(array $nodes): array
    {
        $knownIds = [];
        foreach ($nodes as $node) {
            $knownIds[$this->intValue($node['id'] ?? 0)] = true;
        }

        $byParent = [];
        foreach ($nodes as $node) {
            $parentId = $this->intValue($node['parent_id'] ?? 0);
            if ($parentId > 0 && !isset($knownIds[$parentId])) {
                $parentId = 0;
            }

            $byParent[$parentId][] = $node;
        }

        $visited = [];
        $ordered = $this->appendManagementBranch($byParent, 0, 0, $visited);
        foreach ($nodes as $node) {
            $nodeId = $this->intValue($node['id'] ?? 0);
            if (isset($visited[$nodeId])) {
                continue;
            }

            $node['tree_depth'] = 0;
            $ordered[] = $node;
        }

        return $ordered;
    }

    /**
     * HR: Rekurzivno dodaje jednu podgranu organizatoru i štiti prikaz od
     *     eventualnog ciklusa u postojećim podacima.
     * EN: Recursively appends one branch to the organizer and protects the
     *     view from a possible cycle in existing data.
     *
     * @param array<int, list<array<string, mixed>>> $byParent
     * @param array<int, true> $visited
     * @return list<array<string, mixed>>
     */
    private function appendManagementBranch(
        array $byParent,
        int $parentId,
        int $depth,
        array &$visited,
    ): array {
        $ordered = [];
        foreach ($byParent[$parentId] ?? [] as $node) {
            $nodeId = $this->intValue($node['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            if (isset($visited[$nodeId])) {
                continue;
            }

            $visited[$nodeId] = true;
            $node['tree_depth'] = $depth;
            $ordered[] = $node;
            $ordered = [
                ...$ordered,
                ...$this->appendManagementBranch($byParent, $nodeId, $depth + 1, $visited),
            ];
        }

        return $ordered;
    }

    /**
     * HR: Provjerava add pravo područja ili odabranog roditeljskog čvora.
     * EN: Checks add permission on the workspace or selected parent node.
     *
     * @param array<string, mixed> $workspace
     */
    private function canAddUnderParent(array $workspace, int $parentId): bool
    {
        if ($parentId <= 0) {
            return $this->access->workspacePermissions($workspace)['can_add'];
        }

        $parent = $this->repository->findNodeById($parentId);

        return is_array($parent)
        && $this->intValue($parent['workspace_id'] ?? 0) === $this->intValue($workspace['id'] ?? 0)
        && $this->access->nodePermissions($workspace, $parent)['can_add'];
    }

    /**
     * HR: Odbija nepostojeću named rutu kada interni link nema sigurnu rezervnu putanju.
     * EN: Rejects a missing named route when an internal link has no safe fallback path.
     *
     * @param array<string, mixed> $input
     */
    private function assertInternalLinkCanResolve(array $input): void
    {
        if ($this->stringValue($input['node_type'] ?? '') !== 'internal_link') {
            return;
        }

        $routeName = $this->stringValue($input['route_name'] ?? '');
        $targetPath = $this->stringValue($input['target_url'] ?? '');
        if (
            $routeName !== ''
            && !$this->urlGenerator->namedRouteExists($routeName)
            && $targetPath === ''
        ) {
            throw new RuntimeException(__('Interna named ruta ne postoji i nema rezervnu putanju.'));
        }
    }

    /**
     * HR: Slijedi vanjski URL ili sigurnu internu named rutu.
     * EN: Follows an external URL or a safe internal named route.
     *
     * @param array<string, mixed> $node
     */
    private function redirectLinkNode(array $node): ResponseInterface
    {
        $routeName = $this->stringValue($node['route_name'] ?? '');
        if ($routeName !== '' && $this->urlGenerator->namedRouteExists($routeName)) {
            return $this->responseFactory->redirect($this->urlGenerator->getPathFor($routeName));
        }

        $target = $this->stringValue($node['target_url'] ?? '');
        if ($target === '') {
            return $this->notFound();
        }

        return $this->responseFactory->redirect(
            $this->stringValue($node['node_type'] ?? '') === 'internal_link'
            ? $this->internalTargetPath($target)
            : $target,
        );
    }

    /**
     * HR: Dodaje javne URL-ove područjima za popis.
     * EN: Adds public URLs to workspaces for the index.
     *
     * @param list<array<string, mixed>> $workspaces
     * @return list<array<string, mixed>>
     */
    private function decorateWorkspaces(array $workspaces): array
    {
        $workspaces = $this->presentations->many($workspaces);
        foreach ($workspaces as &$workspace) {
            $workspace['href'] = $this->workspacePath(
                $this->stringValue($workspace['slug'] ?? ''),
            );
        }

        return $workspaces;
    }

    /**
     * HR: Rekurzivno dodaje URL svakome vidljivom čvoru stabla.
     * EN: Recursively adds a URL to each visible tree node.
     *
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $workspace
     * @param array<int, array<string, mixed>> $workflows
     * @return list<array<string, mixed>>
     */
    private function decorateTree(
        array $tree,
        array $workspace,
        array $workflows = [],
        string $language = '',
    ): array {
        foreach ($tree as &$node) {
            $node = $this->repository->localizeNode(
                $node,
                $language,
                $this->config->siteDefaultLanguage(),
            );
            $type = $this->stringValue($node['node_type'] ?? 'document');
            $node['href'] = match ($type) {
                'document' => $this->nodePath(
                    $this->stringValue($workspace['slug'] ?? ''),
                    $this->stringValue($node['slug'] ?? ''),
                ),
                'separator' => '',
                default => $this->linkNodeHref($node),
            };
            $permissions = WorkspaceValue::stringKeyArray($node['permissions'] ?? null);
            $workflow = $workflows[$this->intValue($node['id'] ?? 0)] ?? null;
            if (
                $type === 'document'
                && is_array($workflow)
                && ((bool)($permissions['can_edit'] ?? false)
                    || (bool)($permissions['can_publish'] ?? false)
                    || (bool)($permissions['can_manage'] ?? false))
            ) {
                $status = $this->stringValue($workflow['status'] ?? 'draft');
                $isNewUnpublished = $this->intValue(
                    $workflow['current_version_number'] ?? 0,
                ) > 0 && $this->intValue(
                    $workflow['published_version_number'] ?? 0,
                ) <= 0;
                $statusLabel = match ($status) {
                    'in_review' => __('Na pregledu'),
                    'archived' => __('Arhivirano'),
                    'published' => '',
                    default => __('Nacrt'),
                };
                $node['workflow_status'] = $status;
                $node['workflow_label'] = $isNewUnpublished && $statusLabel !== ''
                ? __('Novo') . ' · ' . $statusLabel
                : $statusLabel;
                $node['is_new_unpublished'] = $isNewUnpublished;
            }

            $children = WorkspaceValue::rows($node['children'] ?? null);
            $node['children'] = $this->decorateTree(
                $children,
                $workspace,
                $workflows,
                $language,
            );
        }

        return $tree;
    }

    /**
     * HR: Pronalazi čvor po ID-u u već ACL-filtriranom stablu.
     * EN: Finds a node by ID in an already ACL-filtered tree.
     *
     * @param list<array<string, mixed>> $tree
     * @return array<string, mixed>|null
     */
    private function treeNodeById(array $tree, int $nodeId): ?array
    {
        if ($nodeId <= 0) {
            return null;
        }

        foreach ($tree as $node) {
            if ($this->intValue($node['id'] ?? 0) === $nodeId) {
                return $node;
            }

            $found = $this->treeNodeById(WorkspaceValue::rows($node['children'] ?? null), $nodeId);
            if (is_array($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * HR: Vraća ID-eve puta od korijena do odabranog čvora.
     * EN: Returns the IDs on the path from a root to the selected node.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<int>
     */
    private function treePathToNode(array $tree, int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }

        foreach ($tree as $node) {
            $candidateId = $this->intValue($node['id'] ?? 0);
            if ($candidateId === $nodeId) {
                return [$candidateId];
            }

            $childPath = $this->treePathToNode(
                WorkspaceValue::rows($node['children'] ?? null),
                $nodeId,
            );
            if ($childPath !== []) {
                return [$candidateId, ...$childPath];
            }
        }

        return [];
    }

    /**
     * HR: Zadržava korijenske stavke, prvu vidljivu razinu i cijeli aktivni
     *     put, a ostale potomke označava za naknadno učitavanje. Time mali
     *     početni HTML ostaje semantički jednak punom stablu.
     *
     * EN: Keeps root items, the first visible level, and the complete active
     *     path while marking other descendants for on-demand loading. This
     *     keeps the small initial HTML semantically equivalent to the full tree.
     *
     * @param list<array<string, mixed>> $tree
     * @param list<int> $activePath
     * @return list<array<string, mixed>>
     */
    private function pruneReadableTree(array $tree, array $activePath, bool $rootLevel): array
    {
        foreach ($tree as &$node) {
            $children = WorkspaceValue::rows($node['children'] ?? null);
            $nodeId = $this->intValue($node['id'] ?? 0);
            /*
             * HR: Ciljani repozitorijski prozor već zna ima li čvor još
             *     neučitanih potomaka. Ne smijemo tu oznaku svesti samo na
             *     trenutno prisutne retke jer bi udaljene grane izgubile gumb
             *     za naknadno otvaranje.
             * EN: The targeted repository window already knows whether a node
             *     has unloaded descendants. Do not reduce that marker to only
             *     the rows currently present, otherwise remote branches lose
             *     their on-demand expansion control.
             */
            $node['has_children'] = (bool)($node['has_children'] ?? ($children !== []));
            $loadChildren = $rootLevel || in_array($nodeId, $activePath, true);
            $node['children_loaded'] = !$node['has_children'] || $loadChildren;
            $node['children'] = $loadChildren
            ? $this->pruneReadableTree($children, $activePath, false)
            : [];
        }

        unset($node);

        return $tree;
    }

    /**
     * HR: Pretvara već ACL-filtrirano stablo u ravni popis bez ponovnog upita
     *     prema repozitoriju. Pregled stranice tako isti skup podataka koristi
     *     za red čekanja, nacrte i provjeru organizatora stabla.
     *
     * EN: Flattens an already ACL-filtered tree without querying the repository
     *     again. The page view can then reuse the same data set for the review
     *     queue, drafts, and the tree-organizer permission check.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private function flattenTree(array $tree): array
    {
        $nodes = [];
        foreach ($tree as $node) {
            $children = WorkspaceValue::rows($node['children'] ?? null);
            unset($node['children']);
            $nodes[] = $node;

            foreach ($this->flattenTree($children) as $child) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /**
     * HR: Rekurzivno prikuplja ID-eve vidljivog stabla za jedan grupni workflow upit.
     * EN: Recursively collects visible-tree IDs for one batched workflow query.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<int>
     */
    private function treeNodeIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $node) {
            $nodeId = $this->intValue($node['id'] ?? 0);
            if ($nodeId > 0) {
                $ids[] = $nodeId;
            }

            foreach ($this->treeNodeIds(WorkspaceValue::rows($node['children'] ?? null)) as $childId) {
                $ids[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * HR: Gradi diskretne akcije dokumenta za stablo, nacrt, pregled, objavu i
     *     ostale dopuštene workflow prijelaze. Akcije koje mijenjaju nacrt
     *     prikazuje samo na njegovu eksplicitnom pregledu ili novoj stranici
     *     koja još nema objavljenu verziju.
     * EN: Builds discreet document actions for the tree, draft, preview,
     *     publication, and other allowed workflow transitions. Draft-mutating
     *     actions are shown only on its explicit preview or on a new page that
     *     does not yet have a published version.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $node
     * @param array<string, bool> $permissions
     * @param array<string, mixed>|null $workflow
     * @return list<array<string, mixed>>
     */
    private function documentLeadingActions(
        array $workspace,
        ?array $node,
        array $permissions,
        ?array $workflow,
        string $transitionPath,
        string $language,
        bool $isDraftPreview,
        bool $treeVisible,
        bool $canManagePage,
    ): array {
        $actions = [[
            'type' => 'collapse',
            'label' => __('Stablo'),
            'target' => '#workspace-page-tree',
            'controls' => 'workspace-page-tree',
            'expanded' => $treeVisible,
            'icon' => 'tree',
        ]];
        if (is_array($node) && $canManagePage) {
            $actions[] = [
                'type' => 'modal',
                'label' => __('Upravljaj stranicom i dozvolama'),
                'target' => '#workspace-node-editor-modal',
                'url' => $this->pathFor('workspace.node.dialog', '/workspaces/node/dialog')
                    . '?' . http_build_query([
                        'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                        'node_id' => $this->intValue($node['id'] ?? 0),
                        'return_node_id' => $this->intValue($node['id'] ?? 0),
                    ]),
                'icon' => 'page-access',
                'style' => 'secondary',
            ];
        }

        if (!is_array($node) || !is_array($workflow)) {
            return $actions;
        }

        $hasDraft = (bool)($workflow['has_unpublished_changes'] ?? false);
        $isNewUnpublishedPage = (bool)($workflow['is_new_unpublished_page'] ?? false);
        $showDraftMutations = $isDraftPreview || $isNewUnpublishedPage;
        $documentKey = $this->stringValue($node['document_key'] ?? '');
        $deleteNewPage = $isNewUnpublishedPage
        && $this->editor->isDocumentNeverPublished($documentKey);
        $nodePath = $this->nodePath(
            $this->stringValue($workspace['slug'] ?? ''),
            $this->stringValue($node['slug'] ?? ''),
        );
        if ($hasDraft && (bool)($permissions['can_edit'] ?? false)) {
            $actions[] = [
                'type' => 'link',
                'label' => __('Uredi nacrt'),
                'href' => $this->editor->editorPath($documentKey, $language),
                'icon' => 'draft',
                'style' => 'warning',
            ];
        }

        if (
            $hasDraft
            && !$isDraftPreview
            && ((bool)($permissions['can_edit'] ?? false)
                || (bool)($permissions['can_publish'] ?? false))
        ) {
            $actions[] = [
                'type' => 'link',
                'label' => __('Pregledaj nacrt'),
                'href' => $nodePath . '?lang=' . rawurlencode($language) . '&draft=preview',
                'icon' => 'view',
                'style' => 'warning',
            ];
        }

        if (
            $hasDraft
            && $showDraftMutations
            && (bool)($permissions['can_edit'] ?? false)
            && (!$deleteNewPage || (bool)($permissions['can_delete'] ?? false))
        ) {
            $actions[] = $this->workflowFormAction(
                $workspace,
                $node,
                $transitionPath,
                $language,
                'discard',
                __('Odbaci nacrt'),
                'trash',
                'danger',
                $deleteNewPage
                    ? __('Odbaciti nacrt i trajno obrisati ovu neobjavljenu stranicu?')
                    : __('Odbaciti zajednički nacrt i vratiti zadnju objavljenu verziju?'),
            );
        }

        foreach (WorkspaceValue::rows($workflow['actions'] ?? null) as $workflowAction) {
            $name = $this->stringValue($workflowAction['action'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($hasDraft && !$showDraftMutations) {
                continue;
            }

            $actions[] = $this->workflowFormAction(
                $workspace,
                $node,
                $transitionPath,
                $language,
                $name,
                $this->stringValue($workflowAction['label'] ?? ''),
                $name,
                $this->stringValue($workflowAction['style'] ?? 'secondary'),
            );
        }

        return $actions;
    }

    /**
     * HR: Pretvara jedan workflow prijelaz u podatke sigurnog POST gumba.
     * EN: Converts one workflow transition into safe POST-button data.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function workflowFormAction(
        array $workspace,
        array $node,
        string $transitionPath,
        string $language,
        string $action,
        string $label,
        string $icon,
        string $style,
        string $confirm = '',
    ): array {
        return [
            'type' => 'form',
            'label' => $label,
            'path' => $transitionPath,
            'icon' => $icon,
            'style' => $style,
            'confirm' => $confirm,
            'fields' => [
                'workspace_id' => $this->intValue($workspace['id'] ?? 0),
                'node_id' => $this->intValue($node['id'] ?? 0),
                'language' => $language,
                'action' => $action,
            ],
        ];
    }

    /**
     * HR: Razrješava URL link čvora bez izvođenja redirecta.
     * EN: Resolves a link-node URL without issuing a redirect.
     *
     * @param array<string, mixed> $node
     */
    private function linkNodeHref(array $node): string
    {
        $routeName = $this->stringValue($node['route_name'] ?? '');
        if ($routeName !== '' && $this->urlGenerator->namedRouteExists($routeName)) {
            return $this->urlGenerator->getPathFor($routeName);
        }

        $target = $this->stringValue($node['target_url'] ?? '#');

        return $this->stringValue($node['node_type'] ?? '') === 'internal_link'
        ? $this->internalTargetPath($target)
        : $target;
    }

    /**
     * HR: Dodaje aplikacijski base path internoj apsolutnoj putanji samo kada
     * ga putanja već ne sadrži. Tako `/calendars` radi i pod `/example-app`.
     * EN: Adds the application base path to an internal absolute path only when
     * the path does not already contain it. This keeps `/calendars` working under `/example-app`.
     */
    private function internalTargetPath(string $target): string
    {
        $basePath = rtrim($this->urlGenerator->getBasePath(), '/');
        if (
            $basePath === ''
            || $target === $basePath
            || str_starts_with($target, $basePath . '/')
        ) {
            return $target;
        }

        return $basePath . $target;
    }

    /**
     * HR: Učitava područje iz ID-a ili sluga forme.
     * EN: Loads a workspace from form ID or slug.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function workspaceFromInput(array $input): ?array
    {
        $id = $this->intValue($input['workspace_id'] ?? $input['id'] ?? 0);
        if ($id > 0) {
            return $this->repository->findWorkspaceById($id);
        }

        $slug = $this->stringValue($input['workspace'] ?? $input['slug'] ?? '');

        return $slug !== '' ? $this->repository->findWorkspaceBySlug($slug) : null;
    }

    /**
     * HR: Gradi administratorsku putanju za postojeće ili novo područje.
     * EN: Builds the administration path for an existing or new workspace.
     */
    private function managePath(string $workspaceSlug): string
    {
        $path = $this->pathFor('workspace.manage', '/workspaces/manage');

        return $workspaceSlug !== '' ? $path . '?workspace=' . rawurlencode($workspaceSlug) : $path;
    }

    /**
     * HR: Odabire lokalizirani naziv razriješene teme uz hrvatski i engleski fallback.
     * EN: Selects the resolved theme label with Croatian and English fallbacks.
     *
     * @param array<string, mixed> $state
     */
    private function localizedThemeLabel(array $state): string
    {
        $theme = is_array($state['resolved_theme'] ?? null) ? $state['resolved_theme'] : [];
        $labels = is_array($theme['label'] ?? null) ? $theme['label'] : [];
        $locale = strtolower($this->translator->getLocale());
        $base = strtolower(strtok($locale, '-_') ?: $locale);

        return $this->stringValue(
            $labels[$locale] ?? $labels[$base] ?? $labels['hr'] ?? $labels['en'] ?? '',
        );
    }

    /**
     * HR: Gradi javnu putanju područja iz aktivne konfiguracije.
     * EN: Builds a public workspace path from active configuration.
     */
    private function workspacePath(string $workspaceSlug): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.show')) {
            return $this->urlGenerator->getPathFor('workspace.show', [
                'workspaceSlug' => $workspaceSlug,
            ]);
        }

        return rtrim($this->urlGenerator->getBasePath(), '/')
        . '/'
        . trim($this->config->rootPath(), '/')
        . '/'
        . rawurlencode($workspaceSlug);
    }

    /**
     * HR: Gradi javnu putanju stranice koju kontrolira područje.
     * EN: Builds a public page path controlled by its workspace.
     */
    private function nodePath(string $workspaceSlug, string $nodeSlug): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.node.show')) {
            return $this->urlGenerator->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        }

        return $this->workspacePath($workspaceSlug) . '/' . rawurlencode($nodeSlug);
    }

    /**
     * HR: Gradi poveznicu na ACL-filtrirane Sažetke područja.
     * EN: Builds the link to the Workspace's ACL-filtered Shorts page.
     */
    private function shortsPath(string $workspaceSlug, string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('workspace.shorts')) {
            return $this->urlGenerator->getPathFor(
                'workspace.shorts',
                ['workspaceSlug' => $workspaceSlug],
                ['lang' => $language],
            );
        }

        return $this->workspacePath($workspaceSlug)
        . '/shorts?lang='
        . rawurlencode($language);
    }

    /**
     * HR: Nakon modalne akcije sigurno vraća korisnika na područje ili aktivnu
     * dokument-stranicu. Klijent šalje samo ID, a URL se ponovno gradi iz
     * provjerenih podataka kako POST parametar ne bi postao otvoreni redirect.
     *
     * EN: Safely returns the user to the Workspace or active document page
     * after a modal action. The client sends only an ID and the URL is rebuilt
     * from verified data so a POST parameter cannot become an open redirect.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $input
     */
    private function actionReturnPath(array $workspace, array $input): string
    {
        $workspaceSlug = $this->stringValue($workspace['slug'] ?? '');
        if ($this->stringValue($input['return_context'] ?? '') !== 'workspace') {
            return $this->managePath($workspaceSlug);
        }

        $returnNodeId = $this->intValue($input['return_node_id'] ?? 0);
        if ($returnNodeId > 0) {
            $returnNode = $this->repository->findNodeById($returnNodeId);
            if (
                is_array($returnNode)
                && $this->intValue($returnNode['workspace_id'] ?? 0)
                    === $this->intValue($workspace['id'] ?? 0)
                && $this->stringValue($returnNode['node_type'] ?? '') === 'document'
                && $this->access->nodePermissions($workspace, $returnNode)['can_view']
            ) {
                return $this->nodePath(
                    $workspaceSlug,
                    $this->stringValue($returnNode['slug'] ?? ''),
                );
            }
        }

        return $this->workspacePath($workspaceSlug);
    }

    /**
     * HR: Vraća čitljiv 403 prikaz umjesto prazne ili tehničke poruke.
     * EN: Returns a readable 403 view instead of an empty or technical message.
     */
    private function accessDenied(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Nedozvoljen pristup'),
            'message' => __('Nemate potrebna prava za ovo područje ili stranicu.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 403);
    }

    /**
     * HR: Vraća čitljiv 404 prikaz.
     * EN: Returns a readable 404 view.
     */
    private function notFound(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Sadržaj nije pronađen'),
            'message' => __('Traženo područje ili stranica ne postoji.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 404);
    }

    /**
     * HR: Objašnjava administratoru da početna migracija nedostaje.
     * EN: Explains to an administrator that the initial migration is missing.
     */
    private function migrationMissing(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Područja još nisu instalirana'),
            'message' => __('Pokrenite početnu Workspace migraciju pa ponovno otvorite stranicu.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 503);
    }

    /**
     * HR: Čita odabrani jezik dokumenta, zatim aktivni jezik sučelja i na kraju zadani jezik sitea.
     * EN: Reads the selected document locale, then the active UI locale, and finally the site default locale.
     */
    private function language(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $language = strtolower($this->stringValue(
            $query['lang'] ?? $this->translator->getLocale(),
        ));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1
        ? $language
        : $this->config->siteDefaultLanguage();
    }

    /**
     * HR: Čita parsed body kao string-key polje.
     * EN: Reads the parsed body as a string-key array.
     *
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }

        $result = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * HR: Vraća ID prijavljenog korisnika potreban za audit.
     * EN: Returns the authenticated user ID required for auditing.
     */
    private function currentUserId(): int
    {
        $user = $this->access->currentUser();
        $id = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if ($id <= 0) {
            throw new RuntimeException(__('Za ovu radnju potrebna je prijava.'));
        }

        return $id;
    }

    /**
     * HR: Vraća named route ili stabilni fallback dok se kasne rute još registriraju.
     * EN: Returns a named route or stable fallback while late routes are still registering.
     */
    private function pathFor(string $routeName, string $fallback): string
    {
        return $this->urlGenerator->namedRouteExists($routeName)
        ? $this->urlGenerator->getPathFor($routeName)
        : $fallback;
    }

    /**
     * HR: Sprema uspješnu poruku u zajednički toast sustav.
     * EN: Stores a success message in the shared toast system.
     */
    private function success(string $message): void
    {
        $this->alertHandler->add(new Alert($message, AlertLevelEnum::Success));
    }

    /**
     * HR: Sprema poruku pogreške u zajednički toast sustav.
     * EN: Stores an error message in the shared toast system.
     */
    private function failure(string $message): void
    {
        $this->alertHandler->add(new Alert(
            $message !== '' ? $message : __('Radnju nije moguće dovršiti.'),
            AlertLevelEnum::Danger,
        ));
    }

    /**
     * HR: Normalizira skalarnu tekstualnu vrijednost.
     * EN: Normalizes a scalar text value.
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Čita cijeli broj iz zahtjeva.
     * EN: Reads an integer from request input.
     */
    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * HR: Normalizira unaprijed izračunata prava čvora na zatvoreni skup
     *     logičkih vrijednosti koji ostatak kontrolera sigurno koristi.
     * EN: Normalizes precomputed node permissions to the closed boolean set
     *     that the rest of the controller can safely consume.
     *
     * @param array<mixed> $permissions
     * @return array<string, bool>
     */
    private function permissionArray(array $permissions): array
    {
        return [
            'can_view' => (bool)($permissions['can_view'] ?? false),
            'can_add' => (bool)($permissions['can_add'] ?? false),
            'can_edit' => (bool)($permissions['can_edit'] ?? false),
            'can_publish' => (bool)($permissions['can_publish'] ?? false),
            'can_delete' => (bool)($permissions['can_delete'] ?? false),
            'can_manage' => (bool)($permissions['can_manage'] ?? false),
        ];
    }

    /**
     * HR: Vraća prazna prava za formu novog područja.
     * EN: Returns empty permissions for the new-workspace form.
     *
     * @return array<string, bool>
     */
    private function emptyPermissions(): array
    {
        return [
            'can_view' => false,
            'can_add' => false,
            'can_edit' => false,
            'can_publish' => false,
            'can_delete' => false,
            'can_manage' => false,
        ];
    }
}
