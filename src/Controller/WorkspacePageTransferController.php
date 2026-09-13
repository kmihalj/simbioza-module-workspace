<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function bin2hex;
use function count;
use function http_build_query;
use function is_array;
use function random_bytes;
use function rawurlencode;
use function trim;
use function unlink;

/**
 * HR: Kopira ili premješta jednu Workspace stranicu kroz isti prenosivi Backup
 *     format koji se koristi i za ručni izvoz/import.
 * EN: Copies or moves one Workspace page through the same portable Backup
 *     format used by manual export/import.
 */
final readonly class WorkspacePageTransferController
{
    /** HR: Prima servise prijenosa i ACL-a. EN: Receives transfer and ACL services. */
    public function __construct(
        private ResponseFactory $responses,
        private WorkspaceModuleViewRenderer $views,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceEditorBridge $editor,
        private BackupManager $backups,
        private UrlGenerator $urls,
        private AlertHandler $alerts,
    ) {
    }

    /** HR: Prikazuje jasan izbor ciljnog područja i opcija. EN: Shows target and transfer options. */
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        [$workspace, $node] = $this->source(WorkspaceValue::stringKeyArray($request->getQueryParams()));
        if (!is_array($workspace) || !is_array($node)) {
            return $this->responses->text(__('Stranica nije pronađena.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->denied();
        }

        return $this->views->render('workspace/page-transfer', [
            'title' => __('Kopiranje ili premještanje stranice'),
            'workspace' => $workspace,
            'node' => $node,
            'subtreeCount' => count($this->repository->nodesInSubtree(
                WorkspaceValue::int($workspace['id'] ?? 0),
                WorkspaceValue::int($node['id'] ?? 0),
            )),
            'submitPath' => $this->urls->getPathFor('workspace.page.transfer.perform'),
            'returnPath' => $this->pagePath($workspace, $node),
            'workspaceLookupPath' => $this->urls->getPathFor('workspace.lookup.workspaces'),
            'pageLookupPath' => $this->urls->getPathFor('workspace.lookup.pages'),
            'assetsCssPath' => $this->urls->getPathFor('workspace.assets.css'),
            'assetsJsPath' => $this->urls->getPathFor('workspace.assets.js'),
        ]);
    }

    /** HR: Izvršava kopiranje te izvor briše tek nakon uspješnog importa. EN: Copies, then deletes source after success. */
    public function perform(ServerRequestInterface $request): ResponseInterface
    {
        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        [$workspace, $node] = $this->source($body);
        if (!is_array($workspace) || !is_array($node)) {
            return $this->responses->text(__('Stranica nije pronađena.'), 404);
        }

        if (!$this->canManage($workspace)) {
            return $this->denied();
        }

        $targetWorkspace = WorkspaceValue::stringKeyArray(
            $this->repository->findWorkspaceById(WorkspaceValue::int($body['target_workspace_id'] ?? 0)),
        );
        if ($targetWorkspace === [] || !$this->canManage($targetWorkspace)) {
            $this->failure(__('Morate imati pravo upravljanja i u ciljnom području.'));
            return $this->responses->redirect($this->formPath($workspace, $node));
        }

        $targetParentId = WorkspaceValue::int($body['target_parent_id'] ?? 0);
        if ($targetParentId > 0) {
            $parent = $this->repository->findNodeById($targetParentId);
            if (
                !is_array($parent)
                || WorkspaceValue::int($parent['workspace_id'] ?? 0)
                    !== WorkspaceValue::int($targetWorkspace['id'] ?? 0)
            ) {
                $this->failure(__('Odabrana nadređena stranica ne pripada ciljnom području.'));
                return $this->responses->redirect($this->formPath($workspace, $node));
            }
        }

        $operation = WorkspaceValue::string($body['operation'] ?? 'copy');
        $move = $operation === 'move';
        $subtree = $this->repository->nodesInSubtree(
            WorkspaceValue::int($workspace['id'] ?? 0),
            WorkspaceValue::int($node['id'] ?? 0),
        );
        if ($move && count($subtree) !== 1) {
            $this->failure(__('Stranicu s podređenim stavkama zasad možete kopirati, ali ne i premjestiti.'));
            return $this->responses->redirect($this->formPath($workspace, $node));
        }

        $includeHistory = WorkspaceValue::string($body['include_history'] ?? '') === '1';
        $includePermissions = WorkspaceValue::string($body['include_permissions'] ?? '') === '1';
        $actorId = WorkspaceValue::int($this->access->currentUser()['id'] ?? 0);
        $passphrase = bin2hex(random_bytes(32));
        $archive = null;

        try {
            $sourceId = WorkspaceValue::int($node['id'] ?? 0);
            $sourceUuid = WorkspaceValue::string($node['uuid'] ?? '');
            $exportOptions = [
                'page-transfer' => [
                    'include_permissions' => $includePermissions,
                    'include_history' => $includeHistory,
                ],
                'editor-html-workspace' => [
                    'include_permissions' => $includePermissions,
                    'include_history' => $includeHistory,
                ],
            ];
            $archive = $this->backups->create(new BackupExportContext(
                new BackupScope(BackupScope::PAGE, (string)$sourceId),
                [],
                $exportOptions,
                $actorId,
                $passphrase,
            ), 'page-transfer');

            $targetSlug = WorkspaceValue::string($targetWorkspace['slug'] ?? '');
            $import = new BackupImportContext(
                new BackupScope(BackupScope::PAGE, $targetSlug),
                BackupImportContext::CONFLICT_COPY,
                [],
                [],
                [
                    'page-transfer' => [
                        'target_workspace' => $targetSlug,
                        'target_parent_id' => $targetParentId > 0 ? $targetParentId : null,
                        'import_permissions' => $includePermissions,
                    ],
                    'editor-html-workspace' => [
                        'target_workspace' => $targetSlug,
                        'import_permissions' => $includePermissions,
                    ],
                    'comment-workspace' => ['fallback_users_to_actor' => true],
                    'task-workspace' => ['fallback_users_to_actor' => true],
                ],
                $actorId,
                $passphrase,
            );
            $preflight = $this->backups->preflight($archive, $import);
            if (!$preflight->isAllowed()) {
                throw new \RuntimeException(implode(' ', $preflight->errors));
            }

            $this->backups->restore($archive, $import);

            $newSlug = (string)$import->state->require('page-transfer.target-slug', $sourceUuid);
            if ($move) {
                $this->repository->disableNodeTree(
                    WorkspaceValue::int($workspace['id'] ?? 0),
                    $sourceId,
                    $actorId,
                );
                $this->editor->deleteDocument(WorkspaceValue::string($node['document_key'] ?? ''));
            }

            $this->alerts->add(new Alert(
                $move ? __('Stranica je premještena.') : __('Stranica je kopirana.'),
                AlertLevelEnum::Success,
            ));
            return $this->responses->redirect($this->nodePath($targetSlug, $newSlug));
        } catch (Throwable $throwable) {
            $this->failure($throwable->getMessage());
            return $this->responses->redirect($this->formPath($workspace, $node));
        } finally {
            if (is_string($archive) && $archive !== '' && is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /**
     * HR: Pronalazi dosljedan izvorni čvor i područje.
     * EN: Finds a consistent source node and Workspace.
     * @param array<string,mixed> $input
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>}
     */
    private function source(array $input): array
    {
        $workspace = WorkspaceValue::stringKeyArray(
            $this->repository->findWorkspaceById(WorkspaceValue::int($input['workspace_id'] ?? 0)),
        );
        $node = WorkspaceValue::stringKeyArray(
            $this->repository->findNodeById(WorkspaceValue::int($input['node_id'] ?? 0)),
        );
        if (
            $workspace === []
            || $node === []
            || WorkspaceValue::int($node['workspace_id'] ?? 0) !== WorkspaceValue::int($workspace['id'] ?? 0)
            || WorkspaceValue::string($node['node_type'] ?? '') !== 'document'
        ) {
            return [null, null];
        }

        return [$workspace, $node];
    }

    /** HR: Provjerava upravljanje područjem. EN: Checks Workspace management permission.
     * @param array<string,mixed> $workspace */
    private function canManage(array $workspace): bool
    {
        return $this->access->isAdministrator()
        || (bool)($this->access->workspacePermissions($workspace)['can_manage'] ?? false);
    }

    /**
     * HR: Gradi adresu obrasca prijenosa. EN: Builds the transfer-form address.
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $node
     */
    private function formPath(array $workspace, array $node): string
    {
        return $this->urls->getPathFor('workspace.page.transfer') . '?' . http_build_query([
            'workspace_id' => WorkspaceValue::int($workspace['id'] ?? 0),
            'node_id' => WorkspaceValue::int($node['id'] ?? 0),
        ]);
    }

    /**
     * HR: Gradi adresu prikaza stranice. EN: Builds the page-view address.
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $node
     */
    private function pagePath(array $workspace, array $node): string
    {
        return $this->nodePath(
            WorkspaceValue::string($workspace['slug'] ?? ''),
            WorkspaceValue::string($node['slug'] ?? ''),
        );
    }

    /** HR: Gradi stabilnu adresu čvora. EN: Builds the stable node address. */
    private function nodePath(string $workspaceSlug, string $nodeSlug): string
    {
        if ($this->urls->namedRouteExists('workspace.node.show')) {
            return $this->urls->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        }

        return '/workspaces/' . rawurlencode($workspaceSlug) . '/' . rawurlencode($nodeSlug);
    }

    /** HR: Prikazuje odbijeni pristup. EN: Displays denied access. */
    private function denied(): ResponseInterface
    {
        return $this->views->render('workspace/access-denied', [
            'title' => __('Nedozvoljen pristup'),
            'message' => __('Nemate pravo upravljanja izvornim i ciljnim područjem.'),
            'indexPath' => $this->urls->getPathFor('workspace.index'),
        ], true, 403);
    }

    /** HR: Prikazuje neuspjeh radnje. EN: Displays an operation failure. */
    private function failure(string $message): void
    {
        $this->alerts->add(new Alert(
            trim($message) !== '' ? $message : __('Radnju nije moguće dovršiti.'),
            AlertLevelEnum::Danger,
        ));
    }
}
