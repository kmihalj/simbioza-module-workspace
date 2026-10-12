<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Api;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * EN: Translates versioned HTTP requests into ACL-aware Workspace operations.
 *
 * HR: Pretvara verzionirane HTTP zahtjeve u ACL-svjesne Workspace operacije.
 */
final readonly class WorkspaceResourceController
{
    /**
     * EN: Initializes the HTTP adapter with the shared response factory and Workspace service.
     *
     * HR: Inicijalizira HTTP adapter zajedničkom tvornicom odgovora i Workspace servisom.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private ResponseFactory $httpResponses,
        private WorkspaceApiService $workspaces,
        private ConfigInterface $config,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * EN: Returns workspaces visible to the API key owner.
     *
     * HR: Vraća područja vidljiva vlasniku API ključa.
     */
    public function listWorkspaces(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->workspaces->listWorkspaces($user),
                ),
        );
    }

    /**
     * EN: Returns one Workspace when the key owner may view it.
     *
     * HR: Vraća jedno područje ako ga vlasnik ključa smije vidjeti.
     */
    public function getWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): array => $this->workspaces->getWorkspace(
                $this->routeString($request, 'workspaceSlug'),
                $user,
            ),
        );
    }

    /**
     * EN: Returns the filtered Workspace tree and effective node permissions.
     *
     * HR: Vraća filtrirano stablo područja i efektivna prava čvorova.
     */
    public function getTree(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): array => $this->workspaces->getTree(
                $this->routeString($request, 'workspaceSlug'),
                $user,
                $this->language($request),
            ),
        );
    }

    /**
     * EN: Returns ACL-filtered summaries of published Workspace pages.
     *
     * HR: Vraća ACL-filtrirane sažetke objavljenih stranica područja.
     */
    public function getShorts(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): array => $this->workspaces->getShorts(
                $this->routeString($request, 'workspaceSlug'),
                $this->language($request),
                $this->stringKeyArray($request->getQueryParams()),
                $user,
            ),
        );
    }

    /**
     * EN: Downloads an ACL-filtered offline HTML ZIP of a Workspace or selected pages.
     *
     * HR: Preuzima ACL-filtrirani offline HTML ZIP cijelog područja ili izabranih stranica.
     */
    public function exportWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('workspace:manage')) {
            return $this->responses->problem(
                $request,
                403,
                'insufficient_scope',
                __('Pristup nije dozvoljen'),
                sprintf(__('API ključ nema potreban scope "%s".'), 'workspace:manage'),
            );
        }

        try {
            $payload = $this->jsonBody($request);
            $export = $this->workspaces->exportWorkspace(
                $this->routeString($request, 'workspaceSlug'),
                $this->positiveIntList($payload['node_ids'] ?? []),
                $identity->user,
            );

            return $this->httpResponses->download(
                $export->content,
                $export->fileName,
                $export->mimeType,
                headers: [
                    'Cache-Control' => 'private, no-store',
                    'X-Request-Id' => $this->responses->requestId($request),
                ],
            );
        } catch (JsonException $exception) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $exception->getMessage(),
            );
        } catch (WorkspaceApiException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                'workspace_export_failed',
                __('Workspace operaciju nije moguće izvršiti'),
                __('HTML paket područja nije moguće stvoriti.'),
            );
        }
    }

    /**
     * EN: Returns Workspace theme settings, system choices, and private files.
     *
     * HR: Vraća postavke teme područja, sistemske izbore i privatne datoteke.
     */
    public function getWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getWorkspaceTheme(
                $this->routeString($request, 'workspaceSlug'),
                $user,
            ),
        );
    }

    /**
     * EN: Selects inheritance or one unchanged system theme for a Workspace.
     *
     * HR: Odabire nasljeđivanje ili jednu nepromijenjenu sistemsku temu područja.
     */
    public function selectWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->selectWorkspaceTheme(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Stores a private theme configuration only for the supplied Workspace.
     *
     * HR: Sprema privatnu konfiguraciju teme samo za zadano područje.
     */
    public function saveWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->saveWorkspaceTheme(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Imports a theme ZIP from the `theme` multipart field into a private Workspace theme.
     *
     * HR: Uvozi ZIP teme iz multipart polja `theme` u privatnu temu područja.
     */
    public function importWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $body = $this->formBody($request);

                return $this->workspaces->importWorkspaceTheme(
                    $this->routeString($request, 'workspaceSlug'),
                    $this->uploadedFile($request, 'theme'),
                    is_scalar($body['mode_policy'] ?? null)
                        ? trim((string)$body['mode_policy'])
                        : 'auto',
                    $user,
                );
            },
        );
    }

    /**
     * EN: Downloads a Workspace private-theme ZIP for an administrator.
     *
     * HR: Administratoru preuzima ZIP privatne teme područja.
     */
    public function exportWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->downloadExport(
            $request,
            fn(array $user): \AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExport =>
                $this->workspaces->exportWorkspaceTheme(
                    $this->routeString($request, 'workspaceSlug'),
                    $user,
                ),
            'workspace_theme_export_failed',
            __('Paket teme područja nije moguće stvoriti.'),
        );
    }

    /**
     * EN: Stores an image from the `asset` multipart field in the private theme library.
     *
     * HR: Sprema sliku iz multipart polja `asset` u privatnu biblioteku teme.
     */
    public function uploadWorkspaceThemeAsset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $body = $this->formBody($request);

                return $this->workspaces->uploadWorkspaceThemeAsset(
                    $this->routeString($request, 'workspaceSlug'),
                    $this->uploadedFile($request, 'asset'),
                    is_scalar($body['role'] ?? null) ? trim((string)$body['role']) : 'other',
                    $user,
                );
            },
        );
    }

    /**
     * EN: Deletes an unused Workspace private-theme file.
     *
     * HR: Briše nekorištenu datoteku privatne teme područja.
     */
    public function deleteWorkspaceThemeAsset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->deleteWorkspaceThemeAsset(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeString($request, 'file'),
                $user,
            ),
        );
    }

    /**
     * EN: Returns administrator-only public and authenticated homepage policy.
     *
     * HR: Vraća administratorsku javnu i prijavljenu politiku naslovnice.
     */
    public function getHomepageSettings(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getHomepageSettings($user),
        );
    }

    /**
     * EN: Stores administrator-only public and authenticated homepage policy.
     *
     * HR: Sprema administratorsku javnu i prijavljenu politiku naslovnice.
     */
    public function saveHomepageSettings(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->saveHomepageSettings(
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Returns the API-key owner's personal homepage when selection is enabled.
     *
     * HR: Vraća osobnu naslovnicu vlasnika API ključa kada je odabir omogućen.
     */
    public function getHomepagePreference(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): ?array => $this->workspaces->getHomepagePreference($user),
        );
    }

    /**
     * EN: Stores the API-key owner's personal homepage after a fresh ACL check.
     *
     * HR: Sprema osobnu naslovnicu vlasnika API ključa uz novu ACL provjeru.
     */
    public function saveHomepagePreference(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            function (array $user) use ($request): ?array {
                $payload = $this->jsonBody($request);
                $selection = $payload['selection'] ?? $payload;
                if (!is_array($selection) && !is_numeric($selection)) {
                    throw $this->validationError(
                        __('Polje "selection" mora biti JSON objekt ili pozitivan broj.'),
                    );
                }

                return $this->workspaces->saveHomepagePreference(
                    is_array($selection)
                        ? $this->stringKeyArray($selection)
                        : (int)$selection,
                    $user,
                );
            },
        );
    }

    /**
     * EN: Creates a new Workspace subject to the application's creation policy.
     *
     * HR: Kreira novo područje uz aplikacijsko pravilo o dozvoli kreiranja.
     */
    public function createWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->createWorkspace(
                $this->jsonBody($request),
                $user,
            ),
            201,
        );
    }

    /**
     * EN: Partially updates a Workspace with the effective can_manage permission.
     *
     * HR: Djelomično mijenja područje uz efektivno can_manage pravo.
     */
    public function updateWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $slug = $this->routeString($request, 'workspaceSlug');
                $this->entityTags->assertMatches(
                    $request,
                    $this->workspaces->getWorkspace($slug, $user),
                );

                return $this->workspaces->updateWorkspace(
                    $slug,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * EN: Soft-deletes a Workspace with the effective can_manage permission.
     *
     * HR: Soft-briše područje uz efektivno can_manage pravo.
     */
    public function deleteWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): null {
                $slug = $this->routeString($request, 'workspaceSlug');
                $this->entityTags->assertMatches(
                    $request,
                    $this->workspaces->getWorkspace($slug, $user),
                );
                $this->workspaces->deleteWorkspace(
                    $slug,
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * EN: Returns the administrator-only list of soft-deleted workspaces.
     *
     * HR: Vraća administratorski popis soft-obrisanih područja.
     */
    public function listDeletedWorkspaces(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->workspaces->listDeletedWorkspaces($user),
                ),
        );
    }

    /**
     * EN: Restores a soft-deleted Workspace under an available slug.
     *
     * HR: Vraća soft-obrisano područje pod slobodnim slugom.
     */
    public function restoreWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);

                return $this->workspaces->restoreWorkspace(
                    $this->routeId($request, 'workspaceId'),
                    is_scalar($payload['slug'] ?? null) ? trim((string)$payload['slug']) : '',
                    $user,
                );
            },
        );
    }

    /**
     * EN: Returns the direct Workspace ACL to a user who may manage it.
     *
     * HR: Vraća izravni ACL područja korisniku koji njime smije upravljati.
     */
    public function getWorkspaceAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getWorkspaceAcl(
                $this->routeString($request, 'workspaceSlug'),
                $user,
            ),
        );
    }

    /**
     * EN: Replaces the complete Workspace ACL.
     *
     * HR: Zamjenjuje potpuni ACL područja.
     */
    public function replaceWorkspaceAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->replaceWorkspaceAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Searches users or groups for the Workspace ACL picker.
     *
     * HR: Pretražuje korisnike ili grupe za Workspace ACL picker.
     */
    public function searchAclSubjects(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = is_scalar($query['category'] ?? null) ? trim((string)$query['category']) : '';
        $search = is_scalar($query['q'] ?? null) ? trim((string)$query['q']) : '';

        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->searchAclSubjects(
                $this->routeString($request, 'workspaceSlug'),
                $category,
                $search,
                $user,
            ),
        );
    }

    /**
     * EN: Adds an internal or external link to the tree.
     *
     * HR: Dodaje interni ili vanjski link u stablo.
     */
    public function createLinkNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->createLinkNode(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
            201,
            'id',
        );
    }

    /**
     * EN: Updates structural data for one tree node.
     *
     * HR: Mijenja strukturne podatke jednog čvora stabla.
     */
    public function updateNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->updateNode(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Deletes a link node; documents remain the Editor API's responsibility.
     *
     * HR: Briše link-čvor; dokumenti ostaju odgovornost Editor API-ja.
     */
    public function deleteLinkNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): null {
                $this->workspaces->deleteLinkNode(
                    $this->routeString($request, 'workspaceSlug'),
                    $this->routeId($request, 'nodeId'),
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * EN: Stores the complete tree order and hierarchy.
     *
     * HR: Sprema potpuni poredak i hijerarhiju stabla.
     */
    public function reorderTree(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);
                $placements = $payload['placements'] ?? null;
                if (!is_array($placements) || !array_is_list($placements)) {
                    throw $this->validationError(__('Polje "placements" mora biti JSON lista.'));
                }

                $normalized = [];
                foreach ($placements as $placement) {
                    if (!is_array($placement)) {
                        throw $this->validationError(
                            __('Svaki raspored čvora mora biti JSON objekt.'),
                        );
                    }

                    $normalized[] = $this->stringKeyArray($placement);
                }

                $slug = $this->routeString($request, 'workspaceSlug');
                $this->workspaces->reorderTree($slug, $normalized, $user);

                return $this->workspaces->getTree($slug, $user, $this->language($request));
            },
        );
    }

    /**
     * EN: Returns direct ACL restrictions for one node.
     *
     * HR: Vraća izravna ACL ograničenja jednog čvora.
     */
    public function getNodeAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getNodeAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $user,
            ),
        );
    }

    /**
     * EN: Replaces direct ACL restrictions for one node.
     *
     * HR: Zamjenjuje izravna ACL ograničenja jednog čvora.
     */
    public function replaceNodeAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->replaceNodeAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * EN: Checks the scope, invokes the operation, and consistently maps expected failures.
     *
     * HR: Provjerava scope, poziva operaciju i ujednačeno mapira očekivane greške.
     *
     * @param callable(array<string,mixed>):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
        int $status = 200,
        string $locationField = 'slug',
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->responses->problem(
                $request,
                403,
                'insufficient_scope',
                __('Pristup nije dozvoljen'),
                sprintf(__('API ključ nema potreban scope "%s".'), $scope),
            );
        }

        try {
            $data = $operation($identity->user);
            if ($status === 204) {
                return $this->responses->noContent($request);
            }

            $response = $this->responses->success(
                $request,
                $data,
                $status,
                links: ['self' => $this->responses->requestTarget($request)],
            );
            if ($status === 201 && is_array($data) && is_scalar($data[$locationField] ?? null)) {
                return $response->withHeader(
                    'Location',
                    $this->responses->childTarget($request, (string)$data[$locationField]),
                );
            }

            return $response;
        } catch (ApiPreconditionException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Uvjet izmjene nije ispunjen'),
                $exception->getMessage(),
            );
        } catch (JsonException $exception) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $exception->getMessage(),
            );
        } catch (WorkspaceApiException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->responses->problem(
                $request,
                422,
                'workspace_validation_failed',
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                'internal_error',
                __('Interna greška'),
                __('Zahtjev nije moguće obraditi. Obrati se administratoru uz request ID.'),
            );
        }
    }

    /**
     * EN: Returns the authenticated identity attached by the API middleware.
     *
     * HR: Vraća autentificirani identitet koji je postavio API middleware.
     */
    private function identity(ServerRequestInterface $request): AuthApiIdentity
    {
        $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AuthApiIdentity) {
            throw new RuntimeException('Authenticated API identity is missing.');
        }

        return $identity;
    }

    /**
     * EN: Downloads a binary Workspace ZIP with consistent scope and error handling.
     *
     * HR: Preuzima binarni Workspace ZIP uz jedinstvenu provjeru scopea i grešaka.
     *
     * @param callable(array<string,mixed>):\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExport $operation
     */
    private function downloadExport(
        ServerRequestInterface $request,
        callable $operation,
        string $errorCode,
        string $failureMessage,
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits('workspace:manage')) {
            return $this->responses->problem(
                $request,
                403,
                'insufficient_scope',
                __('Pristup nije dozvoljen'),
                sprintf(__('API ključ nema potreban scope "%s".'), 'workspace:manage'),
            );
        }

        try {
            $export = $operation($identity->user);

            return $this->httpResponses->download(
                $export->content,
                $export->fileName,
                $export->mimeType,
                headers: [
                    'Cache-Control' => 'private, no-store',
                    'X-Request-Id' => $this->responses->requestId($request),
                ],
            );
        } catch (WorkspaceApiException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->responses->problem(
                $request,
                422,
                'workspace_validation_failed',
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                $errorCode,
                __('Workspace operaciju nije moguće izvršiti'),
                $failureMessage,
            );
        }
    }

    /**
     * EN: Decodes and validates a JSON object from the request body.
     *
     * HR: Dekodira i validira JSON objekt iz tijela zahtjeva.
     *
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $raw = trim((string)$request->getBody());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException(__('JSON tijelo mora biti objekt.'));
        }

        return $this->stringKeyArray($decoded);
    }

    /**
     * EN: Normalizes a standard multipart body into a string-keyed array.
     *
     * HR: Normalizira standardno multipart tijelo u polje sa string ključevima.
     *
     * @return array<string,mixed>
     */
    private function formBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $this->stringKeyArray($body) : [];
    }

    /**
     * EN: Returns the required file from a named multipart field.
     *
     * HR: Vraća obaveznu datoteku iz imenovanog multipart polja.
     */
    private function uploadedFile(
        ServerRequestInterface $request,
        string $field,
    ): UploadedFileInterface {
        $file = $request->getUploadedFiles()[$field] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw $this->validationError(
                sprintf(__('Multipart polje "%s" je obavezno.'), $field),
            );
        }

        return $file;
    }

    /**
     * EN: Reads a positive numeric ID from a route attribute.
     *
     * HR: Čita pozitivni numerički ID iz route atributa.
     */
    private function routeId(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /**
     * EN: Reads a string value from a route attribute.
     *
     * HR: Čita tekstualnu vrijednost route atributa.
     */
    private function routeString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * EN: Returns a safe tree language from the query or the application default locale.
     *
     * HR: Vraća sigurni jezik stabla iz queryja ili zadane aplikacijske lokalizacije.
     */
    private function language(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $candidate = is_scalar($query['lang'] ?? null)
        ? trim((string)$query['lang'])
        : trim(
            $this->config->getAsString('localization.locale')
                    ?? $this->config->getAsString('app.localization.locale')
                    ?? $this->config->getAsString('app.locale')
                    ?? '',
        );

        return preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $candidate) === 1
        ? strtolower($candidate)
        : 'hr';
    }

    /**
     * EN: Keeps only string keys from an input array.
     *
     * HR: Zadržava samo string ključeve ulaznog polja.
     *
     * @param array<mixed,mixed> $values
     * @return array<string,mixed>
     */
    private function stringKeyArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * EN: Normalizes an optional JSON ID list; an empty list means the complete Workspace.
     *
     * HR: Normalizira opcionalnu JSON listu ID-eva; prazna lista znači cijelo područje.
     *
     * @return list<int>
     */
    private function positiveIntList(mixed $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        if (!is_array($values) || !array_is_list($values)) {
            throw $this->validationError(__('Polje "node_ids" mora biti JSON lista.'));
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_numeric($value) || (int)$value <= 0) {
                throw $this->validationError(__('Svaki ID stranice mora biti pozitivan broj.'));
            }

            $result[] = (int)$value;
        }

        return array_values(array_unique($result));
    }

    /**
     * EN: Builds a stable validation failure for an invalid request structure.
     *
     * HR: Gradi stabilnu validacijsku grešku za neispravnu strukturu zahtjeva.
     */
    private function validationError(string $message): WorkspaceApiException
    {
        return new WorkspaceApiException('workspace_validation_failed', $message, 422);
    }
}
