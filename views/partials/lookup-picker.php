<?php

declare(strict_types=1);

/**
 * HR: Zajednički udaljeni birač područja ili stranice.
 * EN: Shared remote Workspace or page picker.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $workspaceLookupKind
 * @var string $workspaceLookupName
 * @var string $workspaceLookupValue
 * @var string $workspaceLookupLabel
 * @var string $workspaceLookupEndpoint
 * @var string|null $workspaceLookupWorkspaceSelector
 * @var bool|null $workspaceLookupIncludeAll
 * @var string|null $workspaceLookupAllLabel
 * @var string|null $workspaceLookupAllValue
 * @var bool|null $workspaceLookupRequired
 * @var string|null $workspaceLookupValueMode
 * @var bool|null $workspaceLookupPublishedOnly
 * @var bool|null $workspaceLookupIncludeShorts
 * @var string|null $workspaceLookupTargetKey
 * @var bool|null $workspaceLookupIncludeContainers
 * @var bool|null $workspaceLookupRequireCanAdd
 * @var int|null $workspaceLookupExcludeNodeId
 * @var int|null $workspaceLookupFixedWorkspaceId
 * @var bool|null $workspaceLookupAllDisabled
 */

$workspaceLookupAudience = isset($workspaceLookupAudience) && is_string($workspaceLookupAudience)
? trim($workspaceLookupAudience)
: 'current';
$workspaceLookupWorkspaceSelector = trim((string)($workspaceLookupWorkspaceSelector ?? ''));
$workspaceLookupIncludeAll = (bool)($workspaceLookupIncludeAll ?? false);
$workspaceLookupAllLabel = trim((string)($workspaceLookupAllLabel ?? ''));
$workspaceLookupAllValue = trim((string)($workspaceLookupAllValue ?? ''));
$workspaceLookupRequired = (bool)($workspaceLookupRequired ?? false);
$workspaceLookupValueMode = trim((string)($workspaceLookupValueMode ?? 'id'));
$workspaceLookupPublishedOnly = (bool)($workspaceLookupPublishedOnly ?? false);
$workspaceLookupIncludeShorts = (bool)($workspaceLookupIncludeShorts ?? false);
$workspaceLookupTargetKey = trim((string)($workspaceLookupTargetKey ?? ''));
$workspaceLookupIncludeContainers = (bool)($workspaceLookupIncludeContainers ?? false);
$workspaceLookupRequireCanAdd = (bool)($workspaceLookupRequireCanAdd ?? false);
$workspaceLookupExcludeNodeId = max(0, (int)($workspaceLookupExcludeNodeId ?? 0));
$workspaceLookupFixedWorkspaceId = max(0, (int)($workspaceLookupFixedWorkspaceId ?? 0));
$workspaceLookupAllDisabled = (bool)($workspaceLookupAllDisabled ?? false);
$workspaceLookupPlaceholder = $workspaceLookupKind === 'workspace'
? __('Odaberite područje')
: __('Odaberite stranicu');
?>
<div
    class="dropdown workspace-lookup-picker"
    data-workspace-lookup-picker="<?= $this->escape($workspaceLookupKind) ?>"
    data-workspace-lookup-endpoint="<?= $this->escape($workspaceLookupEndpoint) ?>"
    data-workspace-lookup-audience="<?= $this->escape($workspaceLookupAudience) ?>"
    data-workspace-lookup-workspace-selector="<?= $this->escape($workspaceLookupWorkspaceSelector) ?>"
    data-workspace-lookup-all-label="<?= $this->escape($workspaceLookupIncludeAll ? $workspaceLookupAllLabel : '') ?>"
    data-workspace-lookup-all-value="<?= $this->escape($workspaceLookupIncludeAll ? $workspaceLookupAllValue : '') ?>"
    data-workspace-lookup-all-disabled="<?= $workspaceLookupAllDisabled ? '1' : '0' ?>"
    data-workspace-lookup-value-mode="<?= $this->escape($workspaceLookupValueMode) ?>"
    data-workspace-lookup-published="<?= $workspaceLookupPublishedOnly ? '1' : '0' ?>"
    data-workspace-lookup-include-shorts="<?= $workspaceLookupIncludeShorts ? '1' : '0' ?>"
    data-workspace-lookup-include-containers="<?= $workspaceLookupIncludeContainers ? '1' : '0' ?>"
    data-workspace-lookup-require-can-add="<?= $workspaceLookupRequireCanAdd ? '1' : '0' ?>"
    data-workspace-lookup-exclude-node-id="<?= $workspaceLookupExcludeNodeId ?>"
    data-workspace-lookup-fixed-workspace-id="<?= $workspaceLookupFixedWorkspaceId ?>"
    data-workspace-lookup-all-global-only="<?= $workspaceLookupKind === 'page' && $workspaceLookupTargetKey !== ''
    ? '1'
    : '0' ?>"
>
    <input
        type="hidden"
        <?= $workspaceLookupName !== '' ? 'name="' . $this->escape($workspaceLookupName) . '"' : '' ?>
        value="<?= $this->escape($workspaceLookupValue) ?>"
        <?= $workspaceLookupRequired ? 'required' : '' ?>
        data-workspace-lookup-value
        <?= $workspaceLookupTargetKey !== ''
        ? 'data-workspace-homepage-target="' . $this->escape($workspaceLookupTargetKey) . '"'
        : '' ?>
    >
    <button
        class="form-select text-start"
        type="button"
        data-bs-toggle="dropdown"
        data-bs-auto-close="outside"
        data-bs-boundary="viewport"
        aria-expanded="false"
        data-workspace-lookup-toggle
        data-workspace-lookup-placeholder="<?= $this->escape($workspaceLookupPlaceholder) ?>"
    ><?= $this->escape($workspaceLookupLabel !== '' ? $workspaceLookupLabel : $workspaceLookupPlaceholder) ?></button>
    <div class="dropdown-menu p-3 shadow workspace-lookup-menu">
        <input
            class="form-control form-control-sm mb-2"
            type="search"
            autocomplete="off"
            placeholder="<?= $this->escape(
                $workspaceLookupKind === 'workspace' ? __('Pretraži područja') : __('Pretraži stranice'),
            ) ?>"
            data-workspace-lookup-search
        >
        <div class="small text-body-secondary mb-2" data-workspace-lookup-loading hidden>
            <?= $this->escape(__('Učitavanje...')) ?>
        </div>
        <div class="alert alert-danger py-2" data-workspace-lookup-error hidden></div>
        <div class="list-group list-group-flush workspace-lookup-list" data-workspace-lookup-list></div>
        <div class="small text-body-secondary mt-2" data-workspace-lookup-empty hidden>
            <?= $this->escape(__('Nema rezultata.')) ?>
        </div>
        <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-workspace-lookup-more hidden>
            <?= $this->escape(__('Učitaj još')) ?>
        </button>
    </div>
</div>
