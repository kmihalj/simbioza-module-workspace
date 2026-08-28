<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Api;

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
 * HR: Pretvara verzionirane HTTP zahtjeve u ACL-svjesne Workspace operacije.
 *
 * EN: Translates versioned HTTP requests into ACL-aware Workspace operations.
 */
final readonly class WorkspaceResourceController
{
    /**
     * HR: Inicijalizira HTTP adapter zajedničkom tvornicom odgovora i Workspace servisom.
     *
     * EN: Initializes the HTTP adapter with the shared response factory and Workspace service.
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
     * HR: Vraća područja vidljiva vlasniku API ključa.
     * EN: Returns workspaces visible to the API key owner.
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
     * HR: Vraća jedno područje ako ga vlasnik ključa smije vidjeti.
     * EN: Returns one Workspace when the key owner may view it.
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
     * HR: Vraća filtrirano stablo područja i efektivna prava čvorova.
     * EN: Returns the filtered Workspace tree and effective node permissions.
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
     * HR: Vraća ACL-filtrirane sažetke objavljenih stranica područja.
     * EN: Returns ACL-filtered summaries of published Workspace pages.
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
     * HR: Preuzima ACL-filtrirani offline HTML ZIP cijelog područja ili izabranih stranica.
     * EN: Downloads an ACL-filtered offline HTML ZIP of a Workspace or selected pages.
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
     * HR: Vraća postavke teme područja, sistemske izbore i privatne datoteke.
     * EN: Returns Workspace theme settings, system choices, and private files.
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
     * HR: Odabire nasljeđivanje ili jednu nepromijenjenu sistemsku temu područja.
     * EN: Selects inheritance or one unchanged system theme for a Workspace.
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
     * HR: Sprema privatnu konfiguraciju teme samo za zadano područje.
     * EN: Stores a private theme configuration only for the supplied Workspace.
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
     * HR: Uvozi ZIP teme iz multipart polja `theme` u privatnu temu područja.
     * EN: Imports a theme ZIP from the `theme` multipart field into a private Workspace theme.
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
     * HR: Administratoru preuzima ZIP privatne teme područja.
     * EN: Downloads a Workspace private-theme ZIP for an administrator.
     */
    public function exportWorkspaceTheme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->downloadExport(
            $request,
            fn(array $user): \AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceExport =>
                $this->workspaces->exportWorkspaceTheme(
                    $this->routeString($request, 'workspaceSlug'),
                    $user,
                ),
            'workspace_theme_export_failed',
            __('Paket teme područja nije moguće stvoriti.'),
        );
    }

    /**
     * HR: Sprema sliku iz multipart polja `asset` u privatnu biblioteku teme.
     * EN: Stores an image from the `asset` multipart field in the private theme library.
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
     * HR: Briše nekorištenu datoteku privatne teme područja.
     * EN: Deletes an unused Workspace private-theme file.
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
     * HR: Vraća administratorsku javnu i prijavljenu politiku naslovnice.
     * EN: Returns administrator-only public and authenticated homepage policy.
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
     * HR: Sprema administratorsku javnu i prijavljenu politiku naslovnice.
     * EN: Stores administrator-only public and authenticated homepage policy.
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
     * HR: Vraća osobnu naslovnicu vlasnika API ključa kada je odabir omogućen.
     * EN: Returns the API-key owner's personal homepage when selection is enabled.
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
     * HR: Sprema osobnu naslovnicu vlasnika API ključa uz novu ACL provjeru.
     * EN: Stores the API-key owner's personal homepage after a fresh ACL check.
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
     * HR: Kreira novo područje uz aplikacijsko pravilo o dozvoli kreiranja.
     * EN: Creates a new Workspace subject to the application's creation policy.
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
     * HR: Djelomično mijenja područje uz efektivno can_manage pravo.
     * EN: Partially updates a Workspace with the effective can_manage permission.
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
     * HR: Soft-briše područje uz efektivno can_manage pravo.
     * EN: Soft-deletes a Workspace with the effective can_manage permission.
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
     * HR: Vraća administratorski popis soft-obrisanih područja.
     * EN: Returns the administrator-only list of soft-deleted workspaces.
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
     * HR: Vraća soft-obrisano područje pod slobodnim slugom.
     * EN: Restores a soft-deleted Workspace under an available slug.
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
     * HR: Vraća izravni ACL područja korisniku koji njime smije upravljati.
     * EN: Returns the direct Workspace ACL to a user who may manage it.
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
     * HR: Zamjenjuje potpuni ACL područja.
     * EN: Replaces the complete Workspace ACL.
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
     * HR: Pretražuje korisnike ili grupe za Workspace ACL picker.
     * EN: Searches users or groups for the Workspace ACL picker.
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
     * HR: Dodaje interni ili vanjski link u stablo.
     * EN: Adds an internal or external link to the tree.
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
     * HR: Mijenja strukturne podatke jednog čvora stabla.
     * EN: Updates structural data for one tree node.
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
     * HR: Briše link-čvor; dokumenti ostaju odgovornost Editor API-ja.
     * EN: Deletes a link node; documents remain the Editor API's responsibility.
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
     * HR: Sprema potpuni poredak i hijerarhiju stabla.
     * EN: Stores the complete tree order and hierarchy.
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
     * HR: Vraća izravna ACL ograničenja jednog čvora.
     * EN: Returns direct ACL restrictions for one node.
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
     * HR: Zamjenjuje izravna ACL ograničenja jednog čvora.
     * EN: Replaces direct ACL restrictions for one node.
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
     * HR: Provjerava scope, poziva operaciju i ujednačeno mapira očekivane greške.
     * EN: Checks the scope, invokes the operation, and consistently maps expected failures.
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
     * HR: Vraća autentificirani identitet koji je postavio API middleware.
     * EN: Returns the authenticated identity attached by the API middleware.
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
     * HR: Preuzima binarni Workspace ZIP uz jedinstvenu provjeru scopea i grešaka.
     * EN: Downloads a binary Workspace ZIP with consistent scope and error handling.
     *
     * @param callable(array<string,mixed>):\AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceExport $operation
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
     * HR: Dekodira i validira JSON objekt iz tijela zahtjeva.
     * EN: Decodes and validates a JSON object from the request body.
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
     * HR: Normalizira standardno multipart tijelo u polje sa string ključevima.
     * EN: Normalizes a standard multipart body into a string-keyed array.
     *
     * @return array<string,mixed>
     */
    private function formBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $this->stringKeyArray($body) : [];
    }

    /**
     * HR: Vraća obaveznu datoteku iz imenovanog multipart polja.
     * EN: Returns the required file from a named multipart field.
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
     * HR: Čita pozitivni numerički ID iz route atributa.
     * EN: Reads a positive numeric ID from a route attribute.
     */
    private function routeId(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /**
     * HR: Čita tekstualnu vrijednost route atributa.
     * EN: Reads a string value from a route attribute.
     */
    private function routeString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Vraća sigurni jezik stabla iz queryja ili zadane aplikacijske lokalizacije.
     * EN: Returns a safe tree language from the query or the application default locale.
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
     * HR: Zadržava samo string ključeve ulaznog polja.
     * EN: Keeps only string keys from an input array.
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
     * HR: Normalizira opcionalnu JSON listu ID-eva; prazna lista znači cijelo područje.
     * EN: Normalizes an optional JSON ID list; an empty list means the complete Workspace.
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
     * HR: Gradi stabilnu validacijsku grešku za neispravnu strukturu zahtjeva.
     * EN: Builds a stable validation failure for an invalid request structure.
     */
    private function validationError(string $message): WorkspaceApiException
    {
        return new WorkspaceApiException('workspace_validation_failed', $message, 422);
    }
}
