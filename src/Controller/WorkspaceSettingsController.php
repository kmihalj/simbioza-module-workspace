<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMaintenanceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceSettingsService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_string;
use function rawurlencode;

final readonly class WorkspaceSettingsController
{
    /**
     * HR: Prima servise za administratorske postavke, popise i zajedničke toast poruke.
     * EN: Receives services for administration settings, listings, and shared toast messages.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceSettingsService $settings,
        private WorkspaceMaintenanceService $maintenance,
        private WorkspacePresentationRegistry $presentations,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
        private SessionInterface $session,
    ) {
    }

    /**
     * HR: Prikazuje opće postavke putanje, vidljivosti, stabla i kreiranja područja.
     * EN: Displays general settings for routing, visibility, the tree, and workspace creation.
     */
    public function index(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        return $this->viewRenderer->render('settings/index', [
            'title' => __('Postavke područja'),
            'settings' => $this->settings->settingsForForm(),
            'savePath' => $this->pathFor('workspace.settings.save', '/settings/workspaces'),
            'settingsPath' => $this->pathFor('workspace.settings', '/settings/workspaces'),
            'homepagePath' => $this->pathFor(
                'workspace.settings.homepage',
                '/settings/workspaces/homepage',
            ),
            'allPath' => $this->pathFor('workspace.settings.all', '/settings/workspaces/all'),
            'deletedPath' => $this->pathFor('workspace.settings.deleted', '/settings/workspaces/deleted'),
            'maintenancePath' => $this->pathFor(
                'workspace.settings.maintenance',
                '/settings/workspaces/maintenance',
            ),
            'settingsMenuActiveSection' => 'workspace.settings',
            'subjectSearchPath' => $this->pathFor(
                'workspace.acl.subjects',
                '/workspaces/acl/subjects',
            ),
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
            'assetsJsPath' => $this->pathFor('workspace.assets.js', '/workspaces/assets.js'),
        ]);
    }

    /**
     * HR: Sprema opće postavke nakon provjere administratorskog statusa.
     * EN: Saves general settings after checking administrator status.
     */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $body = $request->getParsedBody();
        try {
            $this->settings->saveFromForm(WorkspaceValue::stringKeyArray($body));
            $this->alertHandler->add(new Alert(
                __('Postavke područja su spremljene.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert(
                $throwable->getMessage(),
                AlertLevelEnum::Danger,
            ));
        }

        return $this->responseFactory->redirect(
            $this->pathFor('workspace.settings', '/settings/workspaces'),
        );
    }

    /**
     * HR: Prikazuje sva aktivna područja administratoru.
     * EN: Displays all active workspaces to an administrator.
     */
    public function all(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        return $this->workspaceList(
            __('Sva područja'),
            $this->repository->tablesReady() ? $this->repository->allWorkspaces() : [],
            false,
        );
    }

    /**
     * HR: Prikazuje soft-obrisana područja i obrasce za vraćanje.
     * EN: Displays soft-deleted workspaces and restore forms.
     */
    public function deleted(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        return $this->workspaceList(
            __('Obrisana područja'),
            $this->repository->tablesReady() ? $this->repository->deletedWorkspaces() : [],
            true,
        );
    }

    /**
     * HR: Prikazuje zauzeće povijesti i obrisanih stavki te siguran obrazac održavanja.
     * EN: Displays history/deleted-item storage and the safe maintenance form.
     */
    public function maintenance(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $dashboard = $this->maintenance->dashboard();
        return $this->viewRenderer->render('settings/maintenance', [
            'title' => __('Održavanje'),
            'siteStatistics' => $dashboard['statistics']['site'] ?? [],
            'workspaces' => $this->presentations->many(WorkspaceValue::rows($dashboard['workspaces'] ?? null)),
            'deletedWorkspaces' => $this->repository->tablesReady()
                ? $this->presentations->many($this->repository->deletedWorkspaces())
                : [],
            'runPath' => $this->pathFor(
                'workspace.settings.maintenance.run',
                '/settings/workspaces/maintenance',
            ),
            'imageOptimizePath' => $this->pathFor(
                'workspace.settings.maintenance.images',
                '/settings/workspaces/maintenance/images',
            ),
            'imageOptimizeStatusPath' => $this->pathFor(
                'workspace.settings.maintenance.images.status',
                '/settings/workspaces/maintenance/images/status',
            ),
            'imageOptimizeStepPath' => $this->pathFor(
                'workspace.settings.maintenance.images.step',
                '/settings/workspaces/maintenance/images/step',
            ),
            'imageOptimization' => $this->maintenance->imageOptimizationStatus(),
            'purgePath' => $this->pathFor(
                'workspace.settings.purge',
                '/settings/workspaces/purge',
            ),
            'settingsPath' => $this->pathFor('workspace.settings', '/settings/workspaces'),
            'homepagePath' => $this->pathFor('workspace.settings.homepage', '/settings/workspaces/homepage'),
            'allPath' => $this->pathFor('workspace.settings.all', '/settings/workspaces/all'),
            'deletedPath' => $this->pathFor('workspace.settings.deleted', '/settings/workspaces/deleted'),
            'maintenancePath' => $this->pathFor(
                'workspace.settings.maintenance',
                '/settings/workspaces/maintenance',
            ),
            'settingsMenuActiveSection' => 'workspace.settings.maintenance',
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
        ]);
    }

    /**
     * HR: Izvršava izričito potvrđeno čišćenje i vraća mjerljiv rezultat administratoru.
     * EN: Runs explicitly confirmed cleanup and reports measurable results to the administrator.
     */
    public function runMaintenance(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        try {
            if (WorkspaceValue::string($body['confirm'] ?? '') !== '1') {
                throw new \RuntimeException(__('Prije čišćenja potvrdite da razumijete da je radnja nepovratna.'));
            }

            $result = $this->maintenance->clean(
                WorkspaceValue::string($body['scope'] ?? 'site'),
                WorkspaceValue::int($body['workspace_id'] ?? 0),
                WorkspaceValue::string($body['history_policy'] ?? 'none'),
                WorkspaceValue::int($body['history_value'] ?? 0),
                WorkspaceValue::int($body['deleted_days'] ?? 0),
                $this->currentUserId(),
            );
            $message = __('Održavanje je dovršeno.')
            . ' '
            . __('Uklonjene povijesne verzije:') . ' ' . WorkspaceValue::int($result['deleted_versions'] ?? 0)
            . '; '
            . __('trajno uklonjene stranice:') . ' ' . WorkspaceValue::int($result['purged_documents'] ?? 0)
            . '; '
            . __('trajno uklonjeni privitci:') . ' ' . WorkspaceValue::int($result['purged_assets'] ?? 0) . '.';
            if (WorkspaceValue::int($result['failed_files'] ?? 0) > 0) {
                $message .= ' ' . __('Neke datoteke nije bilo moguće ukloniti; provjerite prava datotečnog sustava.');
            }

            $this->alertHandler->add(new Alert($message, AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect(
            $this->pathFor('workspace.settings.maintenance', '/settings/workspaces/maintenance'),
        );
    }

    /**
     * HR: Izrađuje nedostajuće web-kopije slika i administratoru vraća mjerljiv rezultat.
     * EN: Creates missing image web copies and reports measurable results to the administrator.
     */
    public function optimizeImages(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        try {
            return $this->responseFactory->json([
                'ok' => true,
                'optimization' => $this->maintenance->startImageOptimization(),
                'csrf' => $this->csrfPayload(),
            ]);
        } catch (Throwable $throwable) {
            return $this->responseFactory->json([
                'ok' => false,
                'error' => $throwable->getMessage(),
                'csrf' => $this->csrfPayload(),
            ], 400);
        }
    }

    /** HR: Vraća aktualni napredak optimizacije. EN: Returns current optimization progress. */
    public function imageOptimizationStatus(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->responseFactory->json(['ok' => false, 'error' => __('Pristup nije dozvoljen.')], 403);
        }

        return $this->responseFactory->json([
            'ok' => true,
            'optimization' => $this->maintenance->imageOptimizationStatus(),
        ]);
    }

    /** HR: Obrađuje mali batch kao web worker. EN: Processes a small batch as a web worker. */
    public function stepImageOptimization(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->responseFactory->json([
                'ok' => false,
                'error' => __('Pristup nije dozvoljen.'),
                'csrf' => $this->csrfPayload(),
            ], 403);
        }

        try {
            return $this->responseFactory->json([
                'ok' => true,
                'optimization' => $this->maintenance->stepImageOptimization(3),
                'csrf' => $this->csrfPayload(),
            ]);
        } catch (Throwable $throwable) {
            return $this->responseFactory->json([
                'ok' => false,
                'error' => $throwable->getMessage(),
                'csrf' => $this->csrfPayload(),
            ], 400);
        }
    }

    /**
     * HR: Nakon dvostruke potvrde nepovratno uklanja soft-obrisano područje i sav njegov sadržaj.
     * EN: After double confirmation, irreversibly removes a soft-deleted Workspace and all its content.
     */
    public function permanentlyDelete(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        $returnTo = WorkspaceValue::string($body['return_to'] ?? 'deleted');
        $redirect = $returnTo === 'maintenance'
        ? $this->pathFor('workspace.settings.maintenance', '/settings/workspaces/maintenance')
        : $this->pathFor('workspace.settings.deleted', '/settings/workspaces/deleted');

        try {
            if (WorkspaceValue::string($body['confirm'] ?? '') !== '1') {
                throw new \RuntimeException(__('Potvrdite da razumijete da je trajno brisanje nepovratno.'));
            }

            $result = $this->maintenance->permanentlyDeleteWorkspace(
                WorkspaceValue::int($body['workspace_id'] ?? 0),
                WorkspaceValue::string($body['confirm_slug'] ?? ''),
                $this->currentUserId(),
            );
            $message = __('Područje i njegov sadržaj trajno su uklonjeni.')
            . ' '
            . __('Stranice:') . ' ' . WorkspaceValue::int($result['purged_nodes'] ?? 0)
            . '; '
            . __('dokumenti:') . ' ' . WorkspaceValue::int($result['purged_documents'] ?? 0)
            . '; '
            . __('privitci:') . ' ' . WorkspaceValue::int($result['purged_assets'] ?? 0) . '.';
            if (WorkspaceValue::int($result['failed_files'] ?? 0) > 0) {
                $message .= ' ' . __('Neke datoteke nije bilo moguće ukloniti; provjerite prava datotečnog sustava.');
            }

            $this->alertHandler->add(new Alert($message, AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect($redirect);
    }

    /**
     * HR: Renderira zajednički administratorski popis aktivnih ili obrisanih područja.
     * EN: Renders the shared administration list for active or deleted workspaces.
     *
     * @param list<array<string, mixed>> $workspaces
     */
    private function workspaceList(string $title, array $workspaces, bool $deleted): ResponseInterface
    {
        $workspaces = $this->presentations->many($workspaces);
        foreach ($workspaces as &$workspace) {
            $slug = is_string($workspace['slug'] ?? null) ? $workspace['slug'] : '';
            $workspace['manage_path'] = $this->pathFor('workspace.manage', '/workspaces/manage')
            . '?workspace='
            . rawurlencode($slug);
            $workspace['export_path'] = $this->pathFor('workspace.export', '/workspaces/export')
            . '?workspace='
            . rawurlencode($slug);
            $workspace['public_path'] = $this->urlGenerator->namedRouteExists('workspace.show')
            ? $this->urlGenerator->getPathFor('workspace.show', ['workspaceSlug' => $slug])
            : rtrim($this->urlGenerator->getBasePath(), '/')
            . '/'
            . $this->config->rootPath()
            . '/'
            . rawurlencode($slug);
        }

        return $this->viewRenderer->render('settings/workspaces', [
            'title' => $title,
            'workspaces' => $workspaces,
            'deleted' => $deleted,
            'restorePath' => $this->pathFor('workspace.restore', '/workspaces/restore'),
            'purgePath' => $this->pathFor('workspace.settings.purge', '/settings/workspaces/purge'),
            'settingsPath' => $this->pathFor('workspace.settings', '/settings/workspaces'),
            'homepagePath' => $this->pathFor(
                'workspace.settings.homepage',
                '/settings/workspaces/homepage',
            ),
            'allPath' => $this->pathFor('workspace.settings.all', '/settings/workspaces/all'),
            'deletedPath' => $this->pathFor('workspace.settings.deleted', '/settings/workspaces/deleted'),
            'maintenancePath' => $this->pathFor(
                'workspace.settings.maintenance',
                '/settings/workspaces/maintenance',
            ),
            'newPath' => $this->pathFor('workspace.manage', '/workspaces/manage'),
            'settingsMenuActiveSection' => $deleted
            ? 'workspace.settings.deleted'
            : 'workspace.settings.all',
            'tablesReady' => $this->repository->tablesReady(),
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
        ]);
    }

    /** HR: Vraća ID prijavljenog administratora za audit. EN: Returns the signed-in administrator ID for audit. */
    private function currentUserId(): int
    {
        $user = $this->access->currentUser();
        $userId = is_array($user) ? WorkspaceValue::int($user['id'] ?? 0) : 0;
        if ($userId <= 0) {
            throw new \RuntimeException(__('Za ovu radnju potrebna je prijava.'));
        }

        return $userId;
    }

    /**
     * HR: Vraća čitljiv 403 prikaz za korisnika bez administratorskih prava.
     * EN: Returns a readable 403 view for a user without administrator rights.
     */
    private function accessDenied(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Nedozvoljen pristup'),
            'message' => __('Samo administrator može mijenjati postavke područja.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 403);
    }

    /**
     * HR: Vraća svježi CSRF token nakon AJAX POST-a. EN: Returns a fresh CSRF token after an AJAX POST.
     * @return array{name: string, token: string}
     */
    private function csrfPayload(): array
    {
        return [
            'name' => $this->session->getCsrfTokenName(),
            'token' => $this->session->getOrGenerateCsrfToken(),
        ];
    }

    /**
     * HR: Generira named rutu ili stabilni fallback za samostalni rad modula.
     * EN: Generates a named route or stable fallback for standalone module operation.
     */
    private function pathFor(string $routeName, string $fallback): string
    {
        return $this->urlGenerator->namedRouteExists($routeName)
        ? $this->urlGenerator->getPathFor($routeName)
        : $fallback;
    }
}
