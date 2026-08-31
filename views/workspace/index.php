<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var bool $tablesReady
 * @var list<array<string, mixed>> $workspaces
 * @var bool $canCreateWorkspace
 * @var bool $isAdministrator
 * @var string $managePath
 * @var string $settingsPath
 * @var string $assetsCssPath
 */
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">

<header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?= $this->escape($title) ?></h1>
        <p class="text-body-secondary mb-0">
            <?= $this->escape(__('Povezane stranice, članovi i prava organizirani na jednom mjestu.')) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isAdministrator) : ?>
            <a class="btn btn-secondary" href="<?= $this->escape($settingsPath) ?>">
            <?= $this->escape(__('Postavke')) ?>
            </a>
        <?php endif; ?>
        <?php if ($canCreateWorkspace) : ?>
            <a class="btn btn-primary" href="<?= $this->escape($managePath) ?>">
            <?= $this->escape(__('Novo područje')) ?>
            </a>
        <?php endif; ?>
    </div>
</header>

<?php if (!$tablesReady) : ?>
    <div class="alert alert-warning" role="alert">
    <?= $this->escape(__('Početna Workspace migracija još nije pokrenuta.')) ?>
    </div>
<?php elseif ($workspaces === []) : ?>
    <div class="card shadow-sm hph-content-card workspace-empty-state">
        <div class="card-body p-4 text-body-secondary">
    <?= $this->escape(__('Nema područja koja smijete vidjeti.')) ?>
        </div>
    </div>
<?php else : ?>
    <div class="list-group workspace-list">
    <?php foreach ($workspaces as $workspace) : ?>
            <a
                class="list-group-item list-group-item-action d-flex align-items-start justify-content-between gap-3"
                href="<?= $this->escape(WorkspaceValue::string($workspace['href'] ?? '#')) ?>"
            >
                <span>
                    <strong class="d-block">
        <?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?>
                    </strong>
        <?php if (WorkspaceValue::string($workspace['description'] ?? '') !== '') : ?>
                        <span class="text-body-secondary">
            <?= $this->escape(WorkspaceValue::string($workspace['description'] ?? '')) ?>
                        </span>
        <?php endif; ?>
                </span>
                <span class="badge text-bg-secondary">
        <?= $this->escape(__(WorkspaceValue::string($workspace['visibility'] ?? 'restricted'))) ?>
                </span>
            </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
