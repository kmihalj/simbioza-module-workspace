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
 */
?>
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
            <label class="form-label" for="workspace-personal-homepage">
                <?= $this->escape(__('Moja naslovnica')) ?>
            </label>
            <select
                id="workspace-personal-homepage"
                class="form-select"
                name="target"
                data-workspace-homepage-target="personal"
            >
                <option value="default"><?= $this->escape(__('Koristi zadanu naslovnicu')) ?></option>
                <?php foreach ($optionGroups as $group) : ?>
                    <optgroup label="<?= $this->escape(WorkspaceValue::string($group['name'] ?? '')) ?>">
                    <?php foreach ($group['options'] as $option) : ?>
                            <option
                                value="<?= $this->escape(WorkspaceValue::string(
                                    $option['value'] ?? '',
                                )) ?>"
                        <?= WorkspaceValue::string($option['value'] ?? '') === $selectedTargetValue
                        ? 'selected'
                        : '' ?>
                            >
                        <?= $this->escape(WorkspaceValue::string($option['title'] ?? '')) ?>
                            </option>
                    <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
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
