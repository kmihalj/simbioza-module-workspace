<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Jedan uređivi red strukturiranog svojstva stranice.
 * EN: One editable structured page-property row.
 *
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $property
 */
$property = is_array($property ?? null) ? $property : [];
?>
<div class="row g-2 align-items-end" data-workspace-page-property-row>
    <div class="col-12 col-md-4">
        <label class="form-label small w-100 mb-0">
            <span class="d-block mb-2"><?= $this->escape(__('Naziv svojstva')) ?></span>
            <input
                class="form-control"
                name="properties[label][]"
                value="<?= $this->escape(WorkspaceValue::string($property['label'] ?? '')) ?>"
            >
        </label>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small w-100 mb-0">
            <span class="d-block mb-2"><?= $this->escape(__('Vrsta')) ?></span>
            <select class="form-select" name="properties[type][]">
                <?php foreach (['text', 'status', 'number', 'date', 'user', 'link'] as $type) : ?>
                    <option
                        value="<?= $type ?>"
                    <?= WorkspaceValue::string($property['type'] ?? 'text') === $type ? 'selected' : '' ?>
                    >
                    <?= $this->escape(__($type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label small w-100 mb-0">
            <span class="d-block mb-2"><?= $this->escape(__('Vrijednost')) ?></span>
            <input
                class="form-control"
                name="properties[value][]"
                value="<?= $this->escape(WorkspaceValue::string($property['value'] ?? '')) ?>"
            >
        </label>
    </div>
    <div class="col-12 col-md-auto">
        <button
            class="btn btn-outline-danger"
            type="button"
            data-workspace-page-property-remove
        >
            <?= $this->escape(__('Ukloni')) ?>
        </button>
    </div>
</div>
