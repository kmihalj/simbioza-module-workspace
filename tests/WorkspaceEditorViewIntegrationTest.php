<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspaceEditorViewIntegrationTest extends TestCase
{
    /**
     * HR: Workspace mora ugraditi službeni Editorov pregled umjesto vlastitog ispisa sirovog HTML-a.
     * EN: Workspace must embed Editor's official view instead of rendering raw HTML itself.
     */
    public function testWorkspaceEmbedsOfficialEditorDocumentView(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');

        $this->assertIsString($view);
        $this->assertStringContainsString("'editor/view'", $view);
        $this->assertStringContainsString('$editorView', $view);
        $this->assertStringNotContainsString('workspace-document', $view);
        $this->assertStringNotContainsString('$document[\'html\']', $view);
    }

    /**
     * HR: Opcionalni most mora tražiti zajednički builder bez tvrde Composer ovisnosti.
     * EN: The optional bridge must resolve the shared builder without a hard Composer dependency.
     */
    public function testBridgeUsesSharedEditorViewBuilder(): void
    {
        $bridge = file_get_contents(dirname(__DIR__) . '/src/Service/WorkspaceEditorBridge.php');

        $this->assertIsString($bridge);
        $this->assertStringContainsString('EditorDocumentViewBuilder', $bridge);
        $this->assertStringContainsString('documentView(', $bridge);
    }

    /**
     * HR: Izvoz Područja mora prerenderirati nativne grafikone jednako kao izvoz stranice.
     * EN: Workspace export must pre-render native charts just like page export.
     */
    public function testWorkspaceExportPreRendersNativeEditorCharts(): void
    {
        $bridge = file_get_contents(dirname(__DIR__) . '/src/Service/WorkspaceExportEditorBridge.php');

        $this->assertIsString($bridge);
        $this->assertStringContainsString("service('EditorHtmlChartService')", $bridge);
        $this->assertStringContainsString("method_exists(\$charts, 'render')", $bridge);
    }

    /**
     * HR: Backlink indeks mora koristiti Editorov interni put bez sesijskog ACL-a.
     * EN: The backlink index must use the Editor internal path without session ACL.
     */
    public function testBacklinkIndexUsesDedicatedInternalEditorReadPath(): void
    {
        $bridge = file_get_contents(dirname(__DIR__) . '/src/Service/WorkspaceEditorBridge.php');
        $indexer = file_get_contents(dirname(__DIR__) . '/src/Service/WorkspaceBacklinkIndexer.php');

        $this->assertIsString($bridge);
        $this->assertIsString($indexer);
        $this->assertStringContainsString('publishedVersionsForIndexing(', $bridge);
        $this->assertStringContainsString('loadPublishedVersionsForIndexing', $bridge);
        $this->assertStringContainsString('publishedVersionsForIndexing(', $indexer);
    }

    /**
     * HR: Akcije područja moraju ostati u karticama kojima pripadaju kako ne bi
     *     stvarale zaseban prazan red iznad stabla i HTML sadržaja.
     * EN: Workspace actions must remain inside their owning cards so they do
     *     not create a separate empty row above the tree and HTML content.
     */
    public function testWorkspaceActionsStayInsideTreeAndEditorCards(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/WorkspaceController.php');

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString('workspace-tree-card-actions', $view);
        $this->assertStringNotContainsString('class="workspace-actions"', $view);
        $this->assertStringContainsString("'leadingActions'", $controller);
        $this->assertStringContainsString("'target' => '#workspace-page-tree'", $controller);
    }

    /**
     * HR: Akcije stabla moraju biti u zasebnom retku iznad punog naslova kako
     *     usko zaglavlje ne bi skraćivalo naziv područja.
     * EN: Tree actions must occupy a separate row above the complete title so
     *     a narrow header does not truncate the workspace name.
     */
    public function testTreeHeaderKeepsCompactActionsAboveCompleteTitle(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertLessThan(
            strpos($view, 'workspace-tree-title'),
            strpos($view, 'workspace-tree-card-actions'),
        );
        $this->assertStringContainsString('flex-direction: column', $css);
        $this->assertStringContainsString('white-space: normal', $css);
        $this->assertStringContainsString('.workspace-tree-card-action-count', $css);
    }

    /**
     * HR: Mobilno stablo mora koristiti plutajuću tematsku karticu s naslovom
     *     unutar kartice, jednako kao desni panel sadržaja.
     * EN: The mobile tree must use a floating themed card with its heading
     *     inside the card, matching the right-hand contents drawer.
     */
    public function testMobileTreeUsesFloatingThemedCard(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $shorts = file_get_contents(dirname(__DIR__) . '/views/workspace/shorts.php');
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($view);
        $this->assertIsString($shorts);
        $this->assertIsString($css);
        $this->assertStringContainsString('data-workspace-mobile-panel="tree"', $view);
        $this->assertStringContainsString('data-workspace-mobile-panel="tree"', $shorts);
        $this->assertLessThan(
            strpos($view, 'workspace-mobile-panel-header'),
            strpos($view, '<div class="card-body">'),
        );
        $this->assertStringContainsString('height: calc(100dvh - 1.5rem);', $css);
        $this->assertStringContainsString('overflow: visible;', $css);
    }

    /**
     * HR: Backlinkovi moraju pratiti isti responzivni stupac kao Editorov dokument.
     * EN: Backlinks must follow the same responsive column as the Editor document.
     */
    public function testBacklinksFollowEditorDocumentColumnWidth(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $script = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.js');

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertStringContainsString('workspace-backlinks-layout', $view);
        $this->assertStringContainsString('data-workspace-backlinks-column', $view);
        $this->assertStringContainsString('initializeBacklinkLayout', $script);
        $this->assertStringContainsString("'show.bs.collapse'", $script);
        $this->assertStringContainsString("'hidden.bs.collapse'", $script);
        $this->assertStringContainsString("classList.toggle('col-lg-9'", $script);
        $this->assertStringContainsString("classList.toggle('col-12'", $script);
    }

    /**
     * HR: Kućica u breadcrumbu mora biti poravnata s osnovnom linijom teksta.
     * EN: The breadcrumb home icon must align with the text baseline.
     */
    public function testBreadcrumbHomeIconUsesTextBaselineAlignment(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('vertical-align: -.125em;', $css);
        $this->assertStringNotContainsString('transform: translateY(-.04em);', $css);
    }

    /**
     * HR: Sažetci prikazuju putanju u zajedničkom retku alata, dok stablo i sadržaj
     *     zauzimaju isti sljedeći red responzivnog grida.
     * EN: Shorts render breadcrumbs in the shared toolbar row while the tree and
     *     content occupy the same following responsive-grid row.
     */
    public function testShortsBreadcrumbAndCardsUseAlignedGridRows(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/shorts.php');
        $controller = file_get_contents(
            dirname(__DIR__) . '/src/Controller/WorkspaceShortsController.php',
        );
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertIsString($css);
        $this->assertStringContainsString('workspace-shorts-breadcrumb-nav', $view);
        $this->assertStringContainsString('workspace-breadcrumb-home-icon', $view);
        $this->assertStringContainsString(
            "build(\$workspace, null, [], \$language, '', true)",
            $controller,
        );
        $this->assertStringContainsString("'breadcrumbs' => \$breadcrumbs", $controller);
        $this->assertStringContainsString('.workspace-shorts-shell > .workspace-sidebar', $css);
        $this->assertStringContainsString('.workspace-shorts-shell > .workspace-shorts-main', $css);
        $this->assertStringContainsString('grid-row: 2;', $css);
        $this->assertLessThan(
            strpos($view, 'id="workspace-page-tree"'),
            strpos($view, 'class="workspace-shorts-toolbar"'),
        );
    }

    /**
     * HR: Blok za soft-brisanje mora koristiti tematsku Bootstrap karticu kao i ostale postavke.
     * EN: The soft-delete block must use a theme-aware Bootstrap card like the other settings.
     */
    public function testWorkspaceDeleteBlockUsesThemeAwareCard(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/manage.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('id="workspace-delete-title"', $view);
        $this->assertStringContainsString('class="card mb-4"', $view);
        $this->assertStringNotContainsString('border border-danger rounded p-3', $view);
    }

    /**
     * HR: Obrazac nove stranice mora koristiti istu tematsku karticu kao glavni sadržaj.
     * EN: The new-page form must use the same theme-aware card as the main content.
     */
    public function testNewPageFormUsesThemeAwareContentCard(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');

        $this->assertIsString($view);
        $this->assertStringContainsString(
            'class="card shadow-sm hph-content-card workspace-page-create mb-4"',
            $view,
        );
        $this->assertStringNotContainsString(
            'workspace-page-create border rounded bg-body-tertiary',
            $view,
        );
        $this->assertStringContainsString('class="row g-3 align-items-start"', $view);
        $this->assertStringNotContainsString('class="row g-3 align-items-end"', $view);
    }

    /**
     * HR: Poruka o zabrani pristupa mora biti neprozirna tematska kartica, a ne prozirni okvir na hero pozadini.
     * EN: The access-denied message must be an opaque themed card, not a transparent border over the hero.
     */
    public function testAccessDeniedViewUsesThemeAwareContentCard(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/access-denied.php');
        $indexView = file_get_contents(dirname(__DIR__) . '/views/workspace/index.php');
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($view);
        $this->assertIsString($indexView);
        $this->assertIsString($css);
        $this->assertStringContainsString(
            'class="card shadow-sm hph-content-card workspace-access-denied"',
            $view,
        );
        $this->assertStringContainsString('class="card-body p-4"', $view);
        $this->assertStringContainsString('.workspace-access-denied,', $css);
        $this->assertStringContainsString('--hph-content-card-bg', $css);
        $this->assertStringContainsString(
            'class="card shadow-sm hph-content-card workspace-empty-state"',
            $indexView,
        );
        $this->assertStringNotContainsString(
            '<section class="border rounded p-4"',
            $view,
        );
        $this->assertStringNotContainsString(
            '<div class="border rounded p-4 text-body-secondary">',
            $indexView,
        );
    }

    /**
     * HR: Organizator i modalne postavke čvorova pripadaju prikazu Područja,
     *     dok ekran upravljanja zadržava samo podatke, članove i Workspace ACL.
     * EN: The organizer and modal node settings belong to the Workspace view,
     *     while the management screen retains data, members, and Workspace ACL.
     */
    public function testTreeManagementLivesInWorkspaceSidebar(): void
    {
        $workspaceView = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $manageView = file_get_contents(dirname(__DIR__) . '/views/workspace/manage.php');
        $workspaceScript = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.js');

        $this->assertIsString($workspaceView);
        $this->assertIsString($manageView);
        $this->assertIsString($workspaceScript);
        $this->assertStringContainsString('data-workspace-tree-edit-toggle', $workspaceView);
        $this->assertStringContainsString('data-workspace-tree-editor-url', $workspaceView);
        $this->assertStringContainsString('$treeOrganizerPath', $workspaceView);
        $this->assertStringContainsString('data-workspace-lazy-modal', $workspaceScript);
        $this->assertStringContainsString('data-workspace-node-editor-modal', $workspaceView);
        $this->assertStringContainsString('initializeModalPortals', $workspaceScript);
        $this->assertStringContainsString(
            "document.querySelectorAll('.workspace-shell ~ .modal, .workspace-shell .modal')",
            $workspaceScript,
        );
        $this->assertStringContainsString('document.body.append(modal)', $workspaceScript);
        $this->assertStringNotContainsString('data-workspace-tree-order-form', $manageView);
        $this->assertStringNotContainsString("'workspace/node-fields'", $manageView);
    }

    /** HR: Pregled stabla ima sklopive grane, a organizator ostaje odvojen i potpuno otvoren. EN: The tree view has collapsible branches while the organizer remains separate and fully visible. */
    public function testReadOnlyTreeHasBranchControlsWithoutAffectingOrganizer(): void
    {
        $tree = file_get_contents(dirname(__DIR__) . '/views/workspace/tree.php');
        $organizer = file_get_contents(dirname(__DIR__) . '/views/workspace/tree-organizer.php');
        $workspaceView = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $shortsView = file_get_contents(dirname(__DIR__) . '/views/workspace/shorts.php');
        $javascript = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.js');
        $css = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($tree);
        $this->assertIsString($organizer);
        $this->assertIsString($workspaceView);
        $this->assertIsString($shortsView);
        $this->assertIsString($javascript);
        $this->assertIsString($css);
        $this->assertStringContainsString('data-workspace-tree-branch-toggle', $tree);
        $this->assertStringContainsString('data-workspace-tree-branch', $tree);
        $this->assertStringContainsString('data-workspace-tree-branch-url', $tree);
        $this->assertStringContainsString('loadBranch', $javascript);
        $this->assertStringContainsString('window.fetch', $javascript);
        $this->assertStringContainsString('$level === 1 || $containsActiveChild', $tree);
        $this->assertStringContainsString('aria-current="page"', $tree);
        $this->assertStringNotContainsString('data-workspace-tree-branch-toggle', $organizer);
        $this->assertStringContainsString('initializeReadableTrees', $javascript);
        $this->assertStringContainsString('containsActivePage', $javascript);
        $this->assertStringContainsString('data-workspace-tree-key', $workspaceView);
        $this->assertStringContainsString('data-workspace-tree-key', $shortsView);
        $this->assertStringContainsString('initializeAdaptiveTreeWidths', $javascript);
        $this->assertStringContainsString('--workspace-tree-max-width: 22rem', $css);
        $this->assertStringContainsString('text-wrap: pretty', $css);
        $this->assertStringContainsString('workspaceTreeKey', $javascript);
        $this->assertStringContainsString('window.sessionStorage', $javascript);
        $this->assertStringContainsString('persistExpandedBranches', $javascript);
        $this->assertStringContainsString('heartphrame.workspace.tree.scroll.v1', $javascript);
        $this->assertStringContainsString('persistTreeScrollPosition', $javascript);
        $this->assertStringContainsString('restoreTreeState', $javascript);
        $this->assertStringContainsString("window.addEventListener('pagehide'", $javascript);
        $this->assertStringContainsString('border: 0;', $css);
        $this->assertStringContainsString('padding: .32rem .35rem;', $css);
    }

    /**
     * HR: Stablo mora jasno označiti nove neobjavljene stranice, dok izdavači
     *     dobivaju zaseban popis sadržaja poslanog na pregled.
     * EN: The tree must clearly mark new unpublished pages, while publishers
     *     receive a separate queue of content submitted for review.
     */
    public function testWorkspaceExposesUnpublishedAndReviewQueues(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/workspace/show.php');
        $tree = file_get_contents(dirname(__DIR__) . '/views/workspace/tree.php');
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/WorkspaceController.php');

        $this->assertIsString($view);
        $this->assertIsString($tree);
        $this->assertIsString($controller);
        $this->assertStringContainsString('workspace-unpublished-pages-modal', $view);
        $this->assertStringContainsString('workspace-review-queue-modal', $view);
        $this->assertStringContainsString('$unpublishedPages', $controller);
        $this->assertStringContainsString('$reviewQueue', $controller);
        $this->assertStringContainsString('workspace-tree-status', $tree);
    }

    /**
     * HR: Obični pregled objave smije nuditi samo ulaz u nacrt, dok se akcije
     *     koje mijenjaju nacrt prikazuju na njegovu pregledu ili novoj stranici.
     * EN: A regular published view may only offer entry into the draft, while
     *     draft-mutating actions appear on its preview or on a new page.
     */
    public function testDraftMutationsRequireDraftPreviewOrNewUnpublishedPage(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/WorkspaceController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString(
            '$showDraftMutations = $isDraftPreview || $isNewUnpublishedPage;',
            $controller,
        );
        $this->assertStringContainsString('$hasDraft && !$showDraftMutations', $controller);
        $this->assertStringContainsString(
            "(bool)(\$editorView['isDraftPreview'] ?? false)",
            $controller,
        );
        $this->assertStringContainsString(
            "\$editorView['documentLanguage'] ?? \$language",
            $controller,
        );
        $this->assertStringContainsString(
            '$this->workflow->viewModel(',
            $controller,
        );
        $this->assertStringContainsString('$contentLanguage,', $controller);
    }

    /**
     * HR: Odbacivanje nikad objavljene stranice mora trajno ukloniti Editor
     *     dokument i Workspace čvor umjesto da ih stavi među soft-obrisane.
     * EN: Discarding a never-published page must permanently remove the Editor
     *     document and Workspace node instead of soft-deleting them.
     */
    public function testDiscardingNewPagePermanentlyDeletesDocumentAndNode(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/WorkspaceController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString(
            "\$workflowBeforeDiscard['is_new_unpublished_page']",
            $controller,
        );
        $this->assertStringContainsString(
            '$this->editor->deleteUnpublishedDocumentPermanently($documentKey);',
            $controller,
        );
        $this->assertStringContainsString(
            '$this->repository->deleteUnpublishedNodePermanently(',
            $controller,
        );
    }
}
