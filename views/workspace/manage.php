<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.WhiteSpace.ScopeIndent,Squiz.ControlStructures.ControlSignature,Generic.Files.LineLength

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string, mixed>|null $workspace
 * @var array<string, bool> $workspacePermissions
 * @var list<array<string, mixed>> $workspaceAclSubjects
 * @var string $savePath
 * @var string $deletePath
 * @var string $aclSavePath
 * @var string $subjectSearchPath
 * @var string $indexPath
 * @var string $workspaceViewPath
 * @var string $exportPath
 * @var string $workspaceThemePath
 * @var array<string, mixed>|null $workspaceThemeState
 * @var string $workspaceThemeLabel
 * @var string $workspaceMenuPath
 * @var string $workspaceBackupPath
 * @var string $assetsCssPath
 * @var string $assetsJsPath
 */
$workspaceId = is_array($workspace ?? null) ? WorkspaceValue::int($workspace['id'] ?? 0) : 0;
$canManage = (bool)($workspacePermissions['can_manage'] ?? false);
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
$workspaceNameTranslations = WorkspaceValue::stringKeyArray($workspaceNameTranslations ?? null);
$workspaceDescriptionTranslations = WorkspaceValue::stringKeyArray($workspaceDescriptionTranslations ?? null);
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
$subjectsByCategory = ['user' => [], 'group' => []];
foreach ($workspaceAclSubjects as $subject) {
    $category = WorkspaceValue::string($subject['category'] ?? '');
    if (isset($subjectsByCategory[$category])) {
        $subjectsByCategory[$category][] = $subject;
    }
}
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>

<header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?= $this->escape($title) ?></h1>
        <p class="text-body-secondary mb-0">
            <?= $this->escape(__('Podaci područja, članovi i prava.')) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($workspaceViewPath !== '') : ?>
            <a class="btn btn-primary" href="<?= $this->escape($workspaceViewPath) ?>">
                <?= $this->escape(__('Otvori područje')) ?>
            </a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= $this->escape($indexPath) ?>">
            <?= $this->escape(__('Područja')) ?>
        </a>
    </div>
</header>

<?php if ($workspaceId === 0 || $canManage) : ?>
    <section class="card mb-4" aria-labelledby="workspace-data-title">
        <div class="card-body">
            <h2 id="workspace-data-title" class="h5 mb-3">
                <?= $this->escape(__('Podaci područja')) ?>
            </h2>
            <form method="post" action="<?= $this->escape($savePath) ?>">
                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                <input type="hidden" name="id" value="<?= $workspaceId ?>">
                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <label class="form-label" for="workspace-name-<?= $activeLanguage ?>">
                            <?= $this->escape(__('Naziv')) ?>
                        </label>
                        <div class="input-group" data-workspace-translation-group>
                            <button
                                class="btn btn-outline-secondary dropdown-toggle workspace-locale-button"
                                type="button"
                                data-bs-toggle="dropdown"
                                data-workspace-translation-button
                                data-current-locale="<?= $this->escape($activeLanguage) ?>"
                                aria-label="<?= $this->escape(__('Jezik naziva')) ?>"
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
                                    id="workspace-name-<?= $supportedLanguage ?>"
                                    class="form-control<?= $supportedLanguage === $activeLanguage ? '' : ' d-none' ?>"
                                    name="name_translations[<?= $supportedLanguage ?>]"
                                    value="<?= $this->escape(WorkspaceValue::string(
                                        $workspaceNameTranslations[$supportedLanguage] ?? '',
                                    )) ?>"
                                    data-workspace-translation-panel="<?= $supportedLanguage ?>"
                                    <?= $supportedLanguage === $primaryLanguage ? 'required' : '' ?>
                                >
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            <?= $this->escape(sprintf(
                                __('Naziv na primarnom jeziku (%s) je obvezan i koristi se kao zamjenski.'),
                                $primaryLanguage,
                            )) ?>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <label class="form-label" for="workspace-slug">
                            <?= $this->escape(__('Slug')) ?>
                        </label>
                        <input
                            id="workspace-slug"
                            class="form-control font-monospace"
                            name="slug"
                            value="<?= $this->escape(WorkspaceValue::string($workspace['slug'] ?? '')) ?>"
                        >
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="workspace-description-<?= $activeLanguage ?>">
                            <?= $this->escape(__('Opis')) ?>
                        </label>
                        <div data-workspace-translation-group>
                            <div class="dropdown mb-2">
                            <button
                                class="btn btn-outline-secondary dropdown-toggle workspace-locale-button"
                                type="button"
                                data-bs-toggle="dropdown"
                                data-workspace-translation-button
                                data-current-locale="<?= $this->escape($activeLanguage) ?>"
                                aria-label="<?= $this->escape(__('Jezik opisa')) ?>"
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
                            </div>
                            <?php foreach ($supportedLanguages as $supportedLanguage) : ?>
                                <textarea
                                    id="workspace-description-<?= $supportedLanguage ?>"
                                    class="form-control<?= $supportedLanguage === $activeLanguage ? '' : ' d-none' ?>"
                                    name="description_translations[<?= $supportedLanguage ?>]"
                                    rows="2"
                                    data-workspace-translation-panel="<?= $supportedLanguage ?>"
                                ><?= $this->escape(WorkspaceValue::string(
                                    $workspaceDescriptionTranslations[$supportedLanguage] ?? '',
                                )) ?></textarea>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <fieldset>
                            <legend class="h6 mb-2"><?= $this->escape(__('Zadani prikaz')) ?></legend>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="workspace-tree-visibility">
                                        <?= $this->escape(__('Stablo stranica')) ?>
                                    </label>
                                    <select id="workspace-tree-visibility" class="form-select" name="tree_visibility">
                                        <?php foreach (
                                            [
                                                'inherit' => __('Naslijedi sistemsku postavku'),
                                                'shown' => __('Prikaži'),
                                                'hidden' => __('Sakrij'),
                                            ] as $policy => $label
) : ?>
                                            <option value="<?= $policy ?>" <?= WorkspaceValue::string(
                                                $workspace['tree_visibility'] ?? 'inherit',
                                            ) === $policy ? 'selected' : '' ?>>
                                                <?= $this->escape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="workspace-contents-visibility">
                                        <?= $this->escape(__('Sadržaj stranice')) ?>
                                    </label>
                                    <select id="workspace-contents-visibility" class="form-select" name="contents_visibility">
                                        <?php foreach (
                                            [
                                                'inherit' => __('Naslijedi sistemsku postavku'),
                                                'shown' => __('Prikaži'),
                                                'hidden' => __('Sakrij'),
                                            ] as $policy => $label
) : ?>
                                            <option value="<?= $policy ?>" <?= WorkspaceValue::string(
                                                $workspace['contents_visibility'] ?? 'inherit',
                                            ) === $policy ? 'selected' : '' ?>>
                                                <?= $this->escape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text mt-2">
                                <?= $this->escape(
                                    __('Postavka pojedine stranice nadjačava zadani prikaz sadržaja područja.'),
                                ) ?>
                            </div>
                            <div class="form-text mt-1">
                                <?= $this->escape(
                                    __(
                                        'Kada je aktivan poseban lijevi meni, stablo je početno skriveno '
                                        . 'i može se ponovno otvoriti njegovom ikonom.',
                                    ),
                                ) ?>
                            </div>
                        </fieldset>
                    </div>
                    <?php if ($workspaceId > 0) : ?>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input
                                    id="workspace-archived"
                                    class="form-check-input"
                                    type="checkbox"
                                    name="is_archived"
                                    value="1"
                                    <?= (bool)($workspace['is_archived'] ?? false) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="workspace-archived">
                                    <?= $this->escape(__('Arhivirano područje je samo za čitanje')) ?>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="col-12 d-flex align-items-center justify-content-between gap-3">
                        <?php if ($exportPath !== '') : ?>
                            <a
                                class="btn btn-primary btn-sm workspace-export-icon-action"
                                href="<?= $this->escape($exportPath) ?>"
                                title="<?= $this->escape(__('Izvezi područje u HTML')) ?>"
                                aria-label="<?= $this->escape(__('Izvezi područje u HTML')) ?>"
                            >
                                <svg
                                    class="workspace-export-icon-action__icon"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                    focusable="false"
                                >
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <path d="M7 10l5 5 5-5M12 15V3"/>
                                </svg>
                            </a>
                        <?php else : ?>
                            <span aria-hidden="true"></span>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit">
                            <?= $this->escape(__('Spremi')) ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($workspaceId > 0) : ?>
    <?php if ($canManage && $workspaceThemePath !== '') : ?>
        <?php
        $workspaceThemeSelection = WorkspaceValue::string($workspaceThemeState['selection_type'] ?? 'default');
        ?>
        <section class="card mb-4" aria-labelledby="workspace-theme-title">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h2 id="workspace-theme-title" class="h5 mb-1">
                        <?= $this->escape(__('Tema područja')) ?>
                    </h2>
                    <p class="text-body-secondary mb-0">
                        <?php if ($workspaceThemeSelection === 'default') : ?>
                            <?= $this->escape(__('Područje koristi zadanu sistemsku temu.')) ?>
                        <?php elseif ($workspaceThemeSelection === 'custom') : ?>
                            <?= $this->escape(__('Privatna tema područja')) ?>:
                            <?= $this->escape($workspaceThemeLabel) ?>
                        <?php else : ?>
                            <?= $this->escape(__('Odabrana sistemska tema')) ?>:
                            <?= $this->escape($workspaceThemeLabel) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a class="btn btn-primary" href="<?= $this->escape($workspaceThemePath) ?>">
                    <?= $this->escape(__('Uredi temu područja')) ?>
                </a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManage && $workspaceMenuPath !== '') : ?>
        <section class="card mb-4" aria-labelledby="workspace-menu-title">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h2 id="workspace-menu-title" class="h5 mb-1">
                        <?= $this->escape(__('Posebni meniji područja')) ?>
                    </h2>
                    <p class="text-body-secondary mb-0">
                        <?= $this->escape(__(
                            'Uredite posebni gornji i lijevi meni samo za ovo područje.',
                        )) ?>
                    </p>
                </div>
                <a class="btn btn-primary" href="<?= $this->escape($workspaceMenuPath) ?>">
                    <?= $this->escape(__('Uredi menije područja')) ?>
                </a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManage && $workspaceBackupPath !== '') : ?>
        <section class="card mb-4" aria-labelledby="workspace-backup-title">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h2 id="workspace-backup-title" class="h5 mb-1">
                        <?= $this->escape(__('Backup područja')) ?>
                    </h2>
                    <p class="text-body-secondary mb-0">
                        <?= $this->escape(__(
                            'Izvezite ili vratite sadržaj, povijest, prava, temu i posebne menije ovog područja.',
                        )) ?>
                    </p>
                </div>
                <a class="btn btn-primary" href="<?= $this->escape($workspaceBackupPath) ?>">
                    <?= $this->escape(__('Upravljaj backupom područja')) ?>
                </a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManage) : ?>
        <section class="card mb-4" aria-labelledby="workspace-acl-title">
            <div class="card-body">
                <h2 id="workspace-acl-title" class="h5 mb-1">
                    <?= $this->escape(__('Članovi i prava')) ?>
                </h2>
                <p class="small text-body-secondary mb-3">
                    <?= $this->escape(
                        __(
                            'Prava korisnika i njegovih grupa se zbrajaju. '
                            . 'Dodajte samo potrebne subjekte; upravljanje uključuje sva prava.',
                        ),
                    ) ?>
                </p>
                <form
                    method="post"
                    action="<?= $this->escape($aclSavePath) ?>"
                    data-workspace-acl-form
                    data-workspace-remove-label="<?= $this->escape(__('Ukloni')) ?>"
                    data-workspace-built-in-label="<?= $this->escape(__('Ugrađeno')) ?>"
                    <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                        data-workspace-permission-<?= str_replace('_', '-', $permission) ?>-label="<?= $this->escape(
                            __($permission),
                        ) ?>"
                    <?php endforeach; ?>
                >
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">

                    <?php foreach (['user', 'group'] as $category) : ?>
                        <?php $subjects = $subjectsByCategory[$category]; ?>
                        <section
                            class="workspace-acl-subject-section mt-3"
                            data-workspace-acl-section="<?= $category ?>"
                        >
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-12 col-lg-5">
                                    <label
                                        class="form-label"
                                        for="workspace-acl-search-<?= $category ?>"
                                    >
                                        <?= $this->escape(
                                            $category === 'user' ? __('Dodaj korisnika') : __('Dodaj grupu'),
                                        ) ?>
                                    </label>
                                    <div
                                        class="workspace-subject-picker"
                                        data-workspace-subject-picker
                                        data-workspace-picker-mode="acl"
                                        data-workspace-min-query-length="2"
                                        data-workspace-subject-type="<?= $category ?>"
                                        data-workspace-search-url="<?= $this->escape($subjectSearchPath) ?>"
                                        data-workspace-id="<?= $workspaceId ?>"
                                        data-workspace-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
                                        data-workspace-search-error="<?= $this->escape(
                                            __('Pretraživanje nije uspjelo.'),
                                        ) ?>"
                                    >
                                        <input
                                            id="workspace-acl-search-<?= $category ?>"
                                            class="form-control"
                                            type="search"
                                            role="combobox"
                                            autocomplete="off"
                                            aria-autocomplete="list"
                                            aria-expanded="false"
                                            aria-controls="workspace-acl-results-<?= $category ?>"
                                            placeholder="<?= $this->escape(
                                                $category === 'user'
                                                ? __('Pretraži korisnike')
                                                : __('Pretraži grupe'),
                                            ) ?>"
                                            data-workspace-subject-search
                                        >
                                        <div
                                            id="workspace-acl-results-<?= $category ?>"
                                            class="workspace-subject-results list-group"
                                            role="listbox"
                                            data-workspace-subject-results
                                            hidden
                                        ></div>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-7">
                                    <p class="small text-body-secondary mb-1">
                                        <?= $this->escape(
                                            $category === 'user'
                                            ? __('Pretražite po imenu ili korisničkoj oznaci.')
                                            : __(
                                                'Javno i Svi prijavljeni ugrađene su publike, '
                                                . 'a ne Auth grupe.',
                                            ),
                                        ) ?>
                                    </p>
                                </div>
                            </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle workspace-acl-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?= $this->escape(__('Naziv')) ?></th>
                                        <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                            <th scope="col" class="text-center">
                                                <?= $this->escape(__($permission)) ?>
                                            </th>
                                        <?php endforeach; ?>
                                        <th scope="col" class="workspace-acl-action-column">
                                            <span class="visually-hidden"><?= $this->escape(__('Radnje')) ?></span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody data-workspace-acl-rows="<?= $category ?>">
                                    <?php foreach ($subjects as $subject) : ?>
                                        <?php
                                        $subjectType = WorkspaceValue::string($subject['subject_type'] ?? '');
                                        $subjectId = WorkspaceValue::int($subject['subject_id'] ?? 0);
                                        $label = WorkspaceValue::string($subject['label'] ?? '');
                                        $builtIn = (bool)($subject['is_builtin'] ?? false);
                                        $publicReadOnly = (bool)($subject['is_read_only'] ?? false);
                                        ?>
                                        <tr data-workspace-acl-row="<?= $subjectType ?>:<?= $subjectId ?>">
                                            <th scope="row">
                                                <?= $this->escape($label) ?>
                                                <?php if ($builtIn) : ?>
                                                    <span class="badge text-bg-secondary ms-1">
                                                        <?= $this->escape(__('Ugrađeno')) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </th>
                                            <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                                <td class="text-center">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="acl[<?= $subjectType ?>][<?= $subjectId ?>][<?= $permission ?>]"
                                                        value="1"
                                                        <?= $publicReadOnly && $permission !== 'can_view' ? 'disabled' : '' ?>
                                                        aria-label="<?= $this->escape(
                                                            __($permission) . ': ' . $label,
                                                        ) ?>"
                                                        <?= (bool)($subject[$permission] ?? false) ? 'checked' : '' ?>
                                                    >
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-end">
                                                <button
                                                    class="btn btn-sm btn-link text-danger workspace-acl-remove"
                                                    type="button"
                                                    title="<?= $this->escape(__('Ukloni')) ?>"
                                                    aria-label="<?= $this->escape(__('Ukloni') . ': ' . $label) ?>"
                                                    data-workspace-acl-remove
                                                >
                                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                                        <path d="M6 6l12 12M18 6L6 18"></path>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr
                                        class="workspace-acl-empty"
                                        data-workspace-acl-empty
                                        <?= $subjects !== [] ? 'hidden' : '' ?>
                                    >
                                        <td colspan="8" class="text-body-secondary">
                                            <?= $this->escape(__('Nema dodijeljenih subjekata.')) ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        </section>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-end">
                        <button class="btn btn-primary" type="submit">
                            <?= $this->escape(__('Spremi prava')) ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card mb-4" aria-labelledby="workspace-delete-title">
            <div class="card-body">
                <h2 id="workspace-delete-title" class="h5 mb-2">
                    <?= $this->escape(__('Brisanje područja')) ?>
                </h2>
                <p class="text-body-secondary mb-3">
                    <?= $this->escape(
                        __('Područje se soft-briše i administrator ga može vratiti iz postavki.'),
                    ) ?>
                </p>
                <form
                    method="post"
                    action="<?= $this->escape($deletePath) ?>"
                    onsubmit="return confirm('<?= $this->escape(__('Obrisati ovo područje?')) ?>')"
                >
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                    <button class="btn btn-danger" type="submit">
                        <?= $this->escape(__('Obriši područje')) ?>
                    </button>
                </form>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
