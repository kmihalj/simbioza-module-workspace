<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.WhiteSpace.ScopeIndent

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $workspace
 * @var list<array{label:string,href:string,current:bool,icon?:string}> $breadcrumbs
 * @var list<array<string, mixed>> $tree
 * @var list<array{title:string,html:string,published_at:string,href:string}> $articles
 * @var int $depth
 * @var string $limit
 * @var string $order
 * @var int $total
 * @var bool $allAvailable
 * @var string $language
 * @var string $shortsPath
 * @var bool $treeVisibleByDefault
 * @var bool $displayOptionsVisibleByDefault
 * @var string $assetsCssPath
 * @var string $assetsJsPath
 */
$orderOptions = [
    'hierarchy' => __('Prema hijerarhiji'),
    'newest' => __('Najnovije prvo'),
    'oldest' => __('Najstarije prvo'),
];
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>

<div class="workspace-shell workspace-shorts-shell">
    <div class="workspace-shorts-toolbar" aria-label="<?= $this->escape(__('Opcije prikaza')) ?>">
        <?php if ($breadcrumbs !== []) : ?>
            <nav
                class="workspace-shorts-breadcrumb-nav"
                aria-label="<?= $this->escape(__('Breadcrumb navigacija')) ?>"
            >
                <ol class="breadcrumb workspace-breadcrumb">
                    <?php foreach ($breadcrumbs as $breadcrumb) : ?>
                        <?php if ((bool)($breadcrumb['current'] ?? false)) : ?>
                            <li class="breadcrumb-item active" aria-current="page">
                                <?= $this->escape(WorkspaceValue::string($breadcrumb['label'] ?? '')) ?>
                            </li>
                        <?php else : ?>
                            <li class="breadcrumb-item">
                                <?php
                                $breadcrumbLabel = WorkspaceValue::string($breadcrumb['label'] ?? '');
                                $breadcrumbIcon = WorkspaceValue::string($breadcrumb['icon'] ?? '');
                                ?>
                                <a
                                    href="<?= $this->escape(
                                        WorkspaceValue::string($breadcrumb['href'] ?? ''),
                                    ) ?>"
                                    <?php if ($breadcrumbIcon === 'home') : ?>
                                        class="workspace-breadcrumb-home-link"
                                        aria-label="<?= $this->escape($breadcrumbLabel) ?>"
                                        title="<?= $this->escape($breadcrumbLabel) ?>"
                                    <?php endif; ?>
                                >
                                    <?php if ($breadcrumbIcon === 'home') : ?>
                                        <svg
                                            class="workspace-breadcrumb-home-icon"
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                            focusable="false"
                                        >
                                            <path d="M3 11.5 12 4l9 7.5" />
                                            <path d="M5.5 10.5V20h13v-9.5" />
                                            <path d="M9.5 20v-6h5v6" />
                                        </svg>
                                        <span class="visually-hidden">
                                            <?= $this->escape($breadcrumbLabel) ?>
                                        </span>
                                    <?php else : ?>
                                        <?= $this->escape($breadcrumbLabel) ?>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <div class="workspace-shorts-toolbar-actions d-flex flex-wrap gap-2">
            <button
                class="btn btn-sm workspace-shorts-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#workspace-page-tree"
                aria-controls="workspace-page-tree"
                aria-expanded="<?= $treeVisibleByDefault ? 'true' : 'false' ?>"
                aria-label="<?= $this->escape(__('Stablo stranica')) ?>"
                title="<?= $this->escape(__('Stablo stranica')) ?>"
            >
                <svg
                    class="workspace-shorts-toggle-icon"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path d="M10 3H5a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z"/>
                    <path d="M19 14h-5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Z"/>
                    <path d="M7 11v2a3 3 0 0 0 3 3h2"/>
                </svg>
            </button>
            <button
                class="btn btn-sm workspace-shorts-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#workspace-shorts-display-options"
                aria-controls="workspace-shorts-display-options"
                aria-expanded="<?= $displayOptionsVisibleByDefault ? 'true' : 'false' ?>"
                aria-label="<?= $this->escape(__('Opcije prikaza')) ?>"
                title="<?= $this->escape(__('Opcije prikaza')) ?>"
            >
                <svg
                    class="workspace-shorts-toggle-icon"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path d="M4 6h5M13 6h7M4 12h9M17 12h3M4 18h3M11 18h9"/>
                    <path d="M9 4v4M13 10v4M7 16v4"/>
                </svg>
            </button>
        </div>
    </div>

    <aside
        id="workspace-page-tree"
        class="workspace-sidebar collapse<?= $treeVisibleByDefault ? ' show' : '' ?>"
        data-workspace-mobile-panel="tree"
        aria-label="<?= $this->escape(__('Stablo stranica')) ?>"
    >
        <nav class="card shadow-sm workspace-tree-card hph-sidebar-card">
            <div class="card-body">
                <div class="workspace-mobile-panel-header">
                    <h2 class="h6 mb-0"><?= $this->escape(__('Stablo stranica')) ?></h2>
                    <button
                        class="workspace-mobile-panel-close"
                        type="button"
                        data-workspace-mobile-panel-close="tree"
                        aria-label="<?= $this->escape(__('Zatvori')) ?>"
                    >&times;</button>
                </div>
                <div class="workspace-tree-heading mb-3">
                    <div class="workspace-tree-card-actions">
                        <a
                            class="btn btn-primary btn-sm workspace-tree-card-action"
                            href="<?= $this->escape($shortsPath) ?>"
                            title="<?= $this->escape(__('Sažetci')) ?>"
                            aria-label="<?= $this->escape(__('Sažetci')) ?>"
                            aria-current="page"
                        >
                            <svg
                                class="workspace-tree-card-action-icon"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path d="M4 4h16v16H4z"/>
                                <path d="M8 8h8M8 12h8M8 16h5"/>
                            </svg>
                        </a>
                    </div>
                    <h2 class="h6 text-uppercase text-muted mb-0 workspace-tree-title">
                        <?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?>
                    </h2>
                </div>
                <div
                    class="list-group list-group-flush workspace-tree"
                    data-workspace-tree-view
                    data-workspace-tree-key="<?= WorkspaceValue::int($workspace['id'] ?? 0) ?>"
                >
                    <?php if ($tree === []) : ?>
                        <p class="small text-body-secondary mb-0">
                            <?= $this->escape(__('Stablo je prazno.')) ?>
                        </p>
                    <?php else : ?>
                        <?= $this->forModulePartial(
                            'aaieduhr/heartphrame-module-workspace',
                            'workspace/tree',
                            ['nodes' => $tree, 'activeNodeId' => null, 'level' => 1],
                        ) ?>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </aside>

    <button
        class="workspace-mobile-edge-toggle workspace-mobile-edge-toggle--start"
        type="button"
        data-workspace-mobile-panel-open="tree"
        aria-controls="workspace-page-tree"
        aria-expanded="false"
        title="<?= $this->escape(__('Stablo stranica')) ?>"
        aria-label="<?= $this->escape(__('Stablo stranica')) ?>"
    >
        <svg class="workspace-tree-card-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M10 3H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
            <path d="M19 14h-5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2z"/>
            <path d="M7 10v2a2 2 0 0 0 2 2h5"/>
        </svg>
    </button>
    <div class="workspace-mobile-panel-backdrop" data-workspace-mobile-panel-backdrop hidden></div>

    <main class="workspace-main workspace-shorts-main">
        <div
            id="workspace-shorts-display-options"
            class="collapse<?= $displayOptionsVisibleByDefault ? ' show' : '' ?>"
        >
            <form class="card card-body workspace-shorts-filters mb-4" method="get" action="<?= $this->escape(
                $shortsPath,
            ) ?>">
                <input type="hidden" name="lang" value="<?= $this->escape($language) ?>">
                <input
                    type="hidden"
                    name="tree"
                    value="<?= $treeVisibleByDefault ? '1' : '0' ?>"
                    data-workspace-visibility-for="workspace-page-tree"
                >
                <input
                    type="hidden"
                    name="options"
                    value="<?= $displayOptionsVisibleByDefault ? '1' : '0' ?>"
                    data-workspace-visibility-for="workspace-shorts-display-options"
                >
                <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="workspace-shorts-depth">
                        <?= $this->escape(__('Prikazane razine')) ?>
                    </label>
                    <select id="workspace-shorts-depth" class="form-select" name="depth">
                        <?php foreach ([1, 2, 3] as $option) : ?>
                            <option value="<?= $option ?>" <?= $depth === $option ? 'selected' : '' ?>>
                                <?= $this->escape($option === 1
                                    ? __('Samo 1. razina')
                                    : __('Razine 1–') . $option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="workspace-shorts-limit">
                        <?= $this->escape(__('Broj članaka')) ?>
                    </label>
                    <select id="workspace-shorts-limit" class="form-select" name="limit">
                        <?php foreach (['5', '10', '25', '50'] as $option) : ?>
                            <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>>
                                <?= $option ?>
                            </option>
                        <?php endforeach; ?>
                        <option
                            value="all"
                            <?= $limit === 'all' ? 'selected' : '' ?>
                            <?= $allAvailable ? '' : 'disabled' ?>
                        ><?= $this->escape(__('Sve')) ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="workspace-shorts-order">
                        <?= $this->escape(__('Redoslijed')) ?>
                    </label>
                    <select id="workspace-shorts-order" class="form-select" name="order">
                        <?php foreach ($orderOptions as $value => $label) : ?>
                            <option value="<?= $value ?>" <?= $order === $value ? 'selected' : '' ?>>
                                <?= $this->escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <?= $this->escape(__('Prikaži')) ?>
                    </button>
                </div>
                </div>
                <p class="small text-body-secondary mt-3 mb-0">
                    <?= $this->escape(__('Dostupnih članaka:')) ?> <?= $total ?>.
                    <?php if (!$allAvailable) : ?>
                        <?= $this->escape(__('Opcija „Sve” isključena je za 100 ili više članaka.')) ?>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <?php if ($articles === []) : ?>
            <div class="alert alert-info">
                <?= $this->escape(
                    __('U odabranim razinama nema objavljenih stranica koje smijete vidjeti.'),
                ) ?>
            </div>
        <?php else : ?>
            <div class="workspace-shorts-list">
                <?php foreach ($articles as $article) : ?>
                    <?php $publishedAt = WorkspaceValue::string($article['published_at'] ?? ''); ?>
                    <article class="card workspace-short-card shadow-sm">
                        <div class="card-body">
                            <h2 class="h4 mb-1">
                                <a href="<?= $this->escape(WorkspaceValue::string(
                                    $article['href'] ?? '#',
                                )) ?>">
                                    <?= $this->escape(WorkspaceValue::string($article['title'] ?? '')) ?>
                                </a>
                            </h2>
                            <?php if ($publishedAt !== '') : ?>
                                <time class="small text-body-secondary" datetime="<?= $this->escape(
                                    $publishedAt,
                                ) ?>">
                                    <?= $this->escape(date('d. m. Y.', strtotime($publishedAt) ?: 0)) ?>
                                </time>
                            <?php endif; ?>
                            <div class="workspace-short-excerpt mt-3">
                                <?php
                                /*
                                 * HR: HTML je točno objavljena verzija koju je Editor već sanitizirao.
                                 * EN: HTML is the exact published version already sanitized by the Editor.
                                 */
                                ?>
                                <?= WorkspaceValue::string($article['html'] ?? '') ?>
                            </div>
                            <div class="mt-3">
                                <a class="fw-semibold" href="<?= $this->escape(WorkspaceValue::string(
                                    $article['href'] ?? '#',
                                )) ?>">
                                    <?= $this->escape(__('Pročitaj više')) ?>
                                    <span aria-hidden="true">→</span>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
    (() => {
        /*
         * HR: Filter pamti stvarno, trenutačno stanje sklopivog stabla i opcija.
         * EN: The filter preserves the actual current state of the collapsible tree and options.
         */
        document.querySelectorAll('[data-workspace-visibility-for]').forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            const target = document.getElementById(input.dataset.workspaceVisibilityFor || '');
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const synchronize = () => {
                input.value = target.classList.contains('show') ? '1' : '0';
            };

            target.addEventListener('shown.bs.collapse', synchronize);
            target.addEventListener('hidden.bs.collapse', synchronize);
        });
    })();
</script>
