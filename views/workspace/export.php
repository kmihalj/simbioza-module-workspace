<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- Translation keys remain literal for catalogue auditing.

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string, mixed> $workspace
 * @var list<array{id:int,title:string,depth:int,is_homepage:bool}> $pageOptions
 * @var array<string, string> $languageLabels
 * @var string $downloadPath
 * @var string $managePath
 * @var string $assetsCssPath
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$workspaceName = WorkspaceValue::string($workspace['name'] ?? '');
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">

<div class="container-fluid hph-container-wide py-4">
    <section class="card hph-content-card">
        <div class="card-body p-4">
            <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0"><?= $this->escape($workspaceName) ?></p>
                </div>
                <a class="btn btn-outline-secondary" href="<?= $this->escape($managePath) ?>">
                    <?= $this->escape(__('Natrag na upravljanje područjem')) ?>
                </a>
            </header>

            <div class="alert alert-info">
                <h2 class="h6"><?= $this->escape(__('Što sadrži HTML izvoz?')) ?></h2>
                <p class="mb-2">
                    <?= $this->escape(
                        __('ZIP sadrži samostalne HTML stranice, trenutačnu temu, filtrirano stablo, privitke i statičke snimke kalendara i zadataka.'),
                    ) ?>
                </p>
                <p class="mb-0">
                    <?= $this->escape(
                        __('Izvoze se samo objavljene stranice koje smijete vidjeti. Offline paket ne sadrži aktivne prijave, API pozive ni mogućnost uređivanja.'),
                    ) ?>
                </p>
            </div>

            <dl class="row small mb-4">
                <dt class="col-sm-3"><?= $this->escape(__('Naslov offline aplikacije')) ?></dt>
                <dd class="col-sm-9">Simbioza - <?= $this->escape($workspaceName) ?></dd>
                <dt class="col-sm-3"><?= $this->escape(__('Jezici')) ?></dt>
                <dd class="col-sm-9"><?= $this->escape(implode(', ', array_values($languageLabels))) ?></dd>
                <dt class="col-sm-3"><?= $this->escape(__('Početni prikaz')) ?></dt>
                <dd class="col-sm-9 mb-0">
                    <?= $this->escape(
                        __('Stablo, sadržaj dokumenta i privitci slijede trenutačne postavke aplikacije te se u izvozu mogu neovisno pokazati ili sakriti.'),
                    ) ?>
                </dd>
            </dl>

            <form method="post" action="<?= $this->escape($downloadPath) ?>" data-workspace-export-form>
                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">

                <fieldset class="mb-4">
                    <legend class="h5"><?= $this->escape(__('Opseg izvoza')) ?></legend>
                    <div class="form-check mb-2">
                        <input
                            class="form-check-input"
                            id="workspace-export-all"
                            type="radio"
                            name="scope"
                            value="all"
                            checked
                            data-workspace-export-scope
                        >
                        <label class="form-check-label" for="workspace-export-all">
                            <strong><?= $this->escape(__('Cijelo područje')) ?></strong><br>
                            <span class="text-body-secondary">
                                <?= $this->escape(__('Sve objavljene i ACL-vidljive stranice područja.')) ?>
                            </span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            id="workspace-export-selected"
                            type="radio"
                            name="scope"
                            value="selected"
                            data-workspace-export-scope
                        >
                        <label class="form-check-label" for="workspace-export-selected">
                            <strong><?= $this->escape(__('Odabrane stranice')) ?></strong><br>
                            <span class="text-body-secondary">
                                <?= $this->escape(__('Stablo u ZIP-u sadržavat će samo odabrane izvezene stranice.')) ?>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="border rounded p-3 mb-4" data-workspace-export-selection disabled>
                    <legend class="float-none w-auto px-2 h6 mb-0">
                        <?= $this->escape(__('Stranice za izvoz')) ?>
                    </legend>
                    <?php if ($pageOptions === []) : ?>
                        <p class="text-body-secondary mb-0">
                        <?= $this->escape(__('Nema objavljenih stranica koje smijete izvesti.')) ?>
                        </p>
                    <?php else : ?>
                        <div class="vstack gap-1">
                        <?php foreach ($pageOptions as $page) : ?>
                            <?php
                            $pageId = WorkspaceValue::int($page['id'] ?? 0);
                            $depth = WorkspaceValue::int($page['depth'] ?? 0);
                            ?>
                                <div
                                    class="form-check"
                                    style="padding-inline-start: calc(1.5em + <?= $depth ?> * 1.25rem)"
                                >
                                    <input
                                        class="form-check-input"
                                        id="workspace-export-page-<?= $pageId ?>"
                                        type="checkbox"
                                        name="node_ids[]"
                                        value="<?= $pageId ?>"
                                        checked
                                    >
                                    <label class="form-check-label" for="workspace-export-page-<?= $pageId ?>">
                            <?= $this->escape(WorkspaceValue::string($page['title'] ?? '')) ?>
                            <?php if ((bool)($page['is_homepage'] ?? false)) : ?>
                                            <span class="badge text-bg-secondary ms-1">
                                <?= $this->escape(__('Početna')) ?>
                                            </span>
                            <?php endif; ?>
                                    </label>
                                </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <a class="btn btn-secondary" href="<?= $this->escape($managePath) ?>">
                        <?= $this->escape(__('Odustani')) ?>
                    </a>
                    <button class="btn btn-primary" type="submit"<?= $pageOptions === [] ? ' disabled' : '' ?>>
                        <?= $this->escape(__('Izvezi područje u HTML')) ?>
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>

<script>
    /*
     * HR: Checkboxe uključujemo samo za odabrani opseg kako skrivena polja ne bi
     *     slučajno promijenila značenje izvoza cijelog područja.
     * EN: Checkboxes are enabled only for selected scope so hidden fields cannot
     *     accidentally alter the meaning of a complete-Workspace export.
     */
    (function () {
        var form = document.querySelector('[data-workspace-export-form]');
        var selection = document.querySelector('[data-workspace-export-selection]');
        if (!form || !selection) {
            return;
        }

        function updateSelection() {
            var selected = form.querySelector('[data-workspace-export-scope]:checked');
            selection.disabled = !selected || selected.value !== 'selected';
        }

        form.querySelectorAll('[data-workspace-export-scope]').forEach(function (radio) {
            radio.addEventListener('change', updateSelection);
        });
        updateSelection();
    }());
</script>
