<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Dvostupanjski pretraživi odabir područja i njegove naslovne stranice.
 * EN: Two-step searchable selection of a Workspace and its homepage.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $selectorKey
 * @var string $selectorLegend
 * @var string $selectorTargetName
 * @var string $selectorTargetValue
 * @var list<array{name:string,options:list<array<string,mixed>>}> $selectorGroups
 * @var string $selectorSpecialWorkspaceValue
 * @var string $selectorSpecialWorkspaceLabel
 * @var string $selectorSpecialPageLabel
 */

$selectorWorkspaceValue = $selectorSpecialWorkspaceValue;
$selectorWorkspaceLabel = $selectorSpecialWorkspaceLabel;
$selectorPageLabel = $selectorSpecialPageLabel;
foreach ($selectorGroups as $group) {
    foreach ($group['options'] as $option) {
        if (WorkspaceValue::string($option['value'] ?? '') !== $selectorTargetValue) {
            continue;
        }

        $selectorWorkspaceValue = 'workspace:' . WorkspaceValue::int(
            $option['workspace_id'] ?? 0,
        );
        $selectorWorkspaceLabel = WorkspaceValue::string($group['name'] ?? '');
        $selectorPageLabel = WorkspaceValue::string($option['title'] ?? '');
        break 2;
    }
}
?>
<fieldset
    class="workspace-homepage-selector"
    data-workspace-homepage-selector="<?= $this->escape($selectorKey) ?>"
    data-workspace-homepage-special-workspace="<?= $this->escape(
        $selectorSpecialWorkspaceValue,
    ) ?>"
    data-workspace-homepage-special-workspace-label="<?= $this->escape(
        $selectorSpecialWorkspaceLabel,
    ) ?>"
    data-workspace-homepage-special-page-label="<?= $this->escape($selectorSpecialPageLabel) ?>"
    data-workspace-homepage-selected-workspace="<?= $this->escape($selectorWorkspaceValue) ?>"
    data-workspace-homepage-all-label="<?= $this->escape(__('Sva područja')) ?>"
    data-workspace-homepage-page-placeholder="<?= $this->escape(__('Odaberite stranicu')) ?>"
    data-workspace-homepage-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
>
    <legend class="form-label fw-semibold mb-3"><?= $this->escape($selectorLegend) ?></legend>
    <div class="mb-3">
        <label class="form-label" for="workspace-<?= $this->escape($selectorKey) ?>-workspace">
            <?= $this->escape(__('Područje')) ?>
        </label>
        <div
            class="workspace-homepage-picker"
            data-workspace-homepage-picker="workspace"
        >
            <button
                id="workspace-<?= $this->escape($selectorKey) ?>-workspace"
                class="form-select workspace-homepage-picker-toggle"
                type="button"
                aria-haspopup="listbox"
                aria-expanded="false"
                aria-controls="workspace-<?= $this->escape($selectorKey) ?>-workspace-options"
                data-workspace-homepage-picker-toggle
            >
                <span data-workspace-homepage-picker-label>
                    <?= $this->escape($selectorWorkspaceLabel) ?>
                </span>
            </button>
            <div class="workspace-homepage-picker-menu" data-workspace-homepage-picker-menu hidden>
                <label
                    class="visually-hidden"
                    for="workspace-<?= $this->escape($selectorKey) ?>-workspace-search"
                >
                    <?= $this->escape(__('Pretraži područja')) ?>
                </label>
                <input
                    id="workspace-<?= $this->escape($selectorKey) ?>-workspace-search"
                    class="form-control workspace-homepage-picker-search"
                    type="search"
                    autocomplete="off"
                    placeholder="<?= $this->escape(__('Pretraži područja')) ?>"
                    aria-controls="workspace-<?= $this->escape($selectorKey) ?>-workspace-options"
                    data-workspace-homepage-picker-search
                >
                <div
                    id="workspace-<?= $this->escape($selectorKey) ?>-workspace-options"
                    class="workspace-homepage-picker-options list-group list-group-flush"
                    role="listbox"
                    data-workspace-homepage-picker-options
                ></div>
            </div>
        </div>
    </div>
    <div>
        <label class="form-label" for="workspace-<?= $this->escape($selectorKey) ?>-page">
            <?= $this->escape(__('Stranica')) ?>
        </label>
        <div
            class="workspace-homepage-picker"
            data-workspace-homepage-picker="page"
        >
            <button
                id="workspace-<?= $this->escape($selectorKey) ?>-page"
                class="form-select workspace-homepage-picker-toggle"
                type="button"
                aria-haspopup="listbox"
                aria-expanded="false"
                aria-controls="workspace-<?= $this->escape($selectorKey) ?>-page-options"
                data-workspace-homepage-picker-toggle
            >
                <span data-workspace-homepage-picker-label>
                    <?= $this->escape($selectorPageLabel) ?>
                </span>
            </button>
            <div class="workspace-homepage-picker-menu" data-workspace-homepage-picker-menu hidden>
                <label
                    class="visually-hidden"
                    for="workspace-<?= $this->escape($selectorKey) ?>-page-search"
                >
                    <?= $this->escape(__('Pretraži stranice')) ?>
                </label>
                <input
                    id="workspace-<?= $this->escape($selectorKey) ?>-page-search"
                    class="form-control workspace-homepage-picker-search"
                    type="search"
                    autocomplete="off"
                    placeholder="<?= $this->escape(__('Pretraži stranice')) ?>"
                    aria-controls="workspace-<?= $this->escape($selectorKey) ?>-page-options"
                    data-workspace-homepage-picker-search
                >
                <div
                    id="workspace-<?= $this->escape($selectorKey) ?>-page-options"
                    class="workspace-homepage-picker-options list-group list-group-flush"
                    role="listbox"
                    data-workspace-homepage-picker-options
                ></div>
            </div>
        </div>
        <div
            id="workspace-<?= $this->escape($selectorKey) ?>-page-required"
            class="form-text text-danger"
            data-workspace-homepage-page-required
            hidden
        >
            <?= $this->escape(__('Odaberite stranicu.')) ?>
        </div>
    </div>
    <input
        id="workspace-<?= $this->escape($selectorKey) ?>-homepage"
        type="hidden"
        name="<?= $this->escape($selectorTargetName) ?>"
        value="<?= $this->escape($selectorTargetValue) ?>"
        data-workspace-homepage-target="<?= $this->escape($selectorKey) ?>"
    >
    <script type="application/json" data-workspace-homepage-options><?= json_encode(
        $selectorGroups,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
</fieldset>
