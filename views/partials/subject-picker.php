<?php

declare(strict_types=1);

/**
 * EN: Shared bounded remote user/group combobox for Workspace and optional content ACL forms.
 *
 * HR: Zajednički ograničeni udaljeni odabir korisnika/grupa za područja i ovlasti opcionalnog sadržaja.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $workspaceSubjectId
 * @var string $workspaceSubjectResultsId
 * @var string $workspaceSubjectType
 * @var string $workspaceSubjectSearchUrl
 * @var int $workspaceSubjectWorkspaceId
 * @var bool $workspaceSubjectRealOnly
 */
$workspaceSubjectRealOnly = (bool)($workspaceSubjectRealOnly ?? false);
?>
<div class="workspace-subject-picker" data-workspace-subject-picker data-workspace-picker-mode="acl"
    data-workspace-min-query-length="0" data-workspace-real-subjects-only="<?= $workspaceSubjectRealOnly ? '1' : '0' ?>"
    data-workspace-subject-type="<?= $this->escape($workspaceSubjectType) ?>"
    data-workspace-search-url="<?= $this->escape($workspaceSubjectSearchUrl) ?>"
    data-workspace-id="<?= (int)$workspaceSubjectWorkspaceId ?>"
    data-workspace-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
    data-workspace-load-more="<?= $this->escape(__('Učitaj još')) ?>"
    data-workspace-search-error="<?= $this->escape(__('Pretraživanje nije uspjelo.')) ?>">
    <input id="<?= $this->escape($workspaceSubjectId) ?>" class="form-control" type="search" role="combobox"
        autocomplete="off" aria-autocomplete="list" aria-expanded="false"
        aria-controls="<?= $this->escape($workspaceSubjectResultsId) ?>"
        placeholder="<?= $this->escape(
            $workspaceSubjectType === 'user' ? __('Pretraži korisnike') : __('Pretraži grupe'),
        ) ?>"
        data-workspace-subject-search>
    <div id="<?= $this->escape($workspaceSubjectResultsId) ?>" class="workspace-subject-results list-group"
        role="listbox" data-workspace-subject-results hidden></div>
</div>
