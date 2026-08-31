<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Administratorska postavka naslovnice aplikacije unutar grupe Područja.
 * EN: Application-homepage administration inside the Workspaces settings group.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var bool $tablesReady
 * @var bool $viewOptionsReady
 * @var array<string, mixed> $settings
 * @var list<array{name:string,options:list<array{id:int,title:string}>}> $publicOptionGroups
 * @var list<array{name:string,options:list<array{id:int,title:string}>}> $authenticatedOptionGroups
 * @var string $savePath
 * @var string $settingsPath
 * @var string $homepagePath
 * @var string $allPath
 * @var string $deletedPath
 * @var string $settingsMenuActiveSection
 * @var object|null $menuRenderer
 * @var string $assetsCssPath
 * @var string $assetsJsPath
 */

$publicTarget = WorkspaceValue::stringKeyArray($settings['public_target'] ?? null);
$authenticatedTarget = WorkspaceValue::stringKeyArray($settings['authenticated_target'] ?? null);
$targetValue = (static fn(array $target): string => match (WorkspaceValue::string($target['type'] ?? 'default')) {
    'page' => 'page:' . WorkspaceValue::int($target['node_id'] ?? 0),
    'shorts' => 'shorts:' . WorkspaceValue::int($target['workspace_id'] ?? 0),
    default => 'default',
});
$publicValue = $targetValue($publicTarget);
$authenticatedValue = $targetValue($authenticatedTarget);
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>

<div class="row g-4">
    <aside class="col-lg-3">
        <?php require __DIR__ . '/sidebar.php'; ?>
    </aside>

    <div class="col-lg-9">
        <section class="card">
            <div class="card-body">
                <header class="mb-4">
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $this->escape(
                            __('Odaberite objavljenu stranicu područja za goste i prijavljene korisnike.'),
                        ) ?>
                    </p>
                </header>

                <?php if (!$tablesReady) : ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <strong><?= $this->escape(__('Nedostaje migracija naslovnice područja.')) ?></strong>
                        <div>
                    <?= $this->escape(
                        __('Primijenite Workspace migraciju za naslovnicu pa ponovno otvorite postavke.'),
                    ) ?>
                        </div>
                    </div>
                <?php else : ?>
                    <?php if (!$viewOptionsReady) : ?>
                        <div class="alert alert-warning" role="alert">
                        <?= $this->escape(
                            __('Primijenite migraciju opcija prikaza za Shorts naslovnice.'),
                        ) ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= $this->escape($savePath) ?>">
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>

                        <div class="row g-4">
                            <div class="col-12 col-xl-6">
                                <label class="form-label" for="workspace-public-homepage">
                    <?= $this->escape(__('Javna naslovnica')) ?>
                                </label>
                                <select
                                    id="workspace-public-homepage"
                                    class="form-select"
                                    name="public_target"
                                    data-workspace-homepage-target="public"
                                >
                                    <option value="default">
                    <?= $this->escape(__('Ugrađena naslovnica aplikacije')) ?>
                                    </option>
                    <?php foreach ($publicOptionGroups as $group) : ?>
                                        <optgroup label="<?= $this->escape($group['name']) ?>">
                        <?php foreach ($group['options'] as $option) : ?>
                                                <option
                                                    value="<?= $this->escape(WorkspaceValue::string(
                                                        $option['value'] ?? '',
                                                    )) ?>"
                            <?= WorkspaceValue::string($option['value'] ?? '') === $publicValue
                            ? 'selected'
                            : '' ?>
                                                >
                            <?= $this->escape($option['title']) ?>
                                                </option>
                        <?php endforeach; ?>
                                        </optgroup>
                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                    <?= $this->escape(__('Može se odabrati samo stranica dostupna neprijavljenom gostu.')) ?>
                                </div>
                                <div
                                    class="mt-3"
                                    data-workspace-homepage-view-options="public"
                                >
                                    <div class="form-check form-switch">
                                        <input
                                            id="workspace-public-show-tree"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            name="public_show_tree"
                                            value="1"
                    <?= (bool)($publicTarget['show_tree'] ?? true) ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="workspace-public-show-tree">
                    <?= $this->escape(__('Vidljivo stablo stranica')) ?>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch mt-2">
                                        <input
                                            id="workspace-public-show-options"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            name="public_show_display_options"
                                            value="1"
                    <?= (bool)($publicTarget['show_display_options'] ?? true)
                    ? 'checked'
                    : '' ?>
                                        >
                                        <label class="form-check-label" for="workspace-public-show-options">
                    <?= $this->escape(__('Vidljive opcije prikaza')) ?>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-xl-6">
                                <label class="form-label" for="workspace-authenticated-homepage">
                    <?= $this->escape(__('Naslovnica za prijavljene korisnike')) ?>
                                </label>
                                <select
                                    id="workspace-authenticated-homepage"
                                    class="form-select"
                                    name="authenticated_target"
                                    data-workspace-homepage-target="authenticated"
                                >
                                    <option value="default">
                    <?= $this->escape(__('Koristi javnu naslovnicu')) ?>
                                    </option>
                    <?php foreach ($authenticatedOptionGroups as $group) : ?>
                                        <optgroup label="<?= $this->escape($group['name']) ?>">
                        <?php foreach ($group['options'] as $option) : ?>
                                                <option
                                                    value="<?= $this->escape(WorkspaceValue::string(
                                                        $option['value'] ?? '',
                                                    )) ?>"
                            <?= WorkspaceValue::string($option['value'] ?? '') === $authenticatedValue
                            ? 'selected'
                            : '' ?>
                                                >
                            <?= $this->escape($option['title']) ?>
                                                </option>
                        <?php endforeach; ?>
                                        </optgroup>
                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                    <?= $this->escape(
                        __('Izbor mora biti dostupan svakom prijavljenom korisniku bez grupnih prava.'),
                    ) ?>
                                </div>
                                <div
                                    class="mt-3"
                                    data-workspace-homepage-view-options="authenticated"
                                >
                                    <div class="form-check form-switch">
                                        <input
                                            id="workspace-authenticated-show-tree"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            name="authenticated_show_tree"
                                            value="1"
                    <?= (bool)($authenticatedTarget['show_tree'] ?? true)
                    ? 'checked'
                    : '' ?>
                                        >
                                        <label
                                            class="form-check-label"
                                            for="workspace-authenticated-show-tree"
                                        >
                    <?= $this->escape(__('Vidljivo stablo stranica')) ?>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch mt-2">
                                        <input
                                            id="workspace-authenticated-show-options"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            name="authenticated_show_display_options"
                                            value="1"
                    <?= (bool)($authenticatedTarget['show_display_options'] ?? true)
                    ? 'checked'
                    : '' ?>
                                        >
                                        <label
                                            class="form-check-label"
                                            for="workspace-authenticated-show-options"
                                        >
                    <?= $this->escape(__('Vidljive opcije prikaza')) ?>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input
                                        id="workspace-allow-user-homepage"
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        name="allow_user_selection"
                                        value="1"
                    <?= (bool)($settings['allow_user_selection'] ?? true) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="workspace-allow-user-homepage">
                    <?= $this->escape(__('Dopusti korisnicima odabir osobne naslovnice')) ?>
                                    </label>
                                </div>
                                <div class="form-text">
                    <?= $this->escape(
                        __('Korisnik će u svom profilu vidjeti samo objavljene stranice kojima ima pristup.'),
                    ) ?>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info mt-4 mb-0" role="note">
                    <?= $this->escape(
                        __(
                            'Ako odabrana stranica nestane ili prava budu opozvana, '
                            . 'primjenjuje se sljedeća dostupna zadana naslovnica.',
                        ),
                    ) ?>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-primary" type="submit">
                    <?= $this->escape(__('Spremi postavke naslovnice')) ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
