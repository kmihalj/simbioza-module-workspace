<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeArchiveService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeAssetLibrary;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Upravlja odabirom, privatnim uređivanjem i prijenosom teme jednog područja.
 * EN: Manages selection, private editing, and transfer of one workspace theme.
 */
final readonly class WorkspaceThemeController
{
    /**
     * HR: Prima HTTP, ACL, theme i arhivske servise potrebne za sigurno upravljanje temom područja.
     * EN: Receives HTTP, ACL, theme, and archive services required for safe Workspace theme management.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceThemeService $themes,
        private WorkspaceThemeArchiveService $archives,
        private WorkspaceThemeAssetLibrary $assets,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
    ) {
    }

    /**
     * HR: Prikazuje zajednički Theme editor samo upravitelju područja.
     * EN: Displays the shared Theme editor only to a workspace manager.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromRequest($request);
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->responseFactory->text(__('Nedozvoljen pristup'), 403);
        }

        if (!$this->themes->isAvailable()) {
            return $this->responseFactory->text(__('Theme modul nije instaliran ili uključen.'), 404);
        }

        $this->themes->activate($workspace);
        $query = WorkspaceValue::stringKeyArray($request->getQueryParams());

        return $this->themes->renderEditor($this->themes->editorData(
            $workspace,
            $this->access->isAdministrator(),
            $this->section($query['open_section'] ?? ''),
        ));
    }

    /**
     * HR: Sprema samo temu područja; ni jedna akcija ne zapisuje sistemski theme JSON.
     * EN: Saves only the workspace theme; no action writes to system theme JSON.
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

        if (!$this->themes->isAvailable()) {
            return $this->responseFactory->text(__('Theme modul nije instaliran ili uključen.'), 404);
        }

        $action = WorkspaceValue::string($body['action'] ?? '');
        $section = $this->section($body['open_section'] ?? '');
        $actorUserId = $this->actorUserId();
        try {
            if ($action === 'save_settings') {
                $this->themes->saveSelection(
                    $workspace,
                    WorkspaceValue::string($body['active_theme'] ?? '__default__'),
                    WorkspaceValue::string($body['mode_policy'] ?? 'auto'),
                    $actorUserId,
                );
            } elseif ($action === 'save_theme') {
                $theme = WorkspaceValue::stringKeyArray($body['theme'] ?? null);
                $theme['components'] = WorkspaceValue::stringKeyArray($body['components'] ?? null);
                $this->themes->saveTheme(
                    $workspace,
                    $theme,
                    WorkspaceValue::string($body['mode_policy'] ?? $this->currentMode($workspace)),
                    $actorUserId,
                );
            } elseif ($action === 'upload_theme_asset') {
                $file = $request->getUploadedFiles()['theme_asset'] ?? null;
                if (!($file instanceof UploadedFileInterface)) {
                    throw new RuntimeException(__('Odaberite datoteku teme.'));
                }

                $this->themes->uploadAsset(
                    $workspace,
                    $file,
                    WorkspaceValue::string($body['theme_asset_role'] ?? 'other'),
                    $actorUserId,
                );
            } elseif ($action === 'delete_theme_asset') {
                $this->themes->deleteAsset(
                    $workspace,
                    WorkspaceValue::string($body['theme_asset_file'] ?? ''),
                    $actorUserId,
                );
            } elseif ($action === 'import_theme') {
                $file = $request->getUploadedFiles()['complete_theme'] ?? null;
                if (!($file instanceof UploadedFileInterface)) {
                    throw new RuntimeException(__('Odaberite ZIP paket teme.'));
                }

                $this->archives->import($workspace, $file, $this->currentMode($workspace), $actorUserId);
            } else {
                throw new RuntimeException(__('Nepoznata akcija teme područja.'));
            }

            $this->alertHandler->add(new Alert(__('Tema područja je spremljena.'), AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert(__($throwable->getMessage()), AlertLevelEnum::Danger));
        }

        return $this->responseFactory->redirect($this->editorPath($workspace, $section));
    }

    /**
     * HR: Samo administrator izvozi privatnu temu područja kao kompatibilni ZIP paket.
     * EN: Only an administrator exports a private workspace theme as a compatible ZIP package.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromRequest($request);
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->access->isAdministrator()) {
            return $this->responseFactory->text(__('Nedozvoljen pristup'), 403);
        }

        try {
            $content = $this->archives->export($workspace);
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert(__($throwable->getMessage()), AlertLevelEnum::Danger));
            return $this->responseFactory->redirect($this->editorPath($workspace));
        }

        $slug = WorkspaceValue::string($workspace['slug'] ?? 'workspace');
        return $this->responseFactory->createResponse(200)
            ->withBody($this->responseFactory->streamFactory()->createStream($content))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string)strlen($content))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', 'attachment; filename="workspace-theme-' . $slug . '.zip"');
    }

    /**
     * HR: Poslužuje privatni asset samo korisniku koji smije vidjeti pripadajuće područje.
     * EN: Serves a private asset only to a user allowed to view its workspace.
     */
    public function asset(string $workspace, string $file): ResponseInterface
    {
        $workspaceRow = $this->repository->findWorkspaceBySlug($workspace);
        if (!is_array($workspaceRow) || !$this->access->workspacePermissions($workspaceRow)['can_view']) {
            return $this->responseFactory->text(__('Not Found'), 404);
        }

        $path = $this->assets->assetPath(WorkspaceValue::int($workspaceRow['id'] ?? 0), $file);
        if (!is_string($path)) {
            return $this->responseFactory->text(__('Not Found'), 404);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $this->responseFactory->file(
            $path,
            is_string($mime) && str_starts_with($mime, 'image/') ? $mime : 'application/octet-stream',
            null,
            200,
            ['Cache-Control' => 'private, max-age=31536000, immutable'],
        );
    }

    /**
     * HR: Provjerava ima li trenutačni korisnik pravo upravljanja zadanim područjem.
     * EN: Checks whether the current user may manage the supplied Workspace.
     *
     * @param array<string, mixed> $workspace
     */
    private function canManage(array $workspace): bool
    {
        return $this->access->workspacePermissions($workspace)['can_manage'];
    }

    /**
     * HR: Razrješava područje iz sigurnih parametara trenutačnog HTTP zahtjeva.
     * EN: Resolves a Workspace from the current HTTP request's sanitized parameters.
     *
     * @return array<string, mixed>|null
     */
    private function workspaceFromRequest(ServerRequestInterface $request): ?array
    {
        return $this->workspaceFromInput(WorkspaceValue::stringKeyArray($request->getQueryParams()));
    }

    /**
     * HR: Razrješava područje po ID-u ili slugu bez prihvaćanja proizvoljnih vrijednosti.
     * EN: Resolves a Workspace by ID or slug without accepting arbitrary values.
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
     * HR: Vraća valjanu politiku svijetlog, tamnog ili automatskog prikaza teme područja.
     * EN: Returns a valid light, dark, or automatic mode policy for the Workspace theme.
     *
     * @param array<string, mixed> $workspace
     */
    private function currentMode(array $workspace): string
    {
        $state = $this->themes->state($workspace);
        $mode = WorkspaceValue::string($state['mode_policy'] ?? 'auto');
        return in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';
    }

    /**
     * HR: Gradi povratnu putanju uređivača i po potrebi čuva otvorenu sekciju forme.
     * EN: Builds the editor return path and optionally preserves the open form section.
     *
     * @param array<string, mixed> $workspace
     */
    private function editorPath(array $workspace, string $section = ''): string
    {
        $query = ['workspace' => WorkspaceValue::string($workspace['slug'] ?? '')];
        if ($section !== '') {
            $query['open_section'] = $section;
        }

        return $this->urlGenerator->getPathFor('workspace.theme', [], $query);
    }

    /**
     * HR: Prihvaća samo kratki sigurni identifikator sekcije Theme editora.
     * EN: Accepts only a short safe Theme editor section identifier.
     */
    private function section(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        return preg_match('/^[a-z0-9_-]{1,64}$/', $value) === 1 ? $value : '';
    }

    /**
     * HR: Vraća ID prijavljenog izvršitelja ili nulu kada korisnik nije dostupan.
     * EN: Returns the signed-in actor ID or zero when no user is available.
     */
    private function actorUserId(): int
    {
        $user = $this->access->currentUser();
        return is_array($user) ? WorkspaceValue::int($user['id'] ?? 0) : 0;
    }
}
