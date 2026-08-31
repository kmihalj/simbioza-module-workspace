<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Controller;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExportEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExportService;
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

use function array_keys;
use function array_values;
use function is_array;
use function is_numeric;
use function is_scalar;
use function rawurlencode;
use function str_replace;
use function strlen;
use function trim;

/**
 * HR: Upravlja ACL-zaštićenim HTML izvozom cijelog područja ili izabranih stranica.
 * EN: Handles ACL-protected HTML export of a complete Workspace or selected pages.
 */
final readonly class WorkspaceExportController
{
    /**
     * HR: Prima servise za autorizaciju, pripremu forme, stvaranje ZIP-a i lokalizirane poruke.
     * EN: Receives services for authorization, form preparation, ZIP creation, and localized messages.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private WorkspaceModuleViewRenderer $viewRenderer,
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceExportService $exporter,
        private WorkspaceExportEditorBridge $editor,
        private UrlGenerator $urlGenerator,
        private AlertHandler $alertHandler,
        private WorkspaceThemeService $themes,
    ) {
    }

    /**
     * HR: Prikazuje izbor cijelog područja ili pojedinih objavljenih stranica.
     * EN: Displays the choice between the complete Workspace and individual published pages.
     */
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->repository->tablesReady()) {
            return $this->responseFactory->text(__('Workspace migracija još nije pokrenuta.'), 503);
        }

        $workspace = $this->workspaceFromInput(WorkspaceValue::stringKeyArray($request->getQueryParams()));
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->mayExport($workspace)) {
            return $this->accessDenied();
        }

        $this->themes->activate($workspace);

        return $this->renderForm($workspace);
    }

    /**
     * HR: Ponovno provjerava manage pravo, filtrira odabrane ID-eve i šalje ZIP bez spremanja na disk.
     * EN: Rechecks manage permission, filters selected IDs, and streams the ZIP without storing it on disk.
     */
    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $body = WorkspaceValue::stringKeyArray($request->getParsedBody());
        $workspace = $this->workspaceFromInput($body);
        if (!is_array($workspace)) {
            return $this->responseFactory->text(__('Područje nije pronađeno.'), 404);
        }

        if (!$this->mayExport($workspace)) {
            return $this->accessDenied();
        }

        $this->themes->activate($workspace);

        $scope = WorkspaceValue::string($body['scope'] ?? 'all');
        $selectedNodeIds = $scope === 'selected'
        ? $this->selectedNodeIds($body['node_ids'] ?? [])
        : [];
        if ($scope === 'selected' && $selectedNodeIds === []) {
            $this->alertHandler->add(new Alert(
                __('Odaberite najmanje jednu stranicu za izvoz.'),
                AlertLevelEnum::Warning,
            ));

            return $this->responseFactory->redirect($this->formPath($workspace));
        }

        try {
            /*
             * HR: Servis ponovno presijeca odabrane ID-eve s ACL-vidljivim i objavljenim
             *     stablom. Zato ručno izmijenjen POST ne može proširiti sadržaj ZIP-a.
             * EN: The service intersects selected IDs again with the ACL-visible,
             *     published tree, so a tampered POST cannot broaden ZIP contents.
             */
            $export = $this->exporter->export($workspace, $selectedNodeIds);
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert(
                $throwable->getMessage(),
                AlertLevelEnum::Danger,
            ));

            return $this->responseFactory->redirect($this->formPath($workspace));
        }

        return $this->responseFactory->createResponse(200)
            ->withBody($this->responseFactory->streamFactory()->createStream($export->content))
            ->withHeader('Content-Type', $export->mimeType)
            ->withHeader('Content-Length', (string)strlen($export->content))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . str_replace('"', '', $export->fileName) . '"',
            );
    }

    /**
     * HR: Priprema prikazni model forme bez ponavljanja ACL i tree logike u viewu.
     * EN: Prepares the form view model without duplicating ACL and tree logic in the view.
     *
     * @param array<string, mixed> $workspace
     */
    private function renderForm(array $workspace): ResponseInterface
    {
        $tree = $this->exporter->exportableTree($workspace, array_keys($this->editor->languageLabels()));

        return $this->viewRenderer->render('workspace/export', [
            'title' => __('Izvezi područje u HTML'),
            'workspace' => $workspace,
            'pageOptions' => $this->pageOptions($tree),
            'languageLabels' => $this->editor->languageLabels(),
            'downloadPath' => $this->pathFor('workspace.export.download', '/workspaces/export'),
            'managePath' => $this->pathFor('workspace.manage', '/workspaces/manage')
                . '?workspace=' . rawurlencode(WorkspaceValue::string($workspace['slug'] ?? '')),
            'assetsCssPath' => $this->pathFor('workspace.assets.css', '/workspaces/assets.css'),
        ]);
    }

    /**
     * HR: Izvoz smije pokrenuti samo administrator ili korisnik s efektivnim manage pravom.
     * EN: Only an administrator or a user with effective manage permission may start an export.
     *
     * @param array<string, mixed> $workspace
     */
    private function mayExport(array $workspace): bool
    {
        if ($this->access->isAdministrator()) {
            return true;
        }

        return $this->access->workspacePermissions($workspace)['can_manage'];
    }

    /**
     * HR: Učitava područje iz numeričkog ID-a ili sluga, jednako za GET i POST.
     * EN: Loads a Workspace from a numeric ID or slug, consistently for GET and POST.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function workspaceFromInput(array $input): ?array
    {
        $workspaceId = WorkspaceValue::int($input['workspace_id'] ?? $input['id'] ?? 0);
        if ($workspaceId > 0) {
            return $this->repository->findWorkspaceById($workspaceId);
        }

        $slug = trim(WorkspaceValue::string($input['workspace'] ?? $input['slug'] ?? ''));

        return $slug !== '' ? $this->repository->findWorkspaceBySlug($slug) : null;
    }

    /**
     * HR: Pretvara ugniježđeno stablo u listu checkboxa uz očuvanu dubinu prikaza.
     * EN: Converts the nested tree into checkbox options while preserving display depth.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array{id:int,title:string,depth:int,is_homepage:bool}>
     */
    private function pageOptions(array $tree, int $depth = 0): array
    {
        $options = [];
        foreach ($tree as $node) {
            if (WorkspaceValue::string($node['node_type'] ?? '') === 'document') {
                $options[] = [
                    'id' => WorkspaceValue::int($node['id'] ?? 0),
                    'title' => WorkspaceValue::string($node['title'] ?? ''),
                    'depth' => $depth,
                    'is_homepage' => (bool)($node['is_homepage'] ?? false),
                ];
            }

            $options = [
                ...$options,
                ...$this->pageOptions(WorkspaceValue::rows($node['children'] ?? null), $depth + 1),
            ];
        }

        return $options;
    }

    /**
     * HR: Prihvaća samo pozitivne numeričke ID-eve iz višestrukog checkbox polja.
     * EN: Accepts only positive numeric IDs from the multi-checkbox field.
     *
     * @return list<int>
     */
    private function selectedNodeIds(mixed $value): array
    {
        $selected = [];
        foreach (is_array($value) ? $value : [] as $nodeId) {
            if (!is_scalar($nodeId)) {
                continue;
            }

            if (!is_numeric((string)$nodeId)) {
                continue;
            }

            $nodeId = (int)$nodeId;
            if ($nodeId > 0) {
                $selected[$nodeId] = $nodeId;
            }
        }

        return array_values($selected);
    }

    /**
     * HR: Gradi povratni URL forme s trenutačnim područjem.
     * EN: Builds the return URL of the form with the current Workspace.
     *
     * @param array<string, mixed> $workspace
     */
    private function formPath(array $workspace): string
    {
        return $this->pathFor('workspace.export', '/workspaces/export')
        . '?workspace=' . rawurlencode(WorkspaceValue::string($workspace['slug'] ?? ''));
    }

    /**
     * HR: Vraća lokalizirani 403 prikaz bez otkrivanja stabla ili naziva stranica.
     * EN: Returns a localized 403 view without exposing the tree or page names.
     */
    private function accessDenied(): ResponseInterface
    {
        return $this->viewRenderer->render('workspace/access-denied', [
            'title' => __('Nedozvoljen pristup'),
            'message' => __('Samo administratori i upravitelji područja mogu izvesti područje.'),
            'indexPath' => $this->pathFor('workspace.index', '/workspaces'),
        ], true, 403);
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
