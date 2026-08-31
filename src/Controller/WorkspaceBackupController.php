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
 * HR: Povezuje generički Backup s ACL pravilima jednog područja bez obrnutog
 *     povezivanja Backup modula na Workspace poslovni model.
 *
 * EN: Connects generic Backup orchestration to one Workspace's ACL rules
 *     without coupling the Backup module back to the Workspace domain model.
 */
final readonly class WorkspaceBackupController
{
    /**
     * HR: Prima samo javne Backup servise i Workspace ACL/repository servise.
     * EN: Receives only public Backup services and Workspace ACL/repository services.
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
     * HR: Prikazuje sigurni izvoz/import samo upravitelju odabranog područja.
     * EN: Shows secure export/import only to a manager of the selected Workspace.
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
     * HR: Vraća aktualni CSRF token samo upravitelju zadanog područja.
     * EN: Returns the current CSRF token only to a manager of the requested Workspace.
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
     * HR: Izrađuje šifrirani prenosivi arhiv samo od Workspace-scope providera.
     * EN: Creates an encrypted portable archive from Workspace-scope providers only.
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
                [],
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
     * HR: Otvara nastavivi upload samo prijavljenom upravitelju cilja.
     * EN: Opens a resumable upload only for an authenticated target manager.
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
     * HR: Prima jedan binarni dio arhiva uz provjeru vlasnika upload sesije.
     * EN: Receives one binary archive chunk after checking upload-session ownership.
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
     * HR: Zaključuje upload i prihvaća isključivo Workspace arhiv.
     * EN: Completes the upload and accepts Workspace archives only.
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
     * HR: Provjerava identitete, ovisnosti, ACL i konfliktni način bez izmjena.
     * EN: Validates identities, dependencies, ACL, and conflict mode without mutations.
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
     * HR: Ponavlja preflight i pokreće transakcijski restore sa safety snapshotom.
     * EN: Repeats preflight and runs a transactional restore with a safety snapshot.
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

            return $this->responses->json(['restored' => true, 'safety_snapshot' => $snapshot]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * HR: Gradi i autorizira kontekst importa iz završene upload sesije.
     * EN: Builds and authorizes an import context from a completed upload session.
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
            ['workspace-scope' => ['target_slug' => $target]],
            $this->actorUserId(),
            $this->passphrase($body),
        )];
    }

    /**
     * HR: Provjerava da je korisnik upravitelj područja navedenog uz upload.
     * EN: Checks that the user manages the Workspace supplied with the upload.
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
     * HR: Pronalazi područje prema ID-u ili stabilnom slugu iz ulaza.
     * EN: Finds a Workspace by ID or stable slug from the input.
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
     * HR: Provjerava efektivno pravo upravljanja područjem.
     * EN: Checks the effective Workspace manage permission.
     *
     * @param array<string,mixed> $workspace
     */
    private function canManage(array $workspace): bool
    {
        return (bool)($this->access->workspacePermissions($workspace)['can_manage'] ?? false);
    }

    /**
     * HR: Čita formular ili sirovo JSON tijelo zahtjeva.
     * EN: Reads a form body or a raw JSON request body.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return WorkspaceValue::stringKeyArray($body);
        }

        // HR: JSON upload pozivi nemaju nužno unaprijed parsirano tijelo.
        // EN: JSON upload requests do not necessarily have a pre-parsed body.
        $decoded = json_decode((string)$request->getBody(), true);
        return is_array($decoded)
        ? WorkspaceValue::stringKeyArray($decoded)
        : (is_array($body) ? WorkspaceValue::stringKeyArray($body) : []);
    }

    /**
     * HR: Normalizira opcionalnu zaporku arhiva bez pohrane.
     * EN: Normalizes the optional archive passphrase without storing it.
     *
     * @param array<string,mixed> $body
     */
    private function passphrase(array $body): ?string
    {
        $value = is_string($body['passphrase'] ?? null) ? trim($body['passphrase']) : '';
        return $value !== '' ? $value : null;
    }

    /** HR: Vraća ID prijavljenog izvršitelja. EN: Returns the authenticated actor ID. */
    private function actorUserId(): ?int
    {
        $id = WorkspaceValue::int($this->access->currentUser()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * HR: Svodi zapis upload sesije na siguran javni odgovor.
     * EN: Reduces an upload-session record to a safe public response.
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

    /** HR: Vraća lokalizirani HTML 403 odgovor. EN: Returns a localized HTML 403 response. */
    private function denied(): ResponseInterface
    {
        return $this->responses->text(__('Nedozvoljen pristup'), 403);
    }

    /** HR: Vraća lokalizirani JSON 403 odgovor. EN: Returns a localized JSON 403 response. */
    private function deniedJson(): ResponseInterface
    {
        return $this->responses->json(['error' => __('Nedozvoljen pristup')], 403);
    }

    /** HR: Pretvara iznimku u HTML odgovor. EN: Converts an exception to an HTML response. */
    private function error(Throwable $throwable): ResponseInterface
    {
        return $this->responses->text($throwable->getMessage(), $throwable instanceof BackupException ? 422 : 500);
    }

    /** HR: Pretvara iznimku u JSON odgovor. EN: Converts an exception to a JSON response. */
    private function errorJson(Throwable $throwable): ResponseInterface
    {
        return $this->responses->json(
            ['error' => $throwable->getMessage()],
            $throwable instanceof BackupException ? 422 : 500,
        );
    }
}
