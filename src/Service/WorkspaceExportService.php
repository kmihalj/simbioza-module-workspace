<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Localization\TranslatorInterface;
use JsonException;
use RuntimeException;
use ZipArchive;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function date;
use function file_get_contents;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function max;
use function number_format;
use function preg_replace;
use function preg_split;
use function rawurlencode;
use function strlen;
use function strtolower;
use function strtoupper;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * HR: Gradi samostalnu, ACL-filtriranu offline aplikaciju jednog područja.
 *     Svaka HTML datoteka radi preko `file://`, a dinamički sadržaj pretvoren je u snimku.
 * EN: Builds a standalone, ACL-filtered offline application for one Workspace.
 *     Every HTML file works through `file://`, with dynamic content converted to a snapshot.
 */
final readonly class WorkspaceExportService
{
    /**
     * HR: Prima vlasnike stabla, workflowa, Editor snimki i aktivne teme.
     * EN: Receives the owners of the tree, workflow, Editor snapshots, and active theme.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceAccessService $access,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceExportEditorBridge $editor,
        private WorkspaceThemeBridge $theme,
        private WorkspaceConfig $config,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * HR: Vraća izvozive objavljene čvorove koje trenutačni korisnik smije vidjeti.
     * EN: Returns exportable published nodes visible to the current user.
     *
     * @param array<string, mixed> $workspace
     * @param list<string> $languages
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public function exportableTree(array $workspace, array $languages, ?array $user = null): array
    {
        $visibleTree = $this->access->visibleTreeForLanguages($workspace, $user, $languages);
        $nodeIds = [];
        foreach ($this->flatten($visibleTree) as $node) {
            if (WorkspaceValue::string($node['node_type'] ?? '') === 'document') {
                $nodeIds[] = WorkspaceValue::int($node['id'] ?? 0);
            }
        }

        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages($nodeIds);

        return $this->filterPublishedTree($visibleTree, $workflows);
    }

    /**
     * HR: Stvara ZIP cijelog vidljivog područja ili samo izabranih stranica.
     * EN: Creates a ZIP of the complete visible Workspace or selected pages only.
     *
     * @param array<string, mixed> $workspace
     * @param list<int> $selectedNodeIds
     * @param array<string, mixed>|null $user
     */
    public function export(
        array $workspace,
        array $selectedNodeIds = [],
        ?array $user = null,
    ): WorkspaceExport {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('PHP ZIP ekstenzija nije instalirana.'));
        }

        $languages = array_keys($this->editor->languageLabels());
        $defaultLanguage = $this->config->siteDefaultLanguage();
        if (!in_array($defaultLanguage, $languages, true)) {
            $languages[] = $defaultLanguage;
        }

        $tree = $this->exportableTree($workspace, $languages, $user);
        $flat = $this->flatten($tree);
        $availableIds = array_values(array_filter(array_map(
            static fn(array $node): int => WorkspaceValue::int($node['id'] ?? 0),
            $flat,
        )));
        $selectedNodeIds = array_values(array_unique(array_filter(
            $selectedNodeIds,
            static fn(int $nodeId): bool => $nodeId > 0 && in_array($nodeId, $availableIds, true),
        )));
        $includedIds = $selectedNodeIds !== [] ? $selectedNodeIds : $availableIds;
        $tree = $this->filterSelectedTree($tree, $includedIds);
        $flat = $this->flatten($tree);
        if ($flat === []) {
            throw new WorkspaceExportSelectionException(
                __('Nema objavljenih stranica za izvoz.'),
            );
        }

        $documentNodes = array_values(array_filter(
            $flat,
            static fn(array $node): bool => WorkspaceValue::string($node['node_type'] ?? '') === 'document',
        ));
        if ($documentNodes === []) {
            throw new WorkspaceExportSelectionException(
                __('Nema objavljenih stranica za izvoz.'),
            );
        }

        $nodeIds = array_map(
            static fn(array $node): int => WorkspaceValue::int($node['id'] ?? 0),
            $documentNodes,
        );
        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages($nodeIds);
        $fileNames = $this->pageFileNames($documentNodes);
        $homepageId = $this->homepageId($documentNodes);
        $themeBundle = $this->theme->bundle();
        $entries = $this->baseEntries($themeBundle);
        /** @var array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized */
        $localized = [];
        foreach ($languages as $locale) {
            foreach ($documentNodes as $node) {
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                $workflow = $this->workflowForLocale(
                    WorkspaceValue::rows($workflows[$nodeId] ?? null),
                    $locale,
                );
                if (!is_array($workflow)) {
                    continue;
                }

                $sourceLanguage = strtolower(WorkspaceValue::string($workflow['language_code'] ?? ''));
                $versionNumber = WorkspaceValue::int($workflow['published_version_number'] ?? 0);
                $documentKey = WorkspaceValue::string($node['document_key'] ?? '');
                $snapshot = $this->editor->snapshot(
                    $documentKey,
                    $sourceLanguage,
                    $versionNumber,
                    'documents/' . rawurlencode($documentKey)
                    . '/' . rawurlencode($sourceLanguage)
                    . '/v' . $versionNumber,
                    $user,
                );
                if (!is_array($snapshot)) {
                    continue;
                }

                $localized[$locale][$nodeId] = [
                    'snapshot' => $snapshot,
                    'source_language' => $sourceLanguage,
                ];
                foreach (WorkspaceValue::stringKeyArray($snapshot['files']) as $path => $content) {
                    if (is_string($content)) {
                        $entries[$path] = $content;
                    }
                }
            }
        }

        if ($localized === []) {
            throw new WorkspaceExportSelectionException(
                __('Nema objavljenih stranica za izvoz.'),
            );
        }

        /*
         * HR: Svaki stvarni prijevod dobiva samo vlastitu čistu HTML datoteku.
         *     Ne stvaramo kopije fallback sadržaja pod drugim jezicima.
         * EN: Every real translation gets only its own clean HTML file. Fallback
         *     content is never duplicated under a different locale directory.
         */
        $exportedPages = [];
        $standaloneStylesheets = [
            '../assets/css/bootstrap.min.css',
            '../assets/css/calendar.css',
            '../assets/css/tasks.css',
            '../assets/css/editor-html-standalone.css',
        ];
        foreach ($languages as $locale) {
            $snapshots = $localized[$locale] ?? [];
            foreach ($documentNodes as $node) {
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                if (!isset($snapshots[$nodeId])) {
                    continue;
                }

                $file = $fileNames[$nodeId] ?? 'page-' . $nodeId . '.html';
                $entries[$locale . '/' . $file] = $this->editor->standaloneDocument(
                    $snapshots[$nodeId]['snapshot'],
                    $locale,
                    $standaloneStylesheets,
                );
                $exportedPages[] = [
                    'node_id' => $nodeId,
                    'language' => $locale,
                    'source_language' => $snapshots[$nodeId]['source_language'],
                    'file' => $locale . '/' . $file,
                ];
            }
        }

        $initialPageId = $homepageId;
        if ($initialPageId <= 0 || !$this->pageHasSnapshot($localized, $initialPageId)) {
            $initialPageId = WorkspaceValue::int($documentNodes[0]['id'] ?? 0);
        }

        if ($initialPageId <= 0 || !$this->pageHasSnapshot($localized, $initialPageId)) {
            throw new RuntimeException(__('Nema objavljenih stranica za izvoz.'));
        }

        /*
         * HR: `index.html` je jedina offline aplikacijska ljuska. Ona mijenja
         *     sadržaj i jezik bez servera, dok se lokalizirane datoteke iznad
         *     mogu otvoriti izravno kao izvoz jedne stranice.
         * EN: `index.html` is the single offline application shell. It switches
         *     content and locale without a server, while the localized files
         *     above remain directly openable single-page exports.
         */
        $entries['index.html'] = $this->shell(
            $workspace,
            $defaultLanguage,
            $languages,
            $tree,
            $initialPageId,
            $themeBundle,
            $localized,
        );
        $entries['manifest.json'] = $this->manifest($workspace, $defaultLanguage, $exportedPages);

        return new WorkspaceExport(
            $this->zipFileName($workspace),
            $this->zip($entries),
        );
    }

    /**
     * HR: Priprema zajedničke CSS, JavaScript i theme datoteke paketa.
     * EN: Prepares shared CSS, JavaScript, and theme files for the package.
     *
     * @param array<string, mixed> $themeBundle
     * @return array<string, string>
     */
    private function baseEntries(array $themeBundle): array
    {
        $entries = [];
        foreach ($this->editor->stylesheetContents() as $name => $content) {
            if ($name === 'theme.css') {
                continue;
            }

            $entries['assets/css/' . $name] = $content;
        }

        /*
         * HR: HTML uvijek smije referencirati ista stabilna imena, čak i kada
         *     opcionalni Calendar ili Task modul nije instaliran.
         * EN: HTML may always reference the same stable names, even when the
         *     optional Calendar or Task module is not installed.
         */
        $entries['assets/css/bootstrap.min.css'] ??= '';
        $entries['assets/css/calendar.css'] ??= '';
        $entries['assets/css/tasks.css'] ??= '';
        $entries['assets/css/editor-html-standalone.css'] ??= '';
        $workspaceCssPath = $this->config->moduleRoot() . '/resources/assets/workspace.css';
        $workspaceCss = file_get_contents($workspaceCssPath);
        $entries['assets/css/workspace.css'] = is_string($workspaceCss) ? $workspaceCss : '';

        $themeCss = WorkspaceValue::string($themeBundle['css'] ?? '');
        $entries['assets/css/theme.css'] = $themeCss !== '' ? $themeCss : $this->fallbackThemeCss();
        $entries['assets/css/workspace-export.css'] = $this->exportCss();
        $entries['assets/js/workspace-export.js'] = $this->exportJs();
        foreach (WorkspaceValue::stringKeyArray($themeBundle['files'] ?? null) as $path => $content) {
            if (is_string($content)) {
                $entries[$path] = $content;
            }
        }

        return $entries;
    }

    /**
     * HR: Sastavlja zajedničko zaglavlje, Home navigaciju, hero, stablo i sadržaj stranice.
     * EN: Assembles the shared header, Home navigation, hero, tree, and page content.
     *
     * @param array<string, mixed> $workspace
     * @param list<string> $languages
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $themeBundle
     * @param array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized
     */
    private function shell(
        array $workspace,
        string $locale,
        array $languages,
        array $tree,
        int $initialPageId,
        array $themeBundle,
        array $localized,
    ): string {
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $localizedWorkspace = $this->repository->localizeWorkspace($workspace, $locale, $primaryLanguage);
        $initial = $this->resolvedSnapshot($localized, $initialPageId, $locale, $locale);
        if (!is_array($initial)) {
            throw new RuntimeException(__('Nema objavljenih stranica za izvoz.'));
        }

        $initialSnapshot = $this->snapshotForRoot($initial['snapshot']);
        $initialTitle = WorkspaceValue::string($initialSnapshot['title'] ?? '');
        $homeHref = '#page-' . $initialPageId;
        $languageControl = $this->languageControl($locale, $languages);
        $themeControl = $this->themeControl($locale, $languages);
        $assetSources = $this->stringMap($themeBundle['sources'] ?? null);
        $themeEnabled = (bool)($themeBundle['enabled'] ?? false);
        $headerEnabled = (bool)($themeBundle['header_enabled'] ?? false);
        $header = $headerEnabled
        ? $this->theme->renderHeader(
            $locale,
            $languageControl,
            $themeControl,
            $assetSources,
            $homeHref,
        )
        : '';
        if ($header === '' && !$themeEnabled) {
            $header = $this->fallbackHeader($languageControl, $themeControl, $homeHref);
        }

        /*
         * HR: Kao i u aplikaciji, jezik i korisnička kontrola pripadaju desnoj
         *     strani menija kada tema nema zaglavlje. Offline izvoz umjesto
         *     korisnika prikazuje odabir svijetle/tamne varijante.
         * EN: As in the application, language and account controls belong on the
         *     right side of the menu when the theme has no header. The offline
         *     export replaces the account control with the light/dark selector.
         */
        $navigationControls = $header === '' ? $languageControl . $themeControl : '';
        $navigation = $this->homeNavigation($homeHref, $locale, $languages, $navigationControls);
        $navigationInHero = WorkspaceValue::string($themeBundle['navigation_placement'] ?? '') === 'hero';
        $exportNote = $this->localizedExportNote($workspace, $languages, $locale);
        $hero = $this->theme->renderHero($locale, [
            'is_home' => false,
            'eyebrow' => '',
            'title' => $initialTitle,
            'subtitle' => '',
            'actions_html' => $exportNote,
            'title_context' => 'integrated',
        ], $navigationInHero ? $navigation : '', $assetSources);
        if ($hero === '') {
            $hero = $this->fallbackHero($initialTitle, $exportNote);
        }

        $treeVisible = $this->config->treeVisibleForWorkspace($workspace);
        $tocVisible = $this->editor->tableOfContentsVisibleByDefault()
        && WorkspaceValue::rows($initialSnapshot['headings'] ?? null) !== [];
        $content = $this->pageContent($initialSnapshot, $locale);
        $presentation = $this->theme->mainContentPresentation($hero !== '', true);
        $main = '<main id="main-content" class="' . $this->escape($presentation['classes']) . '">'
        . '<div class="workspace-shell workspace-export-layout'
        . ($treeVisible ? ' workspace-export-tree-visible' : '')
        . ($tocVisible ? ' workspace-export-toc-visible' : '')
        . '" data-workspace-export-layout data-default-language="' . $this->escape($locale)
        . '" data-initial-page="' . $initialPageId . '">'
        . '<aside id="workspace-page-tree" class="workspace-sidebar workspace-export-tree"'
        . ' data-export-panel="tree"' . ($treeVisible ? '' : ' hidden') . '>'
        . '<nav class="card shadow-sm workspace-tree-card hph-sidebar-card"><div class="card-body">'
        . '<h2 class="h6 text-uppercase text-body-secondary workspace-tree-title">'
        . $this->localizedWorkspaceName($workspace, $languages, $locale)
        . '</h2>' . $this->treeHtml($tree, $localized, $languages, $locale)
        . '</div></nav></aside>'
        . '<section class="workspace-main workspace-export-main" data-export-page-host>' . $content . '</section>'
        . '<aside class="workspace-export-toc" data-export-panel="toc"'
        . ($tocVisible ? '' : ' hidden') . '>'
        . $this->outlineHtml(WorkspaceValue::rows($initialSnapshot['headings'] ?? null), $locale)
        . '</aside></div></main>';
        $templates = $this->pageTemplates($localized, $languages);

        $mode = WorkspaceValue::string($themeBundle['mode'] ?? 'auto');
        $mode = in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';

        return '<!doctype html>' . "\n"
        . '<html lang="' . $this->escape($locale) . '" data-hph-theme="' . $this->escape($mode) . '">' . "\n"
        . "<head>\n"
        . "    <meta charset=\"UTF-8\">\n"
        . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '    <title>' . $this->escape($this->applicationTitle($localizedWorkspace)) . "</title>\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/bootstrap.min.css\">\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/theme.css\">\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/calendar.css\">\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/tasks.css\">\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/workspace.css\">\n"
        . "    <link rel=\"stylesheet\" href=\"assets/css/workspace-export.css\">\n"
        . "</head>\n<body>\n"
        . '<a class="hph-skip-link" href="#main-content">'
        . $this->localizedText('Preskoči na glavni sadržaj', $languages, $locale) . '</a>'
        . $header
        . ($navigationInHero ? '' : $navigation)
        . '<div class="' . $this->escape($presentation['stage_classes']) . '">' . $hero . $main . '</div>'
        . $templates
        . '<footer class="workspace-export-footer">Simbioza by HeartPhrame</footer>'
        . "<script src=\"assets/js/workspace-export.js\"></script>\n"
        . "</body>\n</html>\n";
    }

    /**
     * HR: Renderira sadržaj kartice bez dodatnog H1 naslova; tema ga već prikazuje u herou.
     * EN: Renders card content without an extra H1 title; the theme already shows it in the hero.
     *
     * @param array<string, mixed> $snapshot
     */
    private function pageContent(array $snapshot, string $locale): string
    {
        return '<article class="card shadow-sm editor-html-view-card hph-content-card workspace-export-document">'
        . '<div class="card-body" data-hph-content-title-target><div class="workspace-export-actions" aria-label="'
        . $this->escape($this->t('Opcije prikaza', $locale)) . '">'
        . $this->toggleButton('tree', $this->t('Stablo stranica', $locale), true)
        . $this->toggleButton(
            'toc',
            $this->t('Sadržaj', $locale),
            WorkspaceValue::rows($snapshot['headings'] ?? null) !== [],
        )
        . $this->toggleButton(
            'attachments',
            $this->t('Privitci', $locale),
            WorkspaceValue::rows($snapshot['attachments'] ?? null) !== [],
        )
        . '</div><div class="editor-html-view-content">'
        . WorkspaceValue::string($snapshot['html'] ?? '') . '</div>'
        . $this->attachmentsHtml(WorkspaceValue::rows($snapshot['attachments'] ?? null), $locale)
        . '</div></article>';
    }

    /**
     * HR: Ugrađuje sve lokalizirane prikaze u root dokument kako prebacivanje radi
     *     i preko `file://` bez Fetch API-ja ili web poslužitelja.
     * EN: Embeds all localized views in the root document so switching works over
     *     `file://` without the Fetch API or a web server.
     *
     * @param array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized
     * @param list<string> $languages
     */
    private function pageTemplates(array $localized, array $languages): string
    {
        $templates = '';
        $defaultLanguage = $this->config->siteDefaultLanguage();
        $nodeIds = [];
        foreach ($localized as $pages) {
            foreach (array_keys($pages) as $nodeId) {
                $nodeIds[(int)$nodeId] = true;
            }
        }

        foreach (array_keys($nodeIds) as $nodeId) {
            foreach ($languages as $locale) {
                $resolved = $this->resolvedSnapshot($localized, $nodeId, $locale, $defaultLanguage);
                if (!is_array($resolved)) {
                    continue;
                }

                $snapshot = $this->snapshotForRoot($resolved['snapshot']);
                $title = WorkspaceValue::string($snapshot['title'] ?? '');
                $templates .= '<template data-export-page-template data-node-id="' . $nodeId
                . '" data-language="' . $this->escape($locale) . '" data-page-title="'
                . $this->escape($title) . '">' . $this->pageContent($snapshot, $locale) . '</template>'
                . '<template data-export-outline-template data-node-id="' . $nodeId
                . '" data-language="' . $this->escape($locale) . '">'
                . $this->outlineHtml(WorkspaceValue::rows($snapshot['headings'] ?? null), $locale)
                . '</template>';
            }
        }

        return '<div hidden data-export-templates>' . $templates . '</div>';
    }

    /**
     * HR: Renderira lokalno stablo koje sadrži isključivo stvarno izvezene čvorove.
     * EN: Renders a local tree containing only nodes actually included in the export.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized
     * @param list<string> $languages
     */
    private function treeHtml(
        array $nodes,
        array $localized,
        array $languages,
        string $locale,
    ): string {
        if ($nodes === []) {
            return '<p class="small text-body-secondary">'
            . $this->escape($this->t('Stablo je prazno.', $locale))
            . '</p>';
        }

        $items = '';
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $type = WorkspaceValue::string($node['node_type'] ?? 'document');
            $children = WorkspaceValue::rows($node['children'] ?? null);
            if ($type === 'document') {
                $href = '#page-' . $nodeId;
            } else {
                $href = WorkspaceValue::string($node['link_url'] ?? '#');
            }

            if ($href === '') {
                continue;
            }

            $labels = '';
            foreach ($languages as $language) {
                $localizedNode = $this->repository->localizeNode(
                    $node,
                    $language,
                    $this->config->siteDefaultLanguage(),
                );
                $labels .= '<span data-export-tree-label data-language="' . $this->escape($language) . '"'
                . ($language === $locale ? '' : ' hidden') . '>'
                . $this->escape(WorkspaceValue::string($localizedNode['title'] ?? '')) . '</span>';
            }

            $items .= '<li><a href="' . $this->escape($href) . '"'
            . ($type === 'document' ? ' data-export-page-link data-node-id="' . $nodeId . '"' : '') . '>'
            . $labels . '</a>'
            . ($children !== [] ? $this->treeHtml($children, $localized, $languages, $locale) : '')
            . '</li>';
        }

        return '<ul class="workspace-export-tree-list">' . $items . '</ul>';
    }

    /**
     * HR: Renderira automatski sadržaj trenutačnog dokumenta.
     * EN: Renders the generated outline of the current document.
     *
     * @param list<array<string, mixed>> $headings
     */
    private function outlineHtml(array $headings, string $locale): string
    {
        if ($headings === []) {
            return '';
        }

        $links = '';
        foreach ($headings as $heading) {
            $level = max(1, WorkspaceValue::int($heading['level'] ?? 1));
            $links .= '<a class="workspace-export-toc-link workspace-export-toc-level-' . $level
            . '" href="#' . $this->escape(WorkspaceValue::string($heading['id'] ?? '')) . '">'
            . $this->escape(WorkspaceValue::string($heading['text'] ?? '')) . '</a>';
        }

        return '<nav class="card shadow-sm hph-sidebar-card workspace-export-toc-card">'
        . '<div class="card-body">'
        . '<h2 class="h6 text-uppercase text-body-secondary">'
        . $this->escape($this->t('Sadržaj', $locale)) . '</h2>' . $links . '</div></nav>';
    }

    /**
     * HR: Renderira popis privitaka koji se lokalno pokazuje ili skriva.
     * EN: Renders the attachment list that is shown or hidden locally.
     *
     * @param list<array<string, mixed>> $attachments
     */
    private function attachmentsHtml(array $attachments, string $locale): string
    {
        if ($attachments === []) {
            return '';
        }

        $items = '';
        foreach ($attachments as $attachment) {
            $items .= '<a class="list-group-item list-group-item-action d-flex flex-wrap gap-2 '
            . 'align-items-center" href="'
            . $this->escape(WorkspaceValue::string($attachment['path'] ?? ''))
            . '"><strong class="me-auto">'
            . $this->escape(WorkspaceValue::string($attachment['name'] ?? ''))
            . '</strong><span class="badge text-bg-secondary">'
            . $this->escape(WorkspaceValue::string($attachment['mime_type'] ?? ''))
            . '</span><span class="small text-body-secondary">'
            . $this->escape($this->fileSize(WorkspaceValue::int($attachment['file_size'] ?? 0)))
            . '</span></a>';
        }

        return '<section class="workspace-export-attachments mt-4" data-export-panel="attachments" hidden>'
        . '<h2 class="h6 text-uppercase text-body-secondary">' . $this->escape($this->t('Privitci', $locale))
        . '</h2><div class="list-group list-group-flush">' . $items . '</div></section>';
    }

    /**
     * HR: Renderira jednu pristupačnu ikonsku kontrolu offline prikaza.
     * EN: Renders one accessible icon control for the offline view.
     */
    private function toggleButton(string $target, string $label, bool $enabled): string
    {
        $icons = [
            'tree' => '<path d="M10 3H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2V5'
            . 'a2 2 0 0 0-2-2z"/><path d="M19 14h-5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2v-3'
            . 'a2 2 0 0 0-2-2z"/><path d="M7 10v2a2 2 0 0 0 2 2h5"/>',
            'toc' => '<path d="M4 5h3M4 12h3M4 19h3M10 5h10M10 12h10M10 19h10"/>',
            'attachments' => '<path d="m21.4 11.6-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7'
            . 'l-9.6 9.6a2 2 0 0 1-2.8-2.8l8.9-8.9"/>',
        ];

        return '<button class="btn btn-outline-secondary btn-sm workspace-export-toggle" type="button"'
        . ' data-export-toggle="' . $this->escape($target) . '" title="' . $this->escape($label) . '"'
        . ' aria-label="' . $this->escape($label) . '"'
        . ($enabled ? '' : ' disabled') . '><svg viewBox="0 0 24 24" aria-hidden="true">'
        . ($icons[$target] ?? '') . '</svg></button>';
    }

    /**
     * HR: Gradi offline jezični select koji vodi na istu datoteku drugog jezika.
     * EN: Builds an offline language select linking to the same file in another locale.
     *
     * @param list<string> $languages
     */
    private function languageControl(string $locale, array $languages): string
    {
        $labels = $this->editor->languageLabels();
        $options = '';
        foreach ($languages as $language) {
            $options .= '<option value="' . $this->escape($language) . '"'
            . ($language === $locale ? ' selected' : '') . '>'
            . $this->escape($labels[$language] ?? strtoupper($language)) . '</option>';
        }

        return '<li class="nav-item"><label class="visually-hidden" for="workspace-export-language">'
        . $this->localizedText('Jezik', $languages, $locale) . '</label><select id="workspace-export-language"'
        . ' class="form-select form-select-sm" data-export-language>' . $options . '</select></li>';
    }

    /**
     * HR: Gradi select za automatsku, svijetlu ili tamnu varijantu teme.
     * EN: Builds the select for automatic, light, or dark theme variants.
     *
     * @param list<string> $languages
     */
    private function themeControl(string $locale, array $languages): string
    {
        return '<li class="nav-item"><label class="visually-hidden" for="workspace-export-theme">'
        . $this->localizedText('Tema', $languages, $locale) . '</label><select id="workspace-export-theme"'
        . ' class="form-select form-select-sm" data-export-theme>'
        . $this->localizedOption('auto', 'Automatski', $languages, $locale)
        . $this->localizedOption('light', 'Svijetlo', $languages, $locale)
        . $this->localizedOption('dark', 'Tamno', $languages, $locale)
        . '</select></li>';
    }

    /**
     * HR: U opciju ugrađuje prijevode svih jezika kako bi offline izvoz mogao
     *     promijeniti tekst bez ponovnog učitavanja ili poslužitelja.
     * EN: Embeds every locale label in an option so the offline export can update
     *     its copy without a reload or a server.
     *
     * @param list<string> $languages
     */
    private function localizedOption(
        string $value,
        string $key,
        array $languages,
        string $activeLocale,
    ): string {
        $attributes = '';
        foreach ($languages as $language) {
            $attributes .= ' data-export-label-' . $this->escape($language) . '="'
            . $this->escape($this->t($key, $language)) . '"';
        }

        return '<option value="' . $this->escape($value) . '"' . $attributes . '>'
        . $this->escape($this->t($key, $activeLocale)) . '</option>';
    }

    /**
     * HR: Renderira jedinu offline navigacijsku stavku koja vodi na izvezenu naslovnicu.
     * EN: Renders the sole offline navigation item linking to the exported homepage.
     *
     * @param list<string> $languages
     */
    private function homeNavigation(
        string $homeHref,
        string $locale,
        array $languages,
        string $controls = '',
    ): string {
        return '<nav class="navbar navbar-expand navbar-dark bg-dark hph-primary-navigation">'
        . '<div class="container-fluid hph-container-wide px-4 d-flex align-items-center">'
        . '<ul class="navbar-nav flex-row">'
        . '<li class="nav-item"><a class="nav-link active" href="' . $this->escape($homeHref)
        . '" aria-current="page" data-export-home>'
        . $this->localizedText('Home', $languages, $locale)
        . '</a></li></ul>'
        . ($controls !== ''
            ? '<ul class="navbar-nav ms-auto flex-row align-items-center gap-2 '
                . 'workspace-export-navigation-controls">' . $controls . '</ul>'
            : '')
        . '</div></nav>';
    }

    /**
     * HR: Renderira čitljivo offline zaglavlje kada Theme modul nije dostupan.
     * EN: Renders a readable offline header when the Theme module is unavailable.
     */
    private function fallbackHeader(string $languageControl, string $themeControl, string $homeHref): string
    {
        return '<header class="hph-site-header"><div class="container-fluid hph-container-wide hph-site-header__inner">'
        . '<div class="hph-site-header__group"><a class="hph-site-header__item fw-semibold text-decoration-none" href="'
        . $this->escape($homeHref) . '">Simbioza</a></div>'
        . '<div class="hph-site-header__group hph-site-header__group--end">'
        . '<ul class="navbar-nav hph-site-header__control">' . $languageControl . $themeControl
        . '</ul></div></div></header>';
    }

    /**
     * HR: Renderira obavezni Simbioza hero kada aktivna tema nema vlastiti hero.
     * EN: Renders the required Simbioza hero when the active theme provides none.
     *
     */
    private function fallbackHero(string $title, string $exportNote): string
    {
        return '<section class="hph-hero hph-hero--medium hph-hero--with-text"><div class="hph-hero__stage">'
        . '<div class="container-fluid hph-container-wide px-4 hph-hero__text-frame"><div class="hph-hero__content">'
        . '<h1 class="hph-hero__title ' . $this->heroTitleLengthClass($title) . '">'
        . $this->escape($title) . '</h1>'
        . $exportNote . '</div></div></div></section>';
    }

    /**
     * HR: Odabire isti profil duljine hero naslova koji koristi Theme modul.
     * EN: Selects the same hero-title length profile used by the Theme module.
     */
    private function heroTitleLengthClass(string $title): string
    {
        $characters = preg_split('//u', trim($title), -1, PREG_SPLIT_NO_EMPTY);
        $length = is_array($characters) ? count($characters) : strlen($title);

        return match (true) {
            $length > 140 => 'hph-hero__title--extreme',
            $length > 96 => 'hph-hero__title--very-long',
            $length > 60 => 'hph-hero__title--long',
            default => 'hph-hero__title--regular',
        };
    }

    /**
     * HR: Gradi uvijek vidljivu napomenu o izvezenom području za svaki podržani jezik.
     * EN: Builds the always-present exported-Workspace note for every supported locale.
     *
     * @param array<string, mixed> $workspace
     * @param list<string> $languages
     */
    private function localizedExportNote(array $workspace, array $languages, string $activeLocale): string
    {
        $content = '';
        foreach ($languages as $language) {
            $localizedWorkspace = $this->repository->localizeWorkspace(
                $workspace,
                $language,
                $this->config->siteDefaultLanguage(),
            );
            $text = $this->t('Izvezeno područje: :workspace', $language, [
                'workspace' => WorkspaceValue::string($localizedWorkspace['name'] ?? ''),
            ]);
            $content .= '<span data-export-localized data-language="' . $this->escape($language) . '"'
            . ($language === $activeLocale ? '' : ' hidden') . '>' . $this->escape($text) . '</span>';
        }

        return '<p class="hph-hero__export-note">' . $content . '</p>';
    }

    /**
     * HR: Ugrađuje lokalizirani naziv područja za svaki jezik offline ljuske.
     * EN: Embeds the localized Workspace name for every offline-shell locale.
     *
     * @param array<string, mixed> $workspace
     * @param list<string> $languages
     */
    private function localizedWorkspaceName(array $workspace, array $languages, string $activeLocale): string
    {
        $content = '';
        foreach ($languages as $language) {
            $localized = $this->repository->localizeWorkspace(
                $workspace,
                $language,
                $this->config->siteDefaultLanguage(),
            );
            $content .= '<span data-export-localized data-language="' . $this->escape($language) . '"'
            . ($language === $activeLocale ? '' : ' hidden') . '>'
            . $this->escape(WorkspaceValue::string($localized['name'] ?? '')) . '</span>';
        }

        return $content;
    }

    /**
     * HR: Renderira kratki UI tekst u svim jezicima bez dupliciranja aplikacijske ljuske.
     * EN: Renders short UI copy in all locales without duplicating the application shell.
     *
     * @param list<string> $languages
     */
    private function localizedText(string $key, array $languages, string $activeLocale): string
    {
        $content = '';
        foreach ($languages as $language) {
            $content .= '<span data-export-localized data-language="' . $this->escape($language) . '"'
            . ($language === $activeLocale ? '' : ' hidden') . '>'
            . $this->escape($this->t($key, $language)) . '</span>';
        }

        return $content;
    }

    /**
     * HR: Odabire točan prijevod, zatim zadani hrvatski, a tek potom prvi stvarni prijevod.
     * EN: Resolves the exact translation, then the Croatian site default, and only then
     *     the first real translation.
     *
     * @param array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized
     * @return array{snapshot:array<string,mixed>,source_language:string}|null
     */
    private function resolvedSnapshot(
        array $localized,
        int $nodeId,
        string $locale,
        string $defaultLanguage,
    ): ?array {
        if (isset($localized[$locale][$nodeId])) {
            return $localized[$locale][$nodeId];
        }

        if (isset($localized[$defaultLanguage][$nodeId])) {
            return $localized[$defaultLanguage][$nodeId];
        }

        foreach ($localized as $pages) {
            if (isset($pages[$nodeId])) {
                return $pages[$nodeId];
            }
        }

        return null;
    }

    /**
     * HR: Provjerava postoji li barem jedan stvarni objavljeni prijevod stranice.
     * EN: Checks whether at least one real published translation exists for a page.
     *
     * @param array<string, array<int, array{snapshot:array<string,mixed>,source_language:string}>> $localized
     */
    private function pageHasSnapshot(array $localized, int $nodeId): bool
    {
        foreach ($localized as $pages) {
            if (isset($pages[$nodeId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * HR: Putanje iz samostalne stranice prilagođava root offline ljusci.
     * EN: Re-bases standalone-page paths for the root offline shell.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function snapshotForRoot(array $snapshot): array
    {
        $snapshot['html'] = str_replace(
            '../documents/',
            'documents/',
            WorkspaceValue::string($snapshot['html'] ?? ''),
        );
        $attachments = [];
        foreach (WorkspaceValue::rows($snapshot['attachments'] ?? null) as $attachment) {
            $attachment['path'] = str_replace(
                '../documents/',
                'documents/',
                WorkspaceValue::string($attachment['path'] ?? ''),
            );
            $attachments[] = $attachment;
        }

        $snapshot['attachments'] = $attachments;

        return $snapshot;
    }

    /**
     * HR: Filtrira stablo na čvorove koji imaju objavljenu inačicu barem jednog traženog jezika.
     * EN: Filters the tree to nodes with a published variant in at least one requested locale.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, list<array<string, mixed>>> $workflows
     * @return list<array<string, mixed>>
     */
    private function filterPublishedTree(array $nodes, array $workflows): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $children = $this->filterPublishedTree(
                WorkspaceValue::rows($node['children'] ?? null),
                $workflows,
            );
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $type = WorkspaceValue::string($node['node_type'] ?? 'document');
            $readable = $type !== 'document';
            foreach (WorkspaceValue::rows($workflows[$nodeId] ?? null) as $workflow) {
                if ($this->workflow->isReadableWorkflow($workflow)) {
                    $readable = true;
                    break;
                }
            }

            if ($readable) {
                $node['children'] = $children;
                $result[] = $node;
            } else {
                $result = array_merge($result, $children);
            }
        }

        return $result;
    }

    /**
     * HR: Zadržava samo izabrane čvorove; djecu neizabranog roditelja sigurno promiče jednu razinu.
     * EN: Keeps only selected nodes and safely promotes children of an omitted parent by one level.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<int> $selectedIds
     * @return list<array<string, mixed>>
     */
    private function filterSelectedTree(array $nodes, array $selectedIds, bool $documentsOnly = false): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $children = $this->filterSelectedTree(
                WorkspaceValue::rows($node['children'] ?? null),
                $selectedIds,
                $documentsOnly,
            );
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $selected = in_array($nodeId, $selectedIds, true);
            if ($documentsOnly && WorkspaceValue::string($node['node_type'] ?? '') !== 'document') {
                $selected = false;
            }

            if ($selected) {
                $node['children'] = $children;
                $result[] = $node;
            } else {
                $result = array_merge($result, $children);
            }
        }

        return $result;
    }

    /**
     * HR: Pretvara ugniježđeno stablo u stabilan pre-order popis.
     * EN: Converts a nested tree into a stable pre-order list.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function flatten(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $children = WorkspaceValue::rows($node['children'] ?? null);
            unset($node['children']);
            $result[] = $node;
            $result = array_merge($result, $this->flatten($children));
        }

        return $result;
    }

    /**
     * HR: Odabire samo stvarno objavljenu inačicu traženog jezika; izvoz ne umnaža fallback sadržaj.
     * EN: Selects only an actually published requested-locale variant; export never duplicates fallback content.
     *
     * @param list<array<string, mixed>> $workflows
     * @return array<string, mixed>|null
     */
    private function workflowForLocale(array $workflows, string $locale): ?array
    {
        foreach ($workflows as $workflow) {
            if (!$this->workflow->isReadableWorkflow($workflow)) {
                continue;
            }

            $language = strtolower(WorkspaceValue::string($workflow['language_code'] ?? ''));
            if ($language === strtolower($locale)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * HR: Dodjeljuje sigurna i jedinstvena HTML imena prema slugovima čvorova.
     * EN: Assigns safe, unique HTML filenames from node slugs.
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<int, string>
     */
    private function pageFileNames(array $nodes): array
    {
        $result = [];
        $used = ['index.html' => true];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $slug = strtolower(WorkspaceValue::string($node['slug'] ?? 'page-' . $nodeId));
            $slug = trim((string)preg_replace('/[^a-z0-9._-]+/', '-', $slug), '-.');
            $slug = $slug !== '' ? $slug : 'page-' . $nodeId;
            $candidate = $slug . '.html';
            $counter = 2;
            while (isset($used[$candidate])) {
                $candidate = $slug . '-' . $counter . '.html';
                ++$counter;
            }

            $used[$candidate] = true;
            $result[$nodeId] = $candidate;
        }

        return $result;
    }

    /**
     * HR: Vraća ID označene početne stranice samo ako je ona dio izvoza.
     * EN: Returns the designated homepage ID only when it is part of the export.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function homepageId(array $nodes): int
    {
        foreach ($nodes as $node) {
            if ((bool)($node['is_homepage'] ?? false)) {
                return WorkspaceValue::int($node['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * HR: Zapisuje transparentni manifest izvora, jezika i izvezenih stranica.
     * EN: Writes a transparent manifest of the source, locales, and exported pages.
     *
     * @param array<string, mixed> $workspace
     * @param list<array<string, scalar>> $pages
     */
    private function manifest(array $workspace, string $defaultLanguage, array $pages): string
    {
        try {
            return json_encode([
                'format' => 'simbioza-workspace-html-export',
                'version' => 2,
                'workspace' => [
                    'id' => WorkspaceValue::int($workspace['id'] ?? 0),
                    'slug' => WorkspaceValue::string($workspace['slug'] ?? ''),
                    'name' => WorkspaceValue::string($workspace['name'] ?? ''),
                    'name_translations' => $this->repository->translationMap(
                        $workspace['name_translations'] ?? null,
                    ),
                    'description_translations' => $this->repository->translationMap(
                        $workspace['description_translations'] ?? null,
                    ),
                ],
                'default_language' => $defaultLanguage,
                'generated_at' => date(DATE_ATOM),
                'pages' => $pages,
                'offline_ready' => true,
                'acl_snapshot' => true,
                'interactive_server_features' => false,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(__('Manifest izvoza nije moguće izraditi.'), 0, $jsonException);
        }
    }

    /**
     * HR: Piše ZIP PHP ekstenzijom i vraća binarni sadržaj bez trajne privremene datoteke.
     * EN: Writes the ZIP with the PHP extension and returns binary content without a persistent temp file.
     *
     * @param array<string, string> $entries
     */
    private function zip(array $entries): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'workspace-export-');
        if (!is_string($temporaryFile)) {
            throw new RuntimeException(__('ZIP export nije moguće pripremiti.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporaryFile);
            throw new RuntimeException(__('ZIP export nije moguće pripremiti.'));
        }

        try {
            foreach ($entries as $path => $content) {
                if (!$zip->addFromString($path, $content)) {
                    throw new RuntimeException(__('ZIP export nije moguće pripremiti.'));
                }
            }
        } finally {
            $zip->close();
        }

        $content = file_get_contents($temporaryFile);
        @unlink($temporaryFile);
        if (!is_string($content)) {
            throw new RuntimeException(__('ZIP export nije moguće pripremiti.'));
        }

        return $content;
    }

    /**
     * HR: Vraća stabilan naziv ZIP datoteke bez opasnih znakova.
     * EN: Returns a stable ZIP filename without unsafe characters.
     *
     * @param array<string, mixed> $workspace
     */
    private function zipFileName(array $workspace): string
    {
        $slug = strtolower(WorkspaceValue::string($workspace['slug'] ?? 'workspace'));
        $slug = trim((string)preg_replace('/[^a-z0-9._-]+/', '-', $slug), '-.');

        return 'simbioza-' . ($slug !== '' ? $slug : 'workspace') . '-' . date('Ymd-His') . '.zip';
    }

    /**
     * HR: Vraća puni naslov offline aplikacije.
     * EN: Returns the complete offline-application title.
     *
     * @param array<string, mixed> $workspace
     */
    private function applicationTitle(array $workspace): string
    {
        return 'Simbioza - ' . WorkspaceValue::string($workspace['name'] ?? '');
    }

    /**
     * HR: Prevoditelj prima eksplicitni jezik kako generiranje ne bi mijenjalo web sesiju.
     * EN: The translator receives an explicit locale so generation does not mutate the web session.
     *
     * @param array<string, string|int|float> $replace
     */
    private function t(string $key, string $locale, array $replace = []): string
    {
        return $this->translator->trans($key, $replace, $locale);
    }

    /**
     * HR: Formatira veličinu privitka za čitljiv offline popis.
     * EN: Formats an attachment size for the readable offline list.
     */
    private function fileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * HR: Sigurno kodira obični tekst prije umetanja u generirani HTML.
     * EN: Safely encodes plain text before inserting it into generated HTML.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * HR: Filtrira nepouzdani bridge payload na strogu mapu tekstualnih putanja.
     * EN: Filters an untrusted bridge payload into a strict map of string paths.
     *
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        $result = [];
        foreach (WorkspaceValue::stringKeyArray($value) as $key => $item) {
            if (is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * HR: Minimalni fallback čuva čitljiv export kada Theme modul nije instaliran.
     * EN: A minimal fallback keeps the export readable when Theme is not installed.
     */
    private function fallbackThemeCss(): string
    {
        return ':root{--bs-body-bg:#fff;--bs-body-color:#212529;--bs-primary:#0d6efd;}'
        . 'body{background:var(--bs-body-bg);color:var(--bs-body-color)}';
    }

    /**
     * HR: Strukturni CSS offline područja nadopunjuje, ali ne mijenja aktivnu temu.
     * EN: Structural offline-Workspace CSS complements but does not replace the active theme.
     */
    private function exportCss(): string
    {
        return <<<'CSS'
body {
    margin: 0;
    min-height: 100vh;
}

.workspace-export-layout {
    align-items: start;
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(0, 1fr);
}

.workspace-export-layout.workspace-export-tree-visible:not(.workspace-export-toc-visible) {
    grid-template-columns: minmax(14rem, 22rem) minmax(0, 1fr);
}

.workspace-export-layout.workspace-export-toc-visible:not(.workspace-export-tree-visible) {
    grid-template-columns: minmax(0, 1fr) minmax(13rem, 19rem);
}

.workspace-export-layout.workspace-export-tree-visible.workspace-export-toc-visible {
    grid-template-columns: minmax(14rem, 22rem) minmax(0, 1fr) minmax(13rem, 19rem);
}

.workspace-export-main {
    min-width: 0;
}

.workspace-export-tree,
.workspace-export-toc {
    max-height: calc(100vh - 2rem);
    overflow: visible;
    position: sticky;
    top: 1rem;
}

/*
 * HR: Pomicanje je unutar kartice, a ne na vanjskom stupcu, kako rub stupca
 *     ne bi odrezao tematsku sjenu kartice.
 * EN: Scrolling lives inside the card instead of the outer column so the
 *     column boundary cannot clip the themed card shadow.
 */
.workspace-export-tree-list {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}

.workspace-export-toc-card {
    max-height: inherit;
    overflow: auto;
}

.workspace-export-tree[hidden],
.workspace-export-toc[hidden],
.workspace-export-attachments[hidden] {
    display: none !important;
}

.workspace-export-tree-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.workspace-export-tree-list .workspace-export-tree-list {
    padding-inline-start: 1rem;
}

.workspace-export-tree-list a {
    border-bottom: 1px solid var(--hph-border, var(--bs-border-color));
    color: var(--hph-surface-text, var(--bs-body-color));
    display: block;
    padding: .55rem .4rem;
    text-decoration: none;
}

.workspace-export-tree-list a:hover,
.workspace-export-tree-list a:focus-visible {
    background: var(--hph-nav-hover-bg, var(--bs-tertiary-bg));
}

.workspace-export-document {
    position: relative;
}

.workspace-export-actions {
    display: flex;
    gap: .35rem;
    justify-content: flex-end;
    margin-bottom: 1rem;
}

.workspace-export-toggle svg {
    fill: none;
    height: 1rem;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 1.8;
    width: 1rem;
}

.workspace-export-page-title {
    margin-bottom: 1.5rem;
}

.editor-html-view-content img,
.editor-html-view-content video {
    height: auto;
    max-width: 100%;
}

.workspace-export-toc-link {
    color: var(--hph-surface-text, var(--bs-body-color));
    display: block;
    padding: .35rem 0;
    text-decoration: none;
}

.workspace-export-toc-level-2 {
    padding-inline-start: .75rem;
}

.workspace-export-toc-level-3 {
    padding-inline-start: 1.5rem;
}

.workspace-export-toc-level-4,
.workspace-export-toc-level-5 {
    padding-inline-start: 2.25rem;
}

.hph-hero__export-note {
    color: var(--hph-hero-supporting-text, var(--hph-hero-text));
    margin: .25rem 0 0;
}

.workspace-export-footer {
    border-top: 1px solid var(--hph-border, var(--bs-border-color));
    color: var(--hph-muted-text, var(--bs-secondary-color));
    margin-top: 2rem;
    padding: 1.5rem;
    text-align: center;
}

.hph-site-header select {
    min-width: 7rem;
}

.workspace-export-navigation-controls .nav-item {
    align-items: center;
    display: flex;
}

.workspace-export-navigation-controls .form-select {
    min-width: 7rem;
}

/* HR: Strukturni fallback za sadržajne tabove. EN: Structural fallback for content tabs. */
.editor-html-tabs__list {
    display: flex;
    flex-wrap: wrap;
    gap: .25rem;
}
.editor-html-tabs__tab {
    background: var(--hph-editor-tab-bg, var(--hph-surface-bg, #fff));
    border: 1px solid var(--hph-editor-tab-border, var(--hph-border, #dee2e6));
    color: var(--hph-editor-tab-text, var(--hph-surface-text, #212529));
    font: inherit;
    margin-bottom: -1px;
    padding: .55rem .9rem;
}
.editor-html-tabs__tab--active {
    background: var(--hph-editor-tab-active-bg, var(--hph-surface-bg, #fff));
    border-top: 3px solid var(--hph-editor-tab-active-accent, var(--hph-primary, #0d6efd));
    color: var(--hph-editor-tab-active-text, var(--hph-surface-text, #212529));
}
.editor-html-tabs__panels {
    background: var(--hph-editor-tab-panel-bg, var(--hph-surface-bg, #fff));
    border: 1px solid var(--hph-editor-tab-border, var(--hph-border, #dee2e6));
    color: var(--hph-editor-tab-panel-text, var(--hph-surface-text, #212529));
}
.editor-html-tabs__panel { min-width: 0; padding: 1rem; }
.editor-html-tabs__panel[hidden] { display: none !important; }
.editor-html-tabs__panel pre { max-width: 100%; overflow-x: auto; overflow-y: visible; }

@media (max-width: 1199.98px) {
    .workspace-export-layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .workspace-export-layout.workspace-export-tree-visible:not(.workspace-export-toc-visible),
    .workspace-export-layout.workspace-export-toc-visible:not(.workspace-export-tree-visible),
    .workspace-export-layout.workspace-export-tree-visible.workspace-export-toc-visible {
        grid-template-columns: minmax(13rem, 18rem) minmax(0, 1fr);
    }

    .workspace-export-toc {
        grid-column: 2;
        position: static;
    }
}

@media (max-width: 767.98px) {
    .workspace-export-layout,
    .workspace-export-layout.workspace-export-tree-visible:not(.workspace-export-toc-visible),
    .workspace-export-layout.workspace-export-toc-visible:not(.workspace-export-tree-visible),
    .workspace-export-layout.workspace-export-tree-visible.workspace-export-toc-visible {
        display: block;
    }

    .workspace-export-tree,
    .workspace-export-toc {
        margin-bottom: 1rem;
        max-height: none;
        position: static;
    }

    .hph-site-header__inner {
        align-items: flex-start;
        flex-direction: column;
    }

    .hph-site-header__group--end {
        width: 100%;
    }
}
CSS;
    }

    /**
     * HR: Sav offline UI radi bez servera: tema, jezik i tri neovisna prikazna panela.
     * EN: The complete offline UI works without a server: theme, language, and three independent panels.
     */
    private function exportJs(): string
    {
        return <<<'JS'
(() => {
    const root = document.documentElement;
    const layout = document.querySelector('[data-workspace-export-layout]');
    const themeSelect = document.querySelector('[data-export-theme]');
    const languageSelect = document.querySelector('[data-export-language]');
    const pageHost = document.querySelector('[data-export-page-host]');
    const tocPanel = document.querySelector('[data-export-panel="toc"]');
    const storage = {
        get(key, fallback) { try { return localStorage.getItem(key) || fallback; } catch (_) { return fallback; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (_) {} }
    };
    let currentPage = Number((location.hash.match(/^#page-(\d+)$/) || [])[1])
        || Number(layout?.dataset.initialPage || 0);
    let currentLanguage = languageSelect?.value || layout?.dataset.defaultLanguage || 'hr';

    function applyTheme(mode) {
        if (!['auto', 'light', 'dark'].includes(mode)) mode = 'auto';
        root.setAttribute('data-hph-theme', mode);
        root.setAttribute('data-bs-theme', mode === 'auto'
            ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : mode);
        if (themeSelect) themeSelect.value = mode;
    }
    applyTheme(storage.get('simbioza-export-theme', root.dataset.hphTheme || 'auto'));
    if (themeSelect) themeSelect.addEventListener('change', () => {
        storage.set('simbioza-export-theme', themeSelect.value);
        applyTheme(themeSelect.value);
    });

    function matchingTemplate(kind, pageId, language) {
        return Array.from(document.querySelectorAll(`[data-export-${kind}-template]`)).find((template) => (
            Number(template.dataset.nodeId || 0) === Number(pageId)
            && template.dataset.language === language
        )) || null;
    }

    function applyLocalizedUi(language) {
        document.querySelectorAll('[data-export-localized], [data-export-tree-label]').forEach((item) => {
            item.hidden = item.dataset.language !== language;
        });
        document.querySelectorAll('[data-export-theme] option').forEach((option) => {
            const label = option.getAttribute(`data-export-label-${language}`);
            if (label) option.textContent = label;
        });
        root.lang = language;
        if (languageSelect) languageSelect.value = language;
    }

    function hideDuplicateHeroTitle(title) {
        if (!pageHost) return;
        for (const heading of pageHost.querySelectorAll('h1')) {
            if ((heading.textContent || '').trim() !== title.trim()) continue;
            if (heading.parentElement instanceof HTMLElement) {
                heading.parentElement.classList.add('hph-page-heading-support');
            }
            heading.hidden = true;
            heading.setAttribute('data-hph-duplicate-hero-title', '');
            break;
        }
    }

    /**
     * HR: Nakon promjene stranice obnavlja profil duljine i tekst hero naslova.
     * EN: After a page change, refreshes the hero-title length profile and text.
     */
    function applyHeroTitleProfile(heroTitle, title) {
        const profiles = [
            'hph-hero__title--regular',
            'hph-hero__title--long',
            'hph-hero__title--very-long',
            'hph-hero__title--extreme',
        ];
        const length = Array.from(title.trim()).length;
        const profile = length > 140
            ? profiles[3]
            : (length > 96 ? profiles[2] : (length > 60 ? profiles[1] : profiles[0]));
        heroTitle.classList.remove(...profiles);
        heroTitle.classList.add(profile);
        heroTitle.textContent = title;
    }

    function syncToggleState() {
        document.querySelectorAll('[data-export-toggle]').forEach((button) => {
            const targetName = button.dataset.exportToggle;
            const target = targetName === 'attachments'
                ? pageHost?.querySelector('[data-export-panel="attachments"]')
                : document.querySelector(`[data-export-panel="${targetName}"]`);
            button.setAttribute('aria-expanded', target && !target.hidden ? 'true' : 'false');
        });
    }

    function tabParts(collection) {
        return {
            tabs: Array.from(collection.querySelectorAll(':scope > .editor-html-tabs__list > [role="tab"]')),
            panels: Array.from(collection.querySelectorAll(':scope > .editor-html-tabs__panels > [role="tabpanel"]')),
        };
    }

    /* HR: Offline paket aktivira tabove bez poslužitelja. EN: The offline package activates tabs without a server. */
    function activateTab(collection, requestedIndex, focusTab) {
        const group = tabParts(collection);
        if (group.tabs.length < 2 || group.tabs.length !== group.panels.length) return;
        const index = Math.max(0, Math.min(group.tabs.length - 1, requestedIndex));
        group.tabs.forEach((tab, currentIndex) => {
            const active = currentIndex === index;
            tab.classList.toggle('editor-html-tabs__tab--active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        group.panels.forEach((panel, currentIndex) => {
            const active = currentIndex === index;
            panel.classList.toggle('editor-html-tabs__panel--active', active);
            panel.hidden = !active;
        });
        if (focusTab) group.tabs[index].focus();
    }

    function initializeTabs(container) {
        container?.querySelectorAll('[data-editor-html-tabs="1"]').forEach((collection) => {
            activateTab(collection, 0, false);
        });
    }

    function renderPage(pageId, language) {
        if (!pageHost) return;
        const pageTemplate = matchingTemplate('page', pageId, language);
        if (!(pageTemplate instanceof HTMLTemplateElement)) return;
        const title = pageTemplate.dataset.pageTitle || 'Simbioza';
        pageHost.replaceChildren(pageTemplate.content.cloneNode(true));
        initializeTabs(pageHost);

        const outlineTemplate = matchingTemplate('outline', pageId, language);
        if (tocPanel) {
            tocPanel.replaceChildren(
                outlineTemplate instanceof HTMLTemplateElement
                    ? outlineTemplate.content.cloneNode(true)
                    : document.createDocumentFragment(),
            );
            const hasOutline = tocPanel.childElementCount > 0;
            if (!hasOutline) tocPanel.hidden = true;
            layout?.classList.toggle('workspace-export-toc-visible', hasOutline && !tocPanel.hidden);
        }

        const heroTitle = document.querySelector('.hph-hero__title');
        if (heroTitle) applyHeroTitleProfile(heroTitle, title);
        document.title = `Simbioza - ${title}`;
        currentPage = Number(pageId);
        currentLanguage = language;
        applyLocalizedUi(language);
        hideDuplicateHeroTitle(title);
        document.querySelectorAll('[data-export-page-link]').forEach((link) => {
            const active = Number(link.dataset.nodeId || 0) === currentPage;
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        syncToggleState();
    }

    if (languageSelect) languageSelect.addEventListener('change', () => {
        if (!languageSelect.value) return;
        renderPage(currentPage, languageSelect.value);
    });
    document.addEventListener('click', (event) => {
        const contentTab = event.target.closest('[data-editor-html-tabs="1"] [role="tab"]');
        if (contentTab) {
            const collection = contentTab.closest('[data-editor-html-tabs="1"]');
            activateTab(collection, tabParts(collection).tabs.indexOf(contentTab), false);
            return;
        }
        const pageLink = event.target.closest('[data-export-page-link], [data-export-home]');
        if (pageLink) {
            const match = (pageLink.getAttribute('href') || '').match(/^#page-(\d+)$/);
            if (match) {
                event.preventDefault();
                const pageId = Number(match[1]);
                if (location.hash === `#page-${pageId}`) renderPage(pageId, currentLanguage);
                else location.hash = `page-${pageId}`;
            }
            return;
        }

        const button = event.target.closest('[data-export-toggle]');
        if (!button || button.disabled) return;
        const targetName = button.dataset.exportToggle;
        const target = targetName === 'attachments'
            ? pageHost?.querySelector('[data-export-panel="attachments"]')
            : document.querySelector(`[data-export-panel="${targetName}"]`);
        if (!target) return;
        target.hidden = !target.hidden;
        button.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
        if (layout && ['tree', 'toc'].includes(targetName)) {
            layout.classList.toggle(`workspace-export-${targetName}-visible`, !target.hidden);
        }
    });
    document.addEventListener('keydown', (event) => {
        const tab = event.target.closest('[data-editor-html-tabs="1"] [role="tab"]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const collection = tab.closest('[data-editor-html-tabs="1"]');
        const tabs = tabParts(collection).tabs;
        if (tabs.length < 2) return;
        event.preventDefault();
        const current = Math.max(0, tabs.indexOf(tab));
        const next = event.key === 'Home' ? 0 : (event.key === 'End'
            ? tabs.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length);
        activateTab(collection, next, true);
    });
    addEventListener('hashchange', () => {
        const match = location.hash.match(/^#page-(\d+)$/);
        if (match) renderPage(Number(match[1]), currentLanguage);
    });
    renderPage(currentPage, currentLanguage);
})();
JS;
    }
}
