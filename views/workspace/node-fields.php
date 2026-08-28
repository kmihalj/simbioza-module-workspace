<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $node
 * @var list<array<string, mixed>> $nodes
 * @var list<array{id:string,title:string}> $editorDocuments
 * @var bool $editorAvailable
 * @var bool $canAttachExistingDocuments
 * @var bool $workspaceCanAdd
 * @var bool $treeOrganizerAvailable
 */
$nodeType = WorkspaceValue::string($node['node_type'] ?? 'document');
$nodeId = WorkspaceValue::int($node['id'] ?? 0);
$currentParentId = WorkspaceValue::int($node['parent_id'] ?? 0);
$currentDocumentKey = WorkspaceValue::string($node['document_key'] ?? '');
$primaryLanguage = strtolower(WorkspaceValue::string($primaryLanguage ?? 'hr'));
$activeLanguage = strtolower(WorkspaceValue::string($activeLanguage ?? $primaryLanguage));
$supportedLanguages = array_values(array_unique(array_filter(array_map(
    static fn (mixed $language): string => strtolower(WorkspaceValue::string($language)),
    is_array($supportedLanguages ?? null) ? $supportedLanguages : [$primaryLanguage],
))));
if ($supportedLanguages === []) {
    $supportedLanguages = [$primaryLanguage];
}
if (!in_array($primaryLanguage, $supportedLanguages, true)) {
    array_unshift($supportedLanguages, $primaryLanguage);
}
if (!in_array($activeLanguage, $supportedLanguages, true)) {
    $activeLanguage = $primaryLanguage;
}
$titleTranslationValues = WorkspaceValue::stringKeyArray($node['title_translation_values'] ?? null);
$localeFlagPaths = WorkspaceValue::stringKeyArray($localeFlagPaths ?? null);
$flagPathForLocale = static function (string $locale) use ($localeFlagPaths): string {
    $locale = strtolower(trim($locale));
    $language = strtolower(strtok($locale, '-_') ?: $locale);

    return WorkspaceValue::string($localeFlagPaths[$locale] ?? $localeFlagPaths[$language] ?? '');
};
$localeButtonContent = function (string $locale) use ($flagPathForLocale): string {
    $flagPath = $flagPathForLocale($locale);
    $flag = $flagPath !== ''
    ? '<img class="workspace-locale-flag" src="' . $this->escape($flagPath) . '" alt="">'
    : '';

    return $flag . '<span>' . $this->escape(strtoupper($locale)) . '</span>';
};
?>
<div class="row g-3" data-workspace-node-fields>
    <div class="col-12 col-lg-4">
        <label class="form-label" for="workspace-node-title-<?= $nodeId ?>-<?= $activeLanguage ?>">
            <?= $this->escape(__('Naslov')) ?>
        </label>
        <div class="input-group" data-workspace-translation-group>
            <button
                class="btn btn-outline-secondary dropdown-toggle workspace-locale-button"
                type="button"
                data-bs-toggle="dropdown"
                data-workspace-translation-button
                data-current-locale="<?= $this->escape($activeLanguage) ?>"
                aria-label="<?= $this->escape(__('Jezik naslova')) ?>"
            >
                <?= $localeButtonContent($activeLanguage) ?>
            </button>
            <ul class="dropdown-menu">
                <?php foreach ($supportedLanguages as $supportedLanguage) : ?>
                    <li>
                        <button
                            class="dropdown-item d-flex align-items-center gap-2"
                            type="button"
                            data-workspace-translation-option
                            data-locale="<?= $this->escape($supportedLanguage) ?>"
                            data-flag-src="<?= $this->escape($flagPathForLocale($supportedLanguage)) ?>"
                        >
                    <?= $localeButtonContent($supportedLanguage) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php foreach ($supportedLanguages as $supportedLanguage) : ?>
                <input
                    id="workspace-node-title-<?= $nodeId ?>-<?= $supportedLanguage ?>"
                    class="form-control<?= $supportedLanguage === $activeLanguage ? '' : ' d-none' ?>"
                    name="title_translations[<?= $supportedLanguage ?>]"
                    value="<?= $this->escape(WorkspaceValue::string(
                        $titleTranslationValues[$supportedLanguage] ?? '',
                    )) ?>"
                    data-workspace-translation-panel="<?= $supportedLanguage ?>"
                <?= $supportedLanguage === $primaryLanguage ? 'required' : '' ?>
                >
            <?php endforeach; ?>
        </div>
        <div class="form-text">
            <?= $this->escape(sprintf(
                __('Naslov na primarnom jeziku (%s) je obvezan.'),
                $primaryLanguage,
            )) ?>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label"><?= $this->escape(__('Slug')) ?></label>
        <input
            class="form-control font-monospace"
            name="slug"
            value="<?= $this->escape(WorkspaceValue::string($node['slug'] ?? '')) ?>"
        >
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label"><?= $this->escape(__('Vrsta stavke')) ?></label>
        <select class="form-select" name="node_type" data-workspace-node-type>
            <?php foreach (['document', 'internal_link', 'external_link'] as $type) : ?>
                <option value="<?= $type ?>" <?= $nodeType === $type ? 'selected' : '' ?>>
                <?= $this->escape(__($type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!$treeOrganizerAvailable) : ?>
        <div class="col-12 col-md-4 col-lg-2">
            <label class="form-label"><?= $this->escape(__('Redoslijed')) ?></label>
            <input
                class="form-control"
                type="number"
                name="sort_order"
                value="<?= WorkspaceValue::int($node['sort_order'] ?? 100) ?>"
            >
        </div>
    <?php endif; ?>
    <?php if ($nodeId === 0 || !$treeOrganizerAvailable) : ?>
        <div class="col-12 col-md-6">
            <label class="form-label"><?= $this->escape(__('Roditeljska stranica')) ?></label>
            <select class="form-select" name="parent_id">
                <option
                    value=""
        <?= !$workspaceCanAdd && $currentParentId !== 0 ? 'disabled' : '' ?>
                ><?= $this->escape(__('Korijen stabla')) ?></option>
        <?php foreach ($nodes as $candidate) : ?>
            <?php $candidateId = WorkspaceValue::int($candidate['id'] ?? 0); ?>
            <?php
            $candidatePermissions = WorkspaceValue::stringKeyArray($candidate['permissions'] ?? null);
            $canUseCandidate = (bool)($candidatePermissions['can_add'] ?? false)
            || $candidateId === $currentParentId;
            ?>
            <?php if (
                        $candidateId !== $nodeId
                        && $canUseCandidate
                        && WorkspaceValue::string($candidate['node_type'] ?? '') === 'document'
) : ?>
                        <option
                            value="<?= $candidateId ?>"
    <?= $currentParentId === $candidateId ? 'selected' : '' ?>
                        >
    <?= $this->escape(
        str_repeat(
            '— ',
            WorkspaceValue::int($candidate['tree_depth'] ?? 0),
        ) . WorkspaceValue::string($candidate['title'] ?? ''),
    ) ?>
                        </option>
            <?php endif; ?>
        <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-12 col-md-6" data-workspace-node-types="document">
        <label class="form-label"><?= $this->escape(__('HTML dokument')) ?></label>
        <?php if ($canAttachExistingDocuments) : ?>
            <select class="form-select" name="document_key" <?= $editorAvailable ? '' : 'disabled' ?>>
                <option value=""><?= $this->escape(__('Kreiraj novi dokument ako je tip Dokument')) ?></option>
            <?php foreach ($editorDocuments as $editorDocument) : ?>
                    <option
                        value="<?= $this->escape($editorDocument['id']) ?>"
                <?= $currentDocumentKey === $editorDocument['id'] ? 'selected' : '' ?>
                    >
                <?= $this->escape($editorDocument['title']) ?>
                        (<?= $this->escape($editorDocument['id']) ?>)
                    </option>
            <?php endforeach; ?>
            </select>
        <?php else : ?>
            <input type="hidden" name="document_key" value="<?= $this->escape($currentDocumentKey) ?>">
            <?php if ($currentDocumentKey !== '') : ?>
                <input
                    class="form-control font-monospace"
                    value="<?= $this->escape($currentDocumentKey) ?>"
                    disabled
                >
            <?php else : ?>
                <div class="form-control text-body-secondary" aria-live="polite">
                <?= $this->escape(__('Novi HTML dokument bit će automatski kreiran.')) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="col-12 col-md-6" data-workspace-node-types="internal_link">
        <label class="form-label"><?= $this->escape(__('Interna named ruta')) ?></label>
        <input
            class="form-control font-monospace"
            name="route_name"
            value="<?= $this->escape(WorkspaceValue::string($node['route_name'] ?? '')) ?>"
            placeholder="calendar.index"
        >
    </div>
    <div
        class="col-12 col-md-6"
        data-workspace-node-types="internal_link external_link"
    >
        <label class="form-label"><?= $this->escape(__('Ciljni URL ili interna putanja')) ?></label>
        <input
            class="form-control"
            name="target_url"
            value="<?= $this->escape(WorkspaceValue::string($node['target_url'] ?? '')) ?>"
            placeholder="https://example.org ili /calendars"
        >
    </div>
    <div class="col-12" data-workspace-node-types="document">
        <div class="form-check">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_homepage"
                value="1"
                id="workspace-homepage-<?= $nodeId ?>"
                <?= (bool)($node['is_homepage'] ?? false) ? 'checked' : '' ?>
            >
            <label class="form-check-label" for="workspace-homepage-<?= $nodeId ?>">
                <?= $this->escape(__('Početna stranica područja')) ?>
            </label>
        </div>
    </div>
</div>
