<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Povezani udaljeni birači područja i naslovne stranice.
 * EN: Linked remote Workspace and homepage pickers.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $selectorKey
 * @var string $selectorLegend
 * @var string $selectorTargetName
 * @var string $selectorTargetValue
 * @var list<array{name:string,options:list<array<string,mixed>>}> $selectorGroups
 * @var string $selectorSpecialPageLabel
 * @var string $workspaceLookupPath
 * @var string $pageLookupPath
 * @var bool $viewOptionsReady
 */

$selectedWorkspaceId = 0;
$selectedWorkspaceLabel = __('Sva područja');
$selectedPageLabel = $selectorSpecialPageLabel;
foreach ($selectorGroups as $group) {
    foreach ($group['options'] as $option) {
        if (WorkspaceValue::string($option['value'] ?? '') !== $selectorTargetValue) {
            continue;
        }

        $selectedWorkspaceId = WorkspaceValue::int($option['workspace_id'] ?? 0);
        $selectedWorkspaceLabel = WorkspaceValue::string($group['name'] ?? __('Sva područja'));
        $selectedPageLabel = WorkspaceValue::string($option['title'] ?? '');
        break 2;
    }
}
$workspaceValueSelector = '#workspace-' . $selectorKey . '-workspace [data-workspace-lookup-value]';
?>
<fieldset>
    <legend class="form-label fw-semibold mb-3"><?= $this->escape($selectorLegend) ?></legend>
    <div class="mb-3" id="workspace-<?= $this->escape($selectorKey) ?>-workspace">
        <label class="form-label"><?= $this->escape(__('Područje')) ?></label>
        <?php
        $workspaceLookupKind = 'workspace';
        $workspaceLookupName = '';
        $workspaceLookupValue = $selectedWorkspaceId > 0 ? (string)$selectedWorkspaceId : '';
        $workspaceLookupLabel = $selectedWorkspaceLabel;
        $workspaceLookupEndpoint = $workspaceLookupPath;
        $workspaceLookupAudience = $selectorKey;
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
        $workspaceLookupName = $selectorTargetName;
        $workspaceLookupValue = $selectorTargetValue;
        $workspaceLookupLabel = $selectedPageLabel;
        $workspaceLookupEndpoint = $pageLookupPath;
        $workspaceLookupAudience = $selectorKey;
        $workspaceLookupWorkspaceSelector = $workspaceValueSelector;
        $workspaceLookupIncludeAll = true;
        $workspaceLookupAllLabel = $selectorSpecialPageLabel;
        $workspaceLookupAllValue = 'default';
        $workspaceLookupRequired = true;
        $workspaceLookupValueMode = 'target';
        $workspaceLookupPublishedOnly = true;
        $workspaceLookupIncludeShorts = $viewOptionsReady;
        $workspaceLookupTargetKey = $selectorKey;
        require __DIR__ . '/../partials/lookup-picker.php';
        ?>
    </div>
</fieldset>
