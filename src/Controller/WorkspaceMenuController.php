<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * HR: Omogućuje upravitelju područja uređivanje samo posebnih menija tog područja.
 * EN: Allows a Workspace manager to edit only that Workspace's special menus.
 */
final readonly class WorkspaceMenuController
{
    /**
     * HR: Prima ACL, opcionalni Menu most i renderere potrebne za izolirani editor područja.
     * EN: Receives ACL, the optional Menu bridge, and renderers required for the isolated Workspace editor.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceMenuService $menus,
        private WorkspaceThemeService $themes,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
    ) {
    }

    /**
     * HR: Prikazuje dva odvojena editora kada korisnik ima `manage` pravo za područje.
     * EN: Displays two separated editors when the user has the Workspace `manage` permission.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromInput(WorkspaceValue::stringKeyArray($request->getQueryParams()));
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->responseFactory->text(__('Nedozvoljen pristup'), 403);
        }

        if (!$this->menus->isAvailable()) {
            return $this->responseFactory->text(__('Menu modul nije instaliran ili uključen.'), 404);
        }

        if ($this->themes->isAvailable()) {
            $this->themes->activate($workspace);
        }

        $savePath = $this->urlGenerator->getPathFor('workspace.menu.save');
        try {
            $topEditor = $this->menus->renderEditor($workspace, 'contexts_top', $savePath);
            $leftEditor = $this->menus->renderEditor($workspace, 'contexts_left', $savePath);
        } catch (Throwable $throwable) {
            return $this->responseFactory->text(__($throwable->getMessage()), 500);
        }

        $slug = WorkspaceValue::string($workspace['slug'] ?? '');

        return $this->viewRenderer->render('workspace/menu', [
            'title' => __('Posebni meniji područja'),
            'workspace' => $workspace,
            'topEditor' => $topEditor,
            'leftEditor' => $leftEditor,
            'managePath' => $this->urlGenerator->getPathFor('workspace.manage', [], ['workspace' => $slug]),
            'workspacePath' => $this->urlGenerator->getPathFor('workspace.show', ['workspaceSlug' => $slug]),
        ]);
    }

    /**
     * HR: Sprema samo poslani gornji ili lijevi meni nakon ponovne server-side ACL provjere.
     * EN: Saves only the submitted top or left menu after repeating the server-side ACL check.
     */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->responseFactory->text(__('Nedozvoljen pristup'), 403);
        }

        if (!$this->menus->isAvailable()) {
            return $this->responseFactory->text(__('Menu modul nije instaliran ili uključen.'), 404);
        }

        try {
            $this->menus->save(
                $workspace,
                WorkspaceValue::string($body['section'] ?? ''),
                WorkspaceValue::stringKeyArray($body['context'] ?? null),
            );
            $this->alertHandler->add(new Alert(__('Posebni meni područja je spremljen.'), AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert(__($throwable->getMessage()), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect($this->editorPath($workspace));
    }

    /**
     * HR: Provjerava efektivnu `manage` ovlast područja.
     * EN: Checks the effective Workspace `manage` permission.
     *
     * @param array<string, mixed> $workspace
     */
    private function canManage(array $workspace): bool
    {
        return $this->access->workspacePermissions($workspace)['can_manage'];
    }

    /**
     * HR: Razrješava područje isključivo po sigurnom ID-u ili slugu.
     * EN: Resolves a Workspace only by a safe ID or slug.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function workspaceFromInput(array $input): ?array
    {
        $id = WorkspaceValue::int($input['workspace_id'] ?? $input['id'] ?? 0);
        if ($id > 0) {
            $workspace = $this->repository->findWorkspaceById($id);
            return is_array($workspace) ? WorkspaceValue::stringKeyArray($workspace) : null;
        }

        $slug = WorkspaceValue::string($input['workspace'] ?? $input['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        $workspace = $this->repository->findWorkspaceBySlug($slug);
        return is_array($workspace) ? WorkspaceValue::stringKeyArray($workspace) : null;
    }

    /**
     * HR: Gradi povratnu putanju odvojenog editor-a područja.
     * EN: Builds the separated Workspace-editor return path.
     *
     * @param array<string, mixed> $workspace
     */
    private function editorPath(array $workspace): string
    {
        return $this->urlGenerator->getPathFor('workspace.menu', [], [
            'workspace' => WorkspaceValue::string($workspace['slug'] ?? ''),
        ]);
    }
}
