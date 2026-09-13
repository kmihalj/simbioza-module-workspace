<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string,mixed> $workspace
 * @var array<string,mixed> $node
 * @var int $subtreeCount
 * @var string $submitPath
 * @var string $returnPath
 * @var string $workspaceLookupPath
 * @var string $pageLookupPath
 * @var string $assetsCssPath
 * @var string $assetsJsPath
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$nodeId = WorkspaceValue::int($node['id'] ?? 0);
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>

<div class="container-fluid hph-container-wide py-4">
    <section class="card hph-content-card">
        <div class="card-body p-4">
            <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?>
                        / <?= $this->escape(WorkspaceValue::string($node['title'] ?? '')) ?>
                    </p>
                </div>
                <a class="btn btn-outline-secondary" href="<?= $this->escape($returnPath) ?>">
                    <?= $this->escape(__('Natrag na stranicu')) ?>
                </a>
            </header>

            <div class="alert alert-info">
                <?= $this->escape(__(
                    'Sadržaj, sve jezične verzije i privitci uvijek se prenose. '
                    . 'Povijest i prava možete uključiti zasebno.',
                )) ?>
            </div>

            <?php if ($subtreeCount > 1) : ?>
                <div class="alert alert-warning">
                <?= $this->escape(__(
                    'Ova stranica ima podređene stavke. Kopira se samo odabrana stranica; '
                    . 'premještanje nije dostupno dok postoje podređene stavke.',
                )) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= $this->escape($submitPath) ?>">
                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                <input type="hidden" name="node_id" value="<?= $nodeId ?>">

                <fieldset class="mb-4">
                    <legend class="h5"><?= $this->escape(__('Radnja')) ?></legend>
                    <div class="d-flex flex-column flex-md-row gap-3">
                        <div class="form-check">
                            <input class="form-check-input" id="page-transfer-copy" type="radio"
                                name="operation" value="copy" checked>
                            <label class="form-check-label" for="page-transfer-copy">
                                <strong><?= $this->escape(__('Kopiraj stranicu')) ?></strong><br>
                                <span class="text-body-secondary">
                                    <?= $this->escape(__('Izvorna stranica ostaje nepromijenjena.')) ?>
                                </span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" id="page-transfer-move" type="radio"
                                name="operation" value="move" <?= $subtreeCount > 1 ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="page-transfer-move">
                                <strong><?= $this->escape(__('Premjesti stranicu')) ?></strong><br>
                                <span class="text-body-secondary">
                                    <?= $this->escape(__('Izvor se briše tek nakon uspješnog kopiranja.')) ?>
                                </span>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-6" id="page-transfer-target-workspace">
                        <label class="form-label"><?= $this->escape(__('Ciljno područje')) ?></label>
                        <?php
                        $workspaceLookupKind = 'workspace';
                        $workspaceLookupName = 'target_workspace_id';
                        $workspaceLookupValue = '';
                        $workspaceLookupLabel = '';
                        $workspaceLookupEndpoint = $workspaceLookupPath;
                        $workspaceLookupAudience = 'current';
                        $workspaceLookupWorkspaceSelector = '';
                        $workspaceLookupIncludeAll = false;
                        $workspaceLookupAllLabel = '';
                        $workspaceLookupAllValue = '';
                        $workspaceLookupAllDisabled = false;
                        $workspaceLookupRequired = true;
                        $workspaceLookupValueMode = 'id';
                        $workspaceLookupPublishedOnly = false;
                        $workspaceLookupIncludeShorts = false;
                        $workspaceLookupIncludeContainers = false;
                        $workspaceLookupRequireCanAdd = false;
                        $workspaceLookupRequireCanManage = true;
                        $workspaceLookupExcludeNodeId = 0;
                        $workspaceLookupFixedWorkspaceId = 0;
                        $workspaceLookupTargetKey = '';
                        require __DIR__ . '/../partials/lookup-picker.php';
                        ?>
                        <div class="form-text">
                            <?= $this->escape(__('Prikazana su samo područja kojima smijete upravljati.')) ?>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label">
                            <?= $this->escape(__('Nadređena stranica u ciljnom području')) ?>
                        </label>
                        <?php
                        $workspaceLookupKind = 'page';
                        $workspaceLookupName = 'target_parent_id';
                        $workspaceLookupValue = '';
                        $workspaceLookupLabel = '';
                        $workspaceLookupEndpoint = $pageLookupPath;
                        $workspaceLookupAudience = 'current';
                        $workspaceLookupWorkspaceSelector =
                        '#page-transfer-target-workspace [data-workspace-lookup-value]';
                        $workspaceLookupIncludeAll = true;
                        $workspaceLookupAllLabel = __('Korijen stabla');
                        $workspaceLookupAllValue = '';
                        $workspaceLookupAllDisabled = false;
                        $workspaceLookupRequired = false;
                        $workspaceLookupValueMode = 'id';
                        $workspaceLookupPublishedOnly = false;
                        $workspaceLookupIncludeShorts = false;
                        $workspaceLookupIncludeContainers = true;
                        $workspaceLookupRequireCanAdd = false;
                        $workspaceLookupRequireCanManage = false;
                        $workspaceLookupExcludeNodeId = 0;
                        $workspaceLookupFixedWorkspaceId = 0;
                        $workspaceLookupTargetKey = '';
                        require __DIR__ . '/../partials/lookup-picker.php';
                        ?>
                        <div class="form-text">
                            <?= $this->escape(__('Ako ništa ne odaberete, stranica se dodaje u korijen stabla.')) ?>
                        </div>
                    </div>
                </div>

                <fieldset class="border rounded p-3 mb-4">
                    <legend class="float-none w-auto px-2 h6 mb-0">
                        <?= $this->escape(__('Dodatne mogućnosti')) ?>
                    </legend>
                    <div class="form-check mb-2">
                        <input class="form-check-input" id="page-transfer-history" type="checkbox"
                            name="include_history" value="1" checked>
                        <label class="form-check-label" for="page-transfer-history">
                            <?= $this->escape(__('Prenesi cijelu povijest verzija')) ?>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" id="page-transfer-permissions" type="checkbox"
                            name="include_permissions" value="1">
                        <label class="form-check-label" for="page-transfer-permissions">
                            <?= $this->escape(__('Prenesi izravna dopuštenja i ograničenja stranice')) ?>
                        </label>
                        <div class="form-text">
                            <?= $this->escape(__(
                                'Prava se prenose samo za korisnike i grupe koji postoje na ciljnom sustavu.',
                            )) ?>
                        </div>
                    </div>
                </fieldset>

                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <a class="btn btn-outline-secondary" href="<?= $this->escape($returnPath) ?>">
                        <?= $this->escape(__('Odustani')) ?>
                    </a>
                    <button class="btn btn-primary" type="submit">
                        <?= $this->escape(__('Nastavi s odabranom radnjom')) ?>
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>
