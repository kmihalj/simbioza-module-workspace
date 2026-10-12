<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupConfig;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupJobRepository;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupJobRunner;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupUploadService;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * EN: Connects generic Backup orchestration to one Workspace's ACL rules
 *     without coupling the Backup module back to the Workspace domain model.
 *
 * HR: Povezuje generički Backup s ACL pravilima jednog područja bez obrnutog
 *     povezivanja Backup modula na Workspace poslovni model.
 */
final readonly class WorkspaceBackupController
{
    /**
     * EN: Receives only public Backup services and Workspace ACL/repository services.
     *
     * HR: Prima samo javne Backup servise i Workspace ACL/repository servise.
     */
    public function __construct(
        private ResponseFactory $responses,
        private WorkspaceModuleViewRenderer $views,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private BackupJobRepository $jobs,
        private BackupJobRunner $runner,
        private BackupUploadService $uploads,
        private BackupManager $manager,
        private BackupConfig $backupConfig,
        private UrlGenerator $urls,
        private SessionInterface $session,
    ) {
    }

    /**
     * EN: Shows secure export/import only to a manager of the selected Workspace.
     *
     * HR: Prikazuje sigurni izvoz/import samo upravitelju odabranog područja.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromInput(WorkspaceValue::stringKeyArray($request->getQueryParams()));
        if (!is_array($workspace)) {
            return $this->responses->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->denied();
        }

        $slug = WorkspaceValue::string($workspace['slug'] ?? '');

        return $this->views->render('workspace/backup', [
            'title' => __('Backup područja'),
            'workspace' => $workspace,
            'isAdministrator' => $this->access->isAdministrator(),
            'managePath' => $this->urls->getPathFor('workspace.manage', [], ['workspace' => $slug]),
            'createPath' => $this->urls->getPathFor('workspace.backup.create'),
            'uploadStartPath' => $this->urls->getPathFor('workspace.backup.upload.start'),
            'uploadChunkPath' => $this->urls->getPathFor('workspace.backup.upload.chunk'),
            'uploadFinishPath' => $this->urls->getPathFor('workspace.backup.upload.finish'),
            'preflightPath' => $this->urls->getPathFor('workspace.backup.preflight'),
            'restorePath' => $this->urls->getPathFor('workspace.backup.restore'),
            'csrfPath' => $this->urls->getPathFor('workspace.backup.csrf', [], ['workspace' => $slug]),
            'csrfName' => $this->session->getCsrfTokenName(),
            'csrfToken' => $this->session->getOrGenerateCsrfToken(),
            'chunkSize' => $this->backupConfig->chunkSize(),
            'maxArchiveSize' => $this->backupConfig->maxArchiveSize(),
        ]);
    }

    /**
     * EN: Returns the current CSRF token only to a manager of the requested Workspace.
     *
     * HR: Vraća aktualni CSRF token samo upravitelju zadanog područja.
     */
    public function csrf(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = $this->workspaceFromInput(WorkspaceValue::stringKeyArray($request->getQueryParams()));
        if (!is_array($workspace) || !$this->canManage($workspace)) {
            return $this->deniedJson();
        }

        return $this->responses->json([
            'csrf_token' => $this->session->getOrGenerateCsrfToken(),
        ]);
    }

    /**
     * EN: Creates an encrypted portable archive from Workspace-scope providers only.
     *
     * HR: Izrađuje šifrirani prenosivi arhiv samo od Workspace-scope providera.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace) || !$this->canManage($workspace)) {
            return $this->denied();
        }

        try {
            $slug = WorkspaceValue::string($workspace['slug'] ?? '');
            $job = $this->jobs->create(
                'export',
                new BackupScope(BackupScope::WORKSPACE, $slug),
                [],
                ['site_base_url' => $this->requestSiteUrl($request)],
                $this->actorUserId(),
            );
            $path = $this->runner->runExport(
                $job,
                'workspace-' . $slug,
                $this->passphrase($body),
            );

            return $this->responses->file($path, 'application/zip', basename($path));
        } catch (Throwable $throwable) {
            return $this->error($throwable);
        }
    }

    /**
     * EN: Opens a resumable upload only for an authenticated target manager.
     *
     * HR: Otvara nastavivi upload samo prijavljenom upravitelju cilja.
     */
    public function uploadStart(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $this->assertUploadAccess($body);
            $upload = $this->uploads->start(
                WorkspaceValue::string($body['name'] ?? ''),
                WorkspaceValue::int($body['size'] ?? 0),
                is_string($body['sha256'] ?? null) ? $body['sha256'] : null,
                $this->actorUserId(),
            );

            return $this->responses->json($this->uploadPayload($upload), 201);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Receives one binary archive chunk after checking upload-session ownership.
     *
     * HR: Prima jedan binarni dio arhiva uz provjeru vlasnika upload sesije.
     */
    public function uploadChunk(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($this->actorUserId() === null) {
                return $this->deniedJson();
            }

            $stream = $request->getBody()->detach();
            if (!is_resource($stream)) {
                throw new RuntimeException('Backup upload body is unavailable.');
            }

            $upload = $this->uploads->append(
                trim($request->getHeaderLine('X-Backup-Upload')),
                (int)$request->getHeaderLine('X-Backup-Offset'),
                $stream,
                $this->actorUserId(),
            );

            return $this->responses->json($this->uploadPayload($upload));
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Completes the upload and accepts Workspace archives only.
     *
     * HR: Zaključuje upload i prihvaća isključivo Workspace arhiv.
     */
    public function uploadFinish(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $this->assertUploadAccess($body);
            $upload = $this->uploads->finish(WorkspaceValue::string($body['uuid'] ?? ''), $this->actorUserId());
            $manifest = $this->manager->inspect(
                WorkspaceValue::string($upload['temp_path'] ?? ''),
                $this->passphrase($body),
            );
            $scope = is_array($manifest['scope'] ?? null) ? $manifest['scope'] : [];
            if (($scope['type'] ?? null) !== BackupScope::WORKSPACE) {
                throw new BackupException('Only a Workspace backup can be imported from this screen.');
            }

            return $this->responses->json([...$this->uploadPayload($upload), 'manifest' => $manifest]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Validates identities, dependencies, ACL, and conflict mode without mutations.
     *
     * HR: Provjerava identitete, ovisnosti, ACL i konfliktni način bez izmjena.
     */
    public function preflight(ServerRequestInterface $request): ResponseInterface
    {
        try {
            [$upload, $context] = $this->importInput($request);

            return $this->responses->json(
                $this->manager->preflight(WorkspaceValue::string($upload['temp_path'] ?? ''), $context)->toArray(),
            );
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Repeats preflight and runs a transactional restore with a safety snapshot.
     *
     * HR: Ponavlja preflight i pokreće transakcijski restore sa safety snapshotom.
     */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        try {
            [$upload, $context] = $this->importInput($request);
            $archive = WorkspaceValue::string($upload['temp_path'] ?? '');
            $preflight = $this->manager->preflight($archive, $context);
            if (!$preflight->isAllowed()) {
                return $this->responses->json($preflight->toArray(), 422);
            }

            $job = $this->jobs->create(
                'restore',
                $context->scope,
                $context->selectedProviders,
                $context->options,
                $context->actorUserId,
                $context->conflictMode,
                $archive,
            );
            $snapshot = $this->runner->runRestore($job, $context->passphrase);
            $completed = $this->jobs->findByUuid(WorkspaceValue::string($job['uuid'] ?? ''));
            $result = json_decode(
                WorkspaceValue::string($completed['result_json'] ?? '{}'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($result)) {
                throw new BackupException('Backup job result must be a structured report.');
            }

            return $this->responses->json([
                'restored' => true,
                'links' => $result['links'] ?? [],
                'safety_snapshot' => $snapshot,
            ]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Builds and authorizes an import context from a completed upload session.
     *
     * HR: Gradi i autorizira kontekst importa iz završene upload sesije.
     *
     * @return array{0:array<string,mixed>,1:BackupImportContext}
     */
    private function importInput(ServerRequestInterface $request): array
    {
        $body = $this->body($request);
        $upload = $this->uploads->requireSession(WorkspaceValue::string($body['uuid'] ?? ''), $this->actorUserId());
        if (($upload['status'] ?? null) !== 'completed') {
            throw new BackupException('Backup upload must be completed before preflight or restore.');
        }

        $target = WorkspaceValue::string($body['target_workspace'] ?? '');
        $mode = WorkspaceValue::string($body['conflict_mode'] ?? BackupImportContext::CONFLICT_REPLACE);
        $workspace = $target !== '' ? $this->repository->findWorkspaceBySlug($target) : null;
        if ($mode === BackupImportContext::CONFLICT_COPY) {
            if (!$this->access->isAdministrator()) {
                throw new BackupException('Only an administrator may import a backup as a new Workspace.');
            }

            if (is_array($workspace)) {
                throw new BackupException('The target Workspace already exists.');
            }
        } elseif (!is_array($workspace) || !$this->canManage($workspace)) {
            throw new BackupException('Manage permission is required for the target Workspace.');
        }

        return [$upload, new BackupImportContext(
            new BackupScope(BackupScope::WORKSPACE, $target),
            $mode,
            [],
            [],
            [
                'site_base_url' => $this->requestSiteUrl($request),
                'workspace-scope' => ['target_slug' => $target],
            ],
            $this->actorUserId(),
            $this->passphrase($body),
        )];
    }

    /**
     * EN: Uses a configured URL or the proxy-normalized request URI with the application base path.
     * Client-supplied target URLs and raw forwarded headers are not used.
     *
     * HR: Koristi podešeni URL ili proxy-normalizirani URI zahtjeva s osnovnom putanjom aplikacije.
     * Ne koristi ciljne URL-ove iz korisničkog unosa ni sirova forwarded zaglavlja.
     */
    private function requestSiteUrl(ServerRequestInterface $request): string
    {
        $configured = $this->manager->siteBaseUrl();
        if ($configured !== null) {
            return $configured;
        }

        $uri = $request->getUri();
        return $this->manager->siteBaseUrl($uri->getScheme() . '://' . $uri->getHost()
            . ($uri->getPort() !== null ? ':' . $uri->getPort() : '') . $this->urls->getBasePath())
        ?? throw new BackupException('Unable to determine the public application URL.');
    }

    /**
     * EN: Checks that the user manages the Workspace supplied with the upload.
     *
     * HR: Provjerava da je korisnik upravitelj područja navedenog uz upload.
     *
     * @param array<string,mixed> $body
     */
    private function assertUploadAccess(array $body): void
    {
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace) || !$this->canManage($workspace)) {
            throw new BackupException('Manage permission is required for this Workspace.');
        }
    }

    /**
     * EN: Finds a Workspace by ID or stable slug from the input.
     *
     * HR: Pronalazi područje prema ID-u ili stabilnom slugu iz ulaza.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function workspaceFromInput(array $input): ?array
    {
        $id = WorkspaceValue::int($input['workspace_id'] ?? $input['id'] ?? 0);
        $workspace = $id > 0
        ? $this->repository->findWorkspaceById($id)
        : $this->repository->findWorkspaceBySlug(WorkspaceValue::string(
            $input['workspace'] ?? $input['slug'] ?? $input['target_workspace'] ?? '',
        ));

        return is_array($workspace) ? WorkspaceValue::stringKeyArray($workspace) : null;
    }

    /**
     * EN: Checks the effective Workspace manage permission.
     *
     * HR: Provjerava efektivno pravo upravljanja područjem.
     *
     * @param array<string,mixed> $workspace
     */
    private function canManage(array $workspace): bool
    {
        return (bool)($this->access->workspacePermissions($workspace)['can_manage'] ?? false);
    }

    /**
     * EN: Reads a form body or a raw JSON request body.
     *
     * HR: Čita formular ili sirovo JSON tijelo zahtjeva.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return WorkspaceValue::stringKeyArray($body);
        }

        // EN: JSON upload requests do not necessarily have a pre-parsed body.
        //
        // HR: JSON upload pozivi nemaju nužno unaprijed parsirano tijelo.
        $decoded = json_decode((string)$request->getBody(), true);
        return is_array($decoded)
        ? WorkspaceValue::stringKeyArray($decoded)
        : (is_array($body) ? WorkspaceValue::stringKeyArray($body) : []);
    }

    /**
     * EN: Normalizes the optional archive passphrase without storing it.
     *
     * HR: Normalizira opcionalnu zaporku arhiva bez pohrane.
     *
     * @param array<string,mixed> $body
     */
    private function passphrase(array $body): ?string
    {
        $value = is_string($body['passphrase'] ?? null) ? trim($body['passphrase']) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * EN: Returns the authenticated actor ID.
     *
     * HR: Vraća ID prijavljenog izvršitelja.
     */
    private function actorUserId(): ?int
    {
        $id = WorkspaceValue::int($this->access->currentUser()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * EN: Reduces an upload-session record to a safe public response.
     *
     * HR: Svodi zapis upload sesije na siguran javni odgovor.
     *
     * @param array<string,mixed> $upload
     * @return array<string,mixed>
     */
    private function uploadPayload(array $upload): array
    {
        return [
            'uuid' => $upload['uuid'] ?? null,
            'status' => $upload['status'] ?? null,
            'total_size' => WorkspaceValue::int($upload['total_size'] ?? 0),
            'chunk_size' => WorkspaceValue::int($upload['chunk_size'] ?? 0),
            'next_offset' => WorkspaceValue::int($upload['next_offset'] ?? 0),
            'sha256' => $upload['actual_sha256'] ?? null,
        ];
    }

    /**
     * EN: Returns a localized HTML 403 response.
     *
     * HR: Vraća lokalizirani HTML 403 odgovor.
     */
    private function denied(): ResponseInterface
    {
        return $this->responses->text(__('Nedozvoljen pristup'), 403);
    }

    /**
     * EN: Returns a localized JSON 403 response.
     *
     * HR: Vraća lokalizirani JSON 403 odgovor.
     */
    private function deniedJson(): ResponseInterface
    {
        return $this->responses->json(['error' => __('Nedozvoljen pristup')], 403);
    }

    /**
     * EN: Converts an exception to an HTML response.
     *
     * HR: Pretvara iznimku u HTML odgovor.
     */
    private function error(Throwable $throwable): ResponseInterface
    {
        return $this->responses->text($throwable->getMessage(), $throwable instanceof BackupException ? 422 : 500);
    }

    /**
     * EN: Converts an exception to a JSON response.
     *
     * HR: Pretvara iznimku u JSON odgovor.
     */
    private function errorJson(Throwable $throwable): ResponseInterface
    {
        return $this->responses->json(
            ['error' => $throwable->getMessage()],
            $throwable instanceof BackupException ? 422 : 500,
        );
    }
}
