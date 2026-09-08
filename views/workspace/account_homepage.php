<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Osobna Workspace naslovnica prikazana kroz modularni Auth profil.
 * EN: Personal Workspace homepage displayed through the modular Auth profile.
 *
 * @var \HeartPhrame\View\View $this
 * @var int $selectedNodeId
 * @var array<string, mixed> $selectedTarget
 * @var string $selectedTargetValue
 * @var bool $selectionUnavailable
 * @var list<array{name:string,options:list<array<string,mixed>>}> $optionGroups
 * @var bool $viewOptionsReady
 * @var string $savePath
 * @var string $assetsJsPath
 * @var string $assetsCssPath
 * @var string $workspaceLookupPath
 * @var string $pageLookupPath
 */

$selectedWorkspaceId = 0;
$selectedWorkspaceLabel = __('Sva područja');
$selectedPageLabel = __('Koristi zadanu naslovnicu');
foreach ($optionGroups as $group) {
    foreach ($group['options'] as $option) {
        if (WorkspaceValue::string($option['value'] ?? '') !== $selectedTargetValue) {
            continue;
        }

        $selectedWorkspaceId = WorkspaceValue::int($option['workspace_id'] ?? 0);
        $selectedWorkspaceLabel = WorkspaceValue::string($group['name'] ?? __('Sva područja'));
        $selectedPageLabel = WorkspaceValue::string($option['title'] ?? '');
        break 2;
    }
}
$workspaceValueSelector = '#workspace-personal-homepage-workspace [data-workspace-lookup-value]';
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="h5 mb-2"><?= $this->escape(__('Osobna naslovnica')) ?></h2>
        <p class="text-body-secondary mb-3">
            <?= $this->escape(
                __('Odaberite objavljenu stranicu područja koja će se otvoriti nakon dolaska na naslovnicu.'),
            ) ?>
        </p>

        <?php if ($selectionUnavailable) : ?>
            <div class="alert alert-warning" role="alert">
            <?= $this->escape(
                __('Prethodno odabrana stranica više nije dostupna pa se koristi zadana naslovnica.'),
            ) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $this->escape($savePath) ?>">
            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
            <label class="form-label">
                <?= $this->escape(__('Moja naslovnica')) ?>
            </label>
            <div class="mb-3" id="workspace-personal-homepage-workspace">
                <label class="form-label"><?= $this->escape(__('Područje')) ?></label>
                <?php
                $workspaceLookupKind = 'workspace';
                $workspaceLookupName = '';
                $workspaceLookupValue = $selectedWorkspaceId > 0 ? (string)$selectedWorkspaceId : '';
                $workspaceLookupLabel = $selectedWorkspaceLabel;
                $workspaceLookupEndpoint = $workspaceLookupPath;
                $workspaceLookupAudience = 'current';
                $workspaceLookupWorkspaceSelector = '';
                $workspaceLookupIncludeAll = true;
                $workspaceLookupAllLabel = __('Sva područja');
                $workspaceLookupAllValue = '';
                $workspaceLookupRequired = false;
                $workspaceLookupValueMode = 'id';
                $workspaceLookupPublishedOnly = false;
                $workspaceLookupIncludeShorts = false;
                $workspaceLookupTargetKey = '';
                require __DIR__ . '/../partials/lookup-picker.php';
                ?>
            </div>
            <div>
                <label class="form-label"><?= $this->escape(__('Stranica')) ?></label>
                <?php
                $workspaceLookupKind = 'page';
                $workspaceLookupName = 'target';
                $workspaceLookupValue = $selectedTargetValue;
                $workspaceLookupLabel = $selectedPageLabel;
                $workspaceLookupEndpoint = $pageLookupPath;
                $workspaceLookupAudience = 'current';
                $workspaceLookupWorkspaceSelector = $workspaceValueSelector;
                $workspaceLookupIncludeAll = true;
                $workspaceLookupAllLabel = __('Koristi zadanu naslovnicu');
                $workspaceLookupAllValue = 'default';
                $workspaceLookupRequired = true;
                $workspaceLookupValueMode = 'target';
                $workspaceLookupPublishedOnly = true;
                $workspaceLookupIncludeShorts = $viewOptionsReady;
                $workspaceLookupTargetKey = 'personal';
                require __DIR__ . '/../partials/lookup-picker.php';
                ?>
            </div>
            <?php if ($viewOptionsReady) : ?>
                <div class="mt-3" data-workspace-homepage-view-options="personal">
                    <div class="form-check form-switch">
                        <input
                            id="workspace-personal-show-tree"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            name="show_tree"
                            value="1"
                <?= (bool)($selectedTarget['show_tree'] ?? true) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="workspace-personal-show-tree">
                <?= $this->escape(__('Vidljivo stablo stranica')) ?>
                        </label>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input
                            id="workspace-personal-show-options"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            name="show_display_options"
                            value="1"
                <?= (bool)($selectedTarget['show_display_options'] ?? true)
                ? 'checked'
                : '' ?>
                        >
                        <label class="form-check-label" for="workspace-personal-show-options">
                <?= $this->escape(__('Vidljive opcije prikaza')) ?>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary mt-3">
                <?= $this->escape(__('Spremi osobnu naslovnicu')) ?>
            </button>
        </form>
    </div>
</div>
