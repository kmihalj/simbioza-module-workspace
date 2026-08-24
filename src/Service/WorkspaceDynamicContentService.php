<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use DateTimeImmutable;
use DateTimeZone;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function base64_decode;
use function base64_encode;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function preg_replace_callback;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;
use function usort;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * HR: Materijalizira nativne, ACL-sigurne blokove područja koji su spremljeni
 *     kao mali deklarativni elementi u HTML dokumentu.
 * EN: Materializes native ACL-safe Workspace blocks stored as small declarative
 *     elements in an HTML document.
 */
final readonly class WorkspaceDynamicContentService
{
    private const BLOCK_PATTERN =
    '~<section\b[^>]*data-editor-html-workspace-block=(?:"1"|\'1\')[^>]*>.*?</section>~isu';

    /**
     * HR: Prima samo generičke servise; ACL servis se razrješava kasno kako se
     *     ne bi stvorila kružna ovisnost s Editor mostom.
     * EN: Receives only generic services; ACL is resolved lazily to avoid a
     *     circular dependency with the Editor bridge.
     */
    public function __construct(
        private ContainerInterface $container,
        private WorkspaceRepository $repository,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceEditorBridge $editor,
        private WorkspaceConfig $config,
        private UrlGenerator $urls,
    ) {
    }

    /**
     * HR: Gradi kanonski HTML element jednog nativnog bloka.
     * EN: Builds the canonical HTML element for one native block.
     *
     * @param array<string,mixed> $configuration
     */
    public function placeholder(string $kind, array $configuration = []): string
    {
        $kind = $this->kind($kind);
        if ($kind === '') {
            return '';
        }

        $configuration = $this->configuration($kind, $configuration);
        $encoded = $this->encode($configuration);

        return '<section class="editor-html-workspace-block"'
        . ' data-editor-html-workspace-block="1"'
        . ' data-workspace-block-kind="' . $this->escape($kind) . '"'
        . ' data-workspace-block-config="' . $this->escape($encoded) . '">'
        . '<p>' . $this->escape($this->blockLabel($kind)) . '</p>'
        . '</section>';
    }

    /**
     * HR: Zamjenjuje sve blokove stvarnim prikazom za područje dokumenta.
     * EN: Replaces every block with its actual document Workspace rendering.
     */
    public function render(
        string $html,
        string $documentKey,
        string $language,
        bool $interactive = true,
    ): string {
        if ($html === '' || !str_contains($html, 'data-editor-html-workspace-block')) {
            return $html;
        }

        $node = $this->repository->findNodeByDocumentKey(trim($documentKey));
        if (!is_array($node)) {
            return $html;
        }

        $workspace = $this->repository->findWorkspaceById(WorkspaceValue::int($node['workspace_id'] ?? 0));
        if (!is_array($workspace)) {
            return $html;
        }

        return (string)preg_replace_callback(
            self::BLOCK_PATTERN,
            function (array $match) use ($workspace, $node, $language, $interactive): string {
                $element = $match[0];
                $kind = $this->attribute($element, 'data-workspace-block-kind');
                $configuration = $this->decode(
                    $this->attribute($element, 'data-workspace-block-config'),
                );

                return match ($this->kind($kind)) {
                    'page-report' => $this->pageReport($workspace, $language, $configuration),
                    'attachment-gallery' => $this->attachmentGallery($node, $configuration),
                    'workspace-search' => $this->workspaceSearch($workspace, $language, $configuration, $interactive),
                    'recent-changes' => $this->recentChanges($workspace, $language, $configuration),
                    default => '',
                };
            },
            $html,
        );
    }

    /**
     * HR: Prikazuje ACL-filtrirano izvješće stranica i strukturiranih svojstava.
     * EN: Renders an ACL-filtered report of pages and structured properties.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $configuration
     */
    private function pageReport(array $workspace, string $language, array $configuration): string
    {
        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $label = $this->text($configuration['label'] ?? '');
        $nodes = $label !== ''
        ? $this->repository->nodesWithLabel($workspaceId, $label)
        : $this->repository->nodesForWorkspace($workspaceId);
        $nodes = $this->readableDocumentNodes($workspace, $nodes, $language);

        $nodeIds = [];
        foreach ($nodes as $candidate) {
            $nodeIds[] = WorkspaceValue::int($candidate['id'] ?? 0);
        }

        $properties = $this->repository->nodePropertiesForNodes($nodeIds);
        $propertyMaps = [];
        foreach ($properties as $nodeId => $nodeProperties) {
            $propertyMaps[(int)$nodeId] = $this->propertyMap($nodeProperties);
        }

        $columns = $this->columns($configuration['columns'] ?? []);
        if ($columns !== []) {
            // HR: Page Properties Report prikazuje samo stranice koje stvarno
            //     imaju strukturirana svojstva, iako oznaku mogu dijeliti i
            //     druge stranice područja.
            // EN: A Page Properties Report includes only pages that actually
            //     have structured properties even when other workspace pages
            //     share the same label.
            $nodes = array_values(array_filter(
                $nodes,
                static fn(array $node): bool => ($propertyMaps[WorkspaceValue::int($node['id'] ?? 0)] ?? []) !== [],
            ));
        }

        $sort = $this->text($configuration['sort'] ?? 'title');
        $direction = $this->text($configuration['direction'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($nodes, function (array $left, array $right) use ($sort, $direction, $propertyMaps): int {
            if ($sort === 'updated') {
                return $direction * strcmp(
                    $this->text($left['updated_at'] ?? ''),
                    $this->text($right['updated_at'] ?? ''),
                );
            }

            if (str_starts_with($sort, 'property:')) {
                $key = $this->propertyKey(substr($sort, 9));
                $leftProperty = $propertyMaps[WorkspaceValue::int($left['id'] ?? 0)][$key]
                    ?? ['value' => '', 'type' => 'text'];
                $rightProperty = $propertyMaps[WorkspaceValue::int($right['id'] ?? 0)][$key]
                    ?? ['value' => '', 'type' => 'text'];

                return $direction * strnatcasecmp(
                    $this->propertySortValue($leftProperty),
                    $this->propertySortValue($rightProperty),
                );
            }

            return $direction * strnatcasecmp(
                $this->text($left['title'] ?? ''),
                $this->text($right['title'] ?? ''),
            );
        });
        $nodes = array_slice($nodes, 0, $this->limit($configuration['limit'] ?? 100, 100));
        if ($columns === []) {
            $columns = $this->discoveredColumns($properties);
        }

        $heading = $this->text($configuration['title'] ?? '');
        $output = '<section class="workspace-dynamic-block workspace-page-report">';
        if ($heading !== '') {
            $output .= '<h2 class="h5">' . $this->escape($heading) . '</h2>';
        }

        if ($nodes === []) {
            return $output . '<p class="text-body-secondary mb-0">'
            . $this->escape(__('Nema stranica koje odgovaraju odabranim uvjetima.'))
            . '</p></section>';
        }

        $firstColumn = $this->text($configuration['first_column'] ?? '') ?: __('Stranica');
        $output .= '<div class="table-responsive"><table class="table table-bordered table-striped table-hover">'
        . '<thead class="table-light"><tr><th scope="col">' . $this->escape($firstColumn) . '</th>';
        foreach ($columns as $column) {
            $output .= '<th scope="col">' . $this->escape($column['label']) . '</th>';
        }

        $output .= '</tr></thead><tbody>';
        foreach ($nodes as $candidate) {
            $nodeId = WorkspaceValue::int($candidate['id'] ?? 0);
            $output .= '<tr><th scope="row"><a href="'
            . $this->escape($this->nodePath($workspace, $candidate, $language))
            . '">' . $this->escape(WorkspaceValue::string($candidate['title'] ?? '')) . '</a></th>';
            $values = $propertyMaps[$nodeId] ?? [];
            foreach ($columns as $column) {
                $value = $values[$column['key']] ?? ['value' => '', 'type' => 'text'];
                $output .= '<td>' . $this->propertyValue($value) . '</td>';
            }

            $output .= '</tr>';
        }

        return $output . '</tbody></table></div></section>';
    }

    /**
     * HR: Prikazuje galeriju javno dostupnih privitaka trenutačne stranice.
     * EN: Renders a gallery of publicly available attachments for the current page.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $configuration
     */
    private function attachmentGallery(array $node, array $configuration): string
    {
        $assets = $this->editor->publicAssets(WorkspaceValue::string($node['document_key'] ?? ''));
        $sort = $this->text($configuration['sort'] ?? 'date');
        usort($assets, static function (array $left, array $right) use ($sort): int {
            if ($sort === 'name') {
                return strnatcasecmp(
                    WorkspaceValue::string($left['name'] ?? ''),
                    WorkspaceValue::string($right['name'] ?? ''),
                );
            }

            return strcmp(
                WorkspaceValue::string($right['created_at'] ?? ''),
                WorkspaceValue::string($left['created_at'] ?? ''),
            );
        });
        $assets = array_slice($assets, 0, $this->limit($configuration['limit'] ?? 100, 100));
        $heading = $this->text($configuration['title'] ?? '');
        $output = '<section class="workspace-dynamic-block workspace-attachment-gallery">';
        if ($heading !== '') {
            $output .= '<h2 class="h5">' . $this->escape($heading) . '</h2>';
        }

        if ($assets === []) {
            return $output . '<p class="text-body-secondary mb-0">'
            . $this->escape(__('Nema privitaka za prikaz.')) . '</p></section>';
        }

        $output .= '<div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">';
        foreach ($assets as $asset) {
            $name = $this->text($asset['name'] ?? '');
            $url = $this->text($asset['url'] ?? '');
            $kind = $this->text($asset['kind'] ?? 'file');
            $output .= '<div class="col"><figure class="card h-100 mb-0 overflow-hidden">';
            if ($kind === 'image') {
                $output .= '<a href="' . $this->escape($url)
                . '"><img class="card-img-top img-fluid" loading="lazy" src="'
                . $this->escape($url) . '" alt="'
                . $this->escape($this->text($asset['alt_text'] ?? $name)) . '"></a>';
            } else {
                $output .= '<div class="card-body"><a class="text-decoration-none" href="'
                . $this->escape($url) . '">' . $this->escape($name) . '</a></div>';
            }

            if ($kind === 'image') {
                $output .= '<figcaption class="card-body py-2"><a class="text-decoration-none" href="'
                . $this->escape($url) . '">' . $this->escape($name) . '</a></figcaption>';
            }

            $output .= '</figure></div>';
        }

        return $output . '</div></section>';
    }

    /**
     * HR: Gradi pretragu ograničenu na trenutačno područje.
     * EN: Builds a search form scoped to the current workspace.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $configuration
     */
    private function workspaceSearch(
        array $workspace,
        string $language,
        array $configuration,
        bool $interactive,
    ): string {
        $heading = $this->text($configuration['title'] ?? '');
        $output = '<section class="workspace-dynamic-block workspace-embedded-search">';
        if ($heading !== '') {
            $output .= '<h2 class="h5">' . $this->escape($heading) . '</h2>';
        }

        if (!$interactive) {
            return $output . '<p class="text-body-secondary mb-0">'
            . $this->escape(__('Pretraga je dostupna u aplikaciji.')) . '</p></section>';
        }

        $action = $this->urls->namedRouteExists('workspace-search.index')
        ? $this->urls->getPathFor('workspace-search.index')
        : rtrim($this->urls->getBasePath(), '/') . '/search';
        $suggest = $this->urls->namedRouteExists('workspace-search.suggest')
        ? $this->urls->getPathFor('workspace-search.suggest')
        : '';
        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');

        return $output . '<form action="' . $this->escape($action) . '" method="get" role="search"'
        . ' data-workspace-embedded-search="1" data-suggest-url="' . $this->escape($suggest) . '"'
        . ' data-workspace-slug="' . $this->escape($workspaceSlug) . '"'
        . ' data-search-language="' . $this->escape($language) . '">'
        . '<input type="hidden" name="workspace" value="'
        . $this->escape($workspaceSlug) . '">'
        . '<input type="hidden" name="lang" value="' . $this->escape($language) . '">'
        . '<div class="input-group"><input class="form-control" type="search" name="q" required minlength="2"'
        . ' autocomplete="off" data-workspace-embedded-search-input="1"'
        . ' placeholder="' . $this->escape(__('Pretraži područje')) . '">'
        . '<button class="btn btn-primary" type="submit">' . $this->escape(__('Pretraži'))
        . '</button></div><div class="list-group mt-2" hidden role="listbox"'
        . ' data-workspace-embedded-search-results="1"></div></form></section>';
    }

    /**
     * HR: Prikazuje posljednje korisniku dostupne promjene područja.
     * EN: Renders the latest workspace changes visible to the user.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $configuration
     */
    private function recentChanges(array $workspace, string $language, array $configuration): string
    {
        $nodes = $this->readableDocumentNodes(
            $workspace,
            $this->repository->nodesForWorkspace(WorkspaceValue::int($workspace['id'] ?? 0)),
            $language,
        );
        usort($nodes, static fn(array $left, array $right): int => strcmp(
            WorkspaceValue::string($right['updated_at'] ?? ''),
            WorkspaceValue::string($left['updated_at'] ?? ''),
        ));
        $nodes = array_slice($nodes, 0, $this->limit($configuration['limit'] ?? 10, 10));
        $heading = $this->text($configuration['title'] ?? '');
        $output = '<section class="workspace-dynamic-block workspace-recent-changes">';
        if ($heading !== '') {
            $output .= '<h2 class="h5">' . $this->escape($heading) . '</h2>';
        }

        if ($nodes === []) {
            return $output . '<p class="text-body-secondary mb-0">'
            . $this->escape(__('Nema nedavnih promjena.')) . '</p></section>';
        }

        $userIds = [];
        foreach ($nodes as $candidate) {
            $userId = WorkspaceValue::int($candidate['updated_by_user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        $authors = $this->repository->userDisplayNames(array_values(array_unique($userIds)));
        $output .= '<ul class="list-group list-group-flush">';
        foreach ($nodes as $candidate) {
            $output .= '<li class="list-group-item px-0"><a href="'
            . $this->escape($this->nodePath($workspace, $candidate, $language)) . '">'
            . $this->escape(WorkspaceValue::string($candidate['title'] ?? '')) . '</a>';
            $updatedAt = $this->text($candidate['updated_at'] ?? '');
            if ($updatedAt !== '') {
                $userId = WorkspaceValue::int($candidate['updated_by_user_id'] ?? 0);
                $author = $this->text($authors[$userId] ?? '');
                $meta = $this->formatDateTime($updatedAt, $language);
                if ($author !== '') {
                    $meta .= ($meta !== '' ? ' · ' : '') . $author;
                }

                if ($meta !== '') {
                    $output .= '<small class="d-block text-body-secondary">' . $this->escape($meta) . '</small>';
                }
            }

            $output .= '</li>';
        }

        return $output . '</ul></section>';
    }

    /**
     * HR: Filtrira dokumente prema objavi, jeziku i trenutačnim ACL ovlastima.
     * EN: Filters documents by publication, language, and current ACL permissions.
     *
     * @param array<string,mixed> $workspace
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function readableDocumentNodes(array $workspace, array $nodes, string $language): array
    {
        $access = $this->container->get(WorkspaceAccessService::class);
        if (!$access instanceof WorkspaceAccessService) {
            return [];
        }

        $user = $access->currentUser();
        $result = [];
        $language = trim($language) !== '' ? strtolower(trim($language)) : $this->config->siteDefaultLanguage();
        foreach ($nodes as $candidate) {
            if (WorkspaceValue::string($candidate['node_type'] ?? '') !== 'document') {
                continue;
            }

            if (!(bool)($access->nodePermissions($workspace, $candidate, $user)['can_view'] ?? false)) {
                continue;
            }

            $nodeId = WorkspaceValue::int($candidate['id'] ?? 0);
            if (
                $this->workflow->publicationVersionForNode($nodeId, $language) <= 0
                && $this->workflow->publicationVersionForNode($nodeId, $this->config->siteDefaultLanguage()) <= 0
            ) {
                continue;
            }

            $result[] = $candidate;
        }

        return $result;
    }

    /**
     * HR: Otkriva stupce izvještaja iz pohranjenih svojstava stranica.
     * EN: Discovers report columns from stored page properties.
     *
     * @param array<int,list<array{key:string,label:string,type:string,value:string,sort_order:int}>> $properties
     * @return list<array{key:string,label:string}>
     */
    private function discoveredColumns(array $properties): array
    {
        $columns = [];
        foreach ($properties as $pageProperties) {
            foreach ($pageProperties as $property) {
                $key = $this->text($property['key'] ?? '');
                if ($key !== '' && !isset($columns[$key])) {
                    $columns[$key] = ['key' => $key, 'label' => $this->text($property['label'] ?? $key)];
                }
            }
        }

        return array_slice(array_values($columns), 0, 8);
    }

    /**
     * HR: Normalizira korisnički odabrane stupce izvještaja.
     * EN: Normalizes user-selected report columns.
     *
     * @return list<array{key:string,label:string}>
     */
    private function columns(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $columns = [];
        foreach ($value as $column) {
            $key = '';
            $label = '';
            if (is_array($column)) {
                $key = $this->propertyKey($column['key'] ?? $column['label'] ?? '');
                $label = $this->text($column['label'] ?? $key);
            } elseif (is_scalar($column)) {
                $label = $this->text($column);
                $key = $this->propertyKey($label);
            }

            if ($key !== '') {
                $columns[$key] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
            }
        }

        return array_slice(array_values($columns), 0, 8);
    }

    /**
     * HR: Pretvara popis svojstava u mapu prikladnu za prikaz izvještaja.
     * EN: Converts a property list into a report-friendly map.
     *
     * @param list<array{key:string,label:string,type:string,value:string,sort_order:int}> $properties
     * @return array<string,array{value:string,type:string}>
     */
    private function propertyMap(array $properties): array
    {
        $result = [];
        foreach ($properties as $property) {
            $key = $this->propertyKey($property['key'] ?? '');
            if ($key !== '') {
                $result[$key] = [
                    'value' => $this->text($property['value'] ?? ''),
                    'type' => $this->text($property['type'] ?? 'text'),
                ];
            }
        }

        return $result;
    }

    /**
     * HR: Sigurno prikazuje jednu vrijednost svojstva prema njezinoj vrsti.
     * EN: Safely renders one property value according to its type.
     *
     * @param array{value:string,type:string} $property
     */
    private function propertyValue(array $property): string
    {
        $value = $property['value'];
        if ($value === '') {
            return '<span aria-hidden="true">—</span>';
        }

        if ($property['type'] === 'status') {
            return '<span class="badge ' . $this->statusBadgeClass($value) . '">'
            . $this->escape($value) . '</span>';
        }

        if ($property['type'] === 'link' && preg_match('~^https?://~i', $value) === 1) {
            return '<a href="' . $this->escape($value) . '">' . $this->escape($value) . '</a>';
        }

        return $this->escape($value);
    }

    /**
     * HR: Gradi stabilnu vrijednost za sortiranje svojstava i statusa.
     * EN: Builds a stable sorting value for properties and statuses.
     *
     * @param array{value:string,type:string} $property
     */
    private function propertySortValue(array $property): string
    {
        $value = mb_strtolower(trim($property['value']), 'UTF-8');
        if ($property['type'] !== 'status') {
            return $value;
        }

        $priority = match (true) {
            preg_match('/prestan|stop|blocked|otkaz/u', $value) === 1 => '10',
            preg_match('/traje|progress|ongoing|aktiv/u', $value) === 1 => '20',
            preg_match('/redovit|regular|plan/u', $value) === 1 => '30',
            preg_match('/zavr|done|complete|closed/u', $value) === 1 => '40',
            default => '50',
        };

        return $priority . ':' . $value;
    }

    /**
     * HR: Dodjeljuje semantičku, Bootstrap-kompatibilnu boju statusu bez
     *     spremanja prezentacijskog HTML-a u podatke stranice.
     * EN: Assigns a semantic Bootstrap-compatible colour to a status without
     *     storing presentation HTML in page data.
     */
    private function statusBadgeClass(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            preg_match('/prestan|stop|blocked|otkaz/u', $value) === 1 => 'text-bg-danger',
            preg_match('/traje|progress|ongoing|aktiv/u', $value) === 1 => 'text-bg-warning',
            preg_match('/redovit|regular|plan/u', $value) === 1 => 'text-bg-primary',
            preg_match('/zavr|done|complete|closed/u', $value) === 1 => 'text-bg-success',
            default => 'text-bg-secondary',
        };
    }

    /**
     * HR: Gradi lokalnu putanju do stranice područja uz odabrani jezik.
     * EN: Builds a local workspace-page path for the selected language.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $node
     */
    private function nodePath(array $workspace, array $node, string $language): string
    {
        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
        if ($this->urls->namedRouteExists('workspace.node.show')) {
            return $this->urls->getPathFor(
                'workspace.node.show',
                ['workspaceSlug' => $workspaceSlug, 'nodeSlug' => $nodeSlug],
                ['lang' => $language],
            );
        }

        return rtrim($this->urls->getBasePath(), '/') . '/' . trim($this->config->rootPath(), '/')
        . '/' . rawurlencode($workspaceSlug) . '/' . rawurlencode($nodeSlug)
        . '?lang=' . rawurlencode($language);
    }

    /**
     * HR: Ograničava i normalizira konfiguraciju dinamičkog bloka.
     * EN: Constrains and normalizes dynamic-block configuration.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private function configuration(string $kind, array $configuration): array
    {
        $result = [
            'title' => $this->text($configuration['title'] ?? ''),
            'limit' => $this->limit($configuration['limit'] ?? ($kind === 'recent-changes' ? 10 : 100), 100),
        ];
        if ($kind === 'page-report') {
            $result['label'] = strtolower($this->text($configuration['label'] ?? ''));
            $result['columns'] = $this->columns($configuration['columns'] ?? []);
            $result['first_column'] = $this->text($configuration['first_column'] ?? '');
            $sort = $this->text($configuration['sort'] ?? 'title');
            if (!in_array($sort, ['title', 'updated'], true) && str_starts_with($sort, 'property:')) {
                $property = $this->propertyKey(substr($sort, 9));
                $sort = $property !== '' ? 'property:' . $property : 'title';
            }

            $result['sort'] = in_array($sort, ['title', 'updated'], true) || str_starts_with($sort, 'property:')
            ? $sort : 'title';
            $defaultDirection = $result['sort'] === 'updated' ? 'desc' : 'asc';
            $requestedDirection = $this->text($configuration['direction'] ?? '');
            $result['direction'] = in_array($requestedDirection, ['asc', 'desc'], true)
            ? $requestedDirection
            : $defaultDirection;
        } elseif ($kind === 'attachment-gallery') {
            $result['sort'] = ($configuration['sort'] ?? '') === 'name' ? 'name' : 'date';
        }

        return $result;
    }

    /** HR: Prihvaća samo podržanu vrstu dinamičkog bloka. EN: Accepts only a supported dynamic-block kind. */
    private function kind(mixed $value): string
    {
        $kind = strtolower($this->text($value));
        return in_array($kind, ['page-report', 'attachment-gallery', 'workspace-search', 'recent-changes'], true)
        ? $kind : '';
    }

    /** HR: Vraća lokalizirani naziv dinamičkog bloka. EN: Returns a localized dynamic-block label. */
    private function blockLabel(string $kind): string
    {
        return match ($kind) {
            'page-report' => __('Tablica stranica i svojstava'),
            'attachment-gallery' => __('Galerija privitaka'),
            'workspace-search' => __('Pretraga područja'),
            'recent-changes' => __('Nedavne promjene područja'),
            default => __('Dinamički sadržaj područja'),
        };
    }

    /**
     * HR: Kodira konfiguraciju bloka za sigurno spremanje u HTML atribut.
     * EN: Encodes block configuration for safe storage in an HTML attribute.
     *
     * @param array<string,mixed> $configuration
     */
    private function encode(array $configuration): string
    {
        $json = json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode(is_string($json) ? $json : '{}')), '=');
    }

    /**
     * HR: Dekodira i provjerava konfiguraciju spremljenu u HTML atributu.
     * EN: Decodes and validates configuration stored in an HTML attribute.
     *
     * @return array<string,mixed>
     */
    private function decode(string $encoded): array
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]{1,8192}$/', $encoded) !== 1) {
            return [];
        }

        $base64 = str_replace(['-', '_'], ['+', '/'], $encoded);
        $remainder = strlen($base64) % 4;
        if ($remainder > 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($base64, true);
        if (!is_string($decoded)) {
            return [];
        }

        $value = json_decode($decoded, true);
        if (!is_array($value)) {
            return [];
        }

        $configuration = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $configuration[$key] = $item;
            }
        }

        return $configuration;
    }

    /** HR: Sigurno čita imenovani atribut iz kanonskog elementa. EN: Safely reads a named attribute from a canonical element. */
    private function attribute(string $html, string $name): string
    {
        $quotedName = preg_quote($name, '~');
        if (preg_match('~\b' . $quotedName . '=(?:"([^"]*)"|\'([^\']*)\')~iu', $html, $match) !== 1) {
            return '';
        }

        return html_entity_decode(
            $match[1] !== '' ? $match[1] : ($match[2] ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    /** HR: Pretvara naziv svojstva u stabilan ključ. EN: Converts a property label into a stable key. */
    private function propertyKey(mixed $value): string
    {
        $value = mb_strtolower($this->text($value), 'UTF-8');
        $value = (string)preg_replace('/[^\pL\pN._-]+/u', '-', $value);
        return mb_substr(trim($value, '-.'), 0, 128, 'UTF-8');
    }

    /** HR: Lokalizira vrijeme promjene prema jeziku prikaza. EN: Localizes a change timestamp for the display language. */
    private function formatDateTime(string $value, string $language): string
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $date = $date->setTimezone(new DateTimeZone($this->config->timezone()));
        } catch (Throwable) {
            return $value;
        }

        return str_starts_with(strtolower($language), 'hr')
        ? $date->format('j. n. Y. H:i')
        : $date->format('Y-m-d H:i');
    }

    /** HR: Ograničava traženi broj rezultata na siguran raspon. EN: Constrains a requested result count to a safe range. */
    private function limit(mixed $value, int $fallback): int
    {
        $limit = is_scalar($value) ? (int)$value : $fallback;
        return min(200, max(1, $limit));
    }

    /** HR: Normalizira skalarnu vrijednost u tekst. EN: Normalizes a scalar value into text. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Kodira tekst za siguran HTML prikaz. EN: Encodes text for safe HTML rendering. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
