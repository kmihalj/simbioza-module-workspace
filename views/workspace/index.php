<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var bool $tablesReady
 * @var list<array<string, mixed>> $workspaces
 * @var list<array<string, mixed>> $otherPersonalWorkspaces
 * @var int $personalWorkspaceCount
 * @var bool $personalMode
 * @var array<string,mixed> $pagination
 * @var bool $canCreateWorkspace
 * @var bool $isAdministrator
 * @var string $managePath
 * @var string $settingsPath
 * @var string $indexPath
 * @var string $personalPath
 * @var string $assetsCssPath
 */
$page = WorkspaceValue::int($pagination['page'] ?? 1);
$pages = WorkspaceValue::int($pagination['pages'] ?? 0);
$total = WorkspaceValue::int($pagination['total'] ?? 0);
$from = WorkspaceValue::int($pagination['from'] ?? 0);
$to = WorkspaceValue::int($pagination['to'] ?? 0);
$pageNumbers = array_values(array_filter(
    is_array($pagination['page_numbers'] ?? null) ? $pagination['page_numbers'] : [],
    static fn(mixed $number): bool => is_numeric($number) && (int)$number > 0,
));
$pagePath = static fn(int $number): string => $indexPath . '?' . http_build_query(array_filter([
    'personal' => $personalMode ? '1' : null,
    'page' => $number,
], static fn(mixed $value): bool => $value !== null));
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">

<header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?= $this->escape($title) ?></h1>
        <p class="text-body-secondary mb-0">
            <?= $this->escape($personalMode
                ? __('Administratorski pregled osobnih područja odvojen od ostalih područja.')
                : __('Povezane stranice, članovi i prava organizirani na jednom mjestu.')) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap justify-content-end gap-2">
        <?php if (!$isAdministrator && $otherPersonalWorkspaces !== []) : ?>
            <div class="dropdown">
                <button
                    class="btn btn-secondary dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                ><?= $this->escape(__('Dostupna osobna područja')) ?></button>
                <ul class="dropdown-menu dropdown-menu-end workspace-personal-dropdown">
            <?php foreach ($otherPersonalWorkspaces as $workspace) : ?>
                        <li>
                            <a
                                class="dropdown-item text-wrap"
                                href="<?= $this->escape(WorkspaceValue::string($workspace['href'] ?? '#')) ?>"
                            ><?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?></a>
                        </li>
            <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($isAdministrator) : ?>
            <?php if ($personalMode) : ?>
                <a class="btn btn-secondary" href="<?= $this->escape($indexPath) ?>">
                <?= $this->escape(__('Sva ostala područja')) ?>
                </a>
            <?php elseif ($personalWorkspaceCount > 0) : ?>
                <a class="btn btn-secondary" href="<?= $this->escape($personalPath) ?>">
                <?= $this->escape(__('Osobna područja')) ?>
                    <span class="badge text-bg-light ms-1"><?= $this->escape((string)$personalWorkspaceCount) ?></span>
                </a>
            <?php endif; ?>
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
    <?= $this->escape($personalMode
        ? __('Nema osobnih područja.')
        : __('Nema područja koja smijete vidjeti.')) ?>
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
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3">
        <p class="text-body-secondary small mb-0">
    <?= $this->escape(sprintf(__('Prikazano %d–%d od %d područja.'), $from, $to, $total)) ?>
        </p>
    <?php if ($pages > 1) : ?>
            <nav aria-label="<?= $this->escape(__('Stranice područja')) ?>">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= $this->escape($pagePath(max(1, $page - 1))) ?>"
                            aria-label="<?= $this->escape(__('Prethodna stranica')) ?>"
                        >&lsaquo;</a>
                    </li>
        <?php foreach ($pageNumbers as $number) : ?>
            <?php $number = (int)$number; ?>
                        <li class="page-item <?= $number === $page ? 'active' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= $this->escape($pagePath($number)) ?>"
            <?= $number === $page ? 'aria-current="page"' : '' ?>
                            ><?= $this->escape((string)$number) ?></a>
                        </li>
        <?php endforeach; ?>
                    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= $this->escape($pagePath(min($pages, $page + 1))) ?>"
                            aria-label="<?= $this->escape(__('Sljedeća stranica')) ?>"
                        >&rsaquo;</a>
                    </li>
                </ul>
            </nav>
    <?php endif; ?>
    </div>
<?php endif; ?>
