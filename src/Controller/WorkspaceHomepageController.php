<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

use function is_array;
use function is_numeric;

/**
 * HR: Poslužuje administratorsku i osobnu postavku naslovnice aplikacije.
 * EN: Serves administrator and personal application-homepage settings.
 */
final readonly class WorkspaceHomepageController
{
    /**
     * HR: Prima Workspace ovlasti, poslovni servis i zajedničke HTTP/UI servise.
     * EN: Receives Workspace authorization, business logic, and shared HTTP/UI services.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceAccessService $access,
        private WorkspaceHomepageService $homepages,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
    ) {
    }

    /**
     * HR: Prikazuje zasebnu stavku naslovnice u grupi Postavke područja.
     * EN: Displays the dedicated homepage item in the Workspace settings group.
     */
    public function settings(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        $form = $this->homepages->settingsForForm();

        return $this->viewRenderer->render('settings/homepage', [
            'title' => __('Naslovnica aplikacije'),
            'tablesReady' => $this->homepages->tablesReady(),
            'viewOptionsReady' => $form['view_options_ready'],
            'settings' => $form['settings'],
            'publicOptionGroups' => $form['public_option_groups'],
            'authenticatedOptionGroups' => $form['authenticated_option_groups'],
            'savePath' => $this->pathFor(
                'workspace.settings.homepage.save',
                '/settings/workspaces/homepage',
            ),
            'settingsPath' => $this->pathFor('workspace.settings', '/settings/workspaces'),
            'homepagePath' => $this->pathFor(
                'workspace.settings.homepage',
                '/settings/workspaces/homepage',
            ),
            'allPath' => $this->pathFor('workspace.settings.all', '/settings/workspaces/all'),
            'deletedPath' => $this->pathFor(
                'workspace.settings.deleted',
                '/settings/workspaces/deleted',
            ),
            'maintenancePath' => $this->pathFor(
                'workspace.settings.maintenance',
                '/settings/workspaces/maintenance',
            ),
            'settingsMenuActiveSection' => 'workspace.settings.homepage',
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
            'assetsJsPath' => $this->pathFor('workspace.assets.js', '/workspaces/assets.js'),
        ], true, $this->homepages->tablesReady() ? 200 : 503);
    }

    /**
     * HR: Sprema javnu, prijavljenu i korisničku politiku nakon admin provjere.
     * EN: Saves public, authenticated, and user policy after an administrator check.
     */
    public function saveSettings(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->accessDenied();
        }

        try {
            $this->homepages->saveSettings(
                WorkspaceValue::stringKeyArray($request->getParsedBody()),
                $this->currentUserId(),
            );
            $this->alertHandler->add(new Alert(
                __('Postavke naslovnice su spremljene.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect(
            $this->pathFor('workspace.settings.homepage', '/settings/workspaces/homepage'),
        );
    }

    /**
     * HR: Sprema osobni odabir i vraća korisnika na postojeći Auth profil.
     * EN: Saves the personal selection and returns the user to the existing Auth profile.
     */
    public function savePreference(ServerRequestInterface $request): ResponseInterface
    {
        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        try {
            $this->homepages->saveUserSelection(
                $this->currentUserId(),
                $body,
            );
            $this->alertHandler->add(new Alert(
                __('Osobna naslovnica je spremljena.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect(
            $this->pathFor('auth.account.profile', '/auth/account/profile'),
        );
    }

    /**
     * HR: Vraća ID prijavljenog korisnika ili zaustavlja neispravan session.
     * EN: Returns the authenticated user ID or stops an invalid session.
     */
    private function currentUserId(): int
    {
        $user = $this->access->currentUser();
        $userId = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if ($userId <= 0) {
            throw new RuntimeException(__('Za ovu radnju potrebna je prijava.'));
        }

        return $userId;
    }

    /**
     * HR: Vraća administratorski 403 bez izlaganja postavki.
     * EN: Returns an administrator-only 403 without exposing settings.
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
     * HR: Generira named rutu ili stabilni fallback.
     * EN: Generates a named route or stable fallback.
     */
    private function pathFor(string $routeName, string $fallback): string
    {
        return $this->urlGenerator->namedRouteExists($routeName)
        ? $this->urlGenerator->getPathFor($routeName)
        : $fallback;
    }
}
