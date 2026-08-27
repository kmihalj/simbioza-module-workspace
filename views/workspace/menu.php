<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: View za uređivanje dvaju potpuno odvojenih posebnih menija jednog područja.
 * EN: View for editing two fully separated special menus of one Workspace.
 *
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $workspace
 * @var string $topEditor
 * @var string $leftEditor
 * @var string $managePath
 * @var string $workspacePath
 */
$workspaceName = WorkspaceValue::string($workspace['name'] ?? $workspace['slug'] ?? '');
?>
<header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?= $this->escape(__('Posebni meniji područja')) ?></h1>
        <p class="text-body-secondary mb-0"><?= $this->escape($workspaceName) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= $this->escape($workspacePath) ?>">
            <?= $this->escape(__('Otvori područje')) ?>
        </a>
        <a class="btn btn-secondary" href="<?= $this->escape($managePath) ?>">
            <?= $this->escape(__('Upravljaj područjem')) ?>
        </a>
    </div>
</header>

<div class="card shadow-sm hph-content-card workspace-special-menu-intro mb-4" role="note">
    <div class="card-body py-3">
        <p class="mb-0">
            <?= $this->escape(__(
                'Gornji i lijevi meni uređuju se odvojeno. Promjena ili uklanjanje jednoga ne mijenja drugi meni.',
            )) ?>
        </p>
    </div>
</div>

<section class="mb-4" aria-labelledby="workspace-special-top-menu-title">
    <h2 id="workspace-special-top-menu-title" class="h4 mb-3">
        <?= $this->escape(__('Posebni gornji meni')) ?>
    </h2>
    <?= $topEditor ?>
</section>

<section class="mb-4" aria-labelledby="workspace-special-left-menu-title">
    <h2 id="workspace-special-left-menu-title" class="h4 mb-3">
        <?= $this->escape(__('Posebni lijevi meni')) ?>
    </h2>
    <?= $leftEditor ?>
</section>
