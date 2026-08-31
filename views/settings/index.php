<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.WhiteSpace.ScopeIndent,Squiz.ControlStructures.ControlSignature,Generic.Files.LineLength

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string, mixed> $settings
 * @var string $savePath
 * @var string $settingsPath
 * @var string $allPath
 * @var string $deletedPath
 * @var string $settingsMenuActiveSection
 * @var object|null $menuRenderer
 * @var string $subjectSearchPath
 * @var string $assetsCssPath
 * @var string $assetsJsPath
 */
$creatorSubjects = [
    'user' => is_array($settings['creator_users'] ?? null) ? $settings['creator_users'] : [],
    'group' => is_array($settings['creator_groups'] ?? null) ? $settings['creator_groups'] : [],
];
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<script src="<?= $this->escape($assetsJsPath) ?>" defer></script>

<div class="row g-4">
    <aside class="col-lg-3">
        <?php require __DIR__ . '/sidebar.php'; ?>
    </aside>

    <div class="col-lg-9">
        <form
            method="post"
            action="<?= $this->escape($savePath) ?>"
            data-workspace-creator-form
            data-workspace-remove-label="<?= $this->escape(__('Ukloni')) ?>"
        >
            <section class="card">
                <div class="card-body">
                    <header class="mb-4">
                        <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                        <p class="text-body-secondary mb-0">
                            <?= $this->escape(__('Opće ponašanje URL-ova, stabla i novih područja.')) ?>
                        </p>
                    </header>

                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <div class="row g-3">
                        <div class="col-12 col-lg-5">
                            <label class="form-label" for="workspace-root-path">
                                <?= $this->escape(__('Korijenska putanja područja')) ?>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">/</span>
                                <input
                                    id="workspace-root-path"
                                    class="form-control font-monospace"
                                    name="root_path"
                                    value="<?= $this->escape(
                                        WorkspaceValue::string($settings['root_path'] ?? 'workspace'),
                                    ) ?>"
                                    required
                                >
                                <span class="input-group-text">/{workspace}/{page}</span>
                            </div>
                            <div class="form-text">
                                <?= $this->escape(__('Putanja mora imati slobodan prvi segment aplikacije.')) ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label" for="workspace-default-visibility">
                                <?= $this->escape(__('Zadana vidljivost')) ?>
                            </label>
                            <select
                                id="workspace-default-visibility"
                                class="form-select"
                                name="default_visibility"
                            >
                                <?php foreach (['restricted', 'authenticated', 'public'] as $visibility) : ?>
                                    <option
                                        value="<?= $visibility ?>"
                                    <?= WorkspaceValue::string(
                                        $settings['default_visibility'] ?? '',
                                    ) === $visibility
                                    ? 'selected'
                                    : '' ?>
                                    >
                                    <?= $this->escape(__($visibility)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label"><?= $this->escape(__('Korisničko sučelje')) ?></label>
                            <div class="form-check form-switch mb-2">
                                <input
                                    id="workspace-tree-visible"
                                    class="form-check-input"
                                    type="checkbox"
                                    name="tree_visible"
                                    value="1"
                                    <?= (bool)($settings['tree_visible'] ?? true) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="workspace-tree-visible">
                                    <?= $this->escape(__('Stablo je početno prikazano')) ?>
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input
                                    id="workspace-contents-visible"
                                    class="form-check-input"
                                    type="checkbox"
                                    name="contents_visible"
                                    value="1"
                                    <?= (bool)($settings['contents_visible'] ?? false) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="workspace-contents-visible">
                                    <?= $this->escape(__('Sadržaj stranice je početno prikazan')) ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <section aria-labelledby="workspace-creator-settings-title">
                        <h2 id="workspace-creator-settings-title" class="h5 mb-1">
                            <?= $this->escape(__('Kreiranje područja')) ?>
                        </h2>
                        <p class="text-body-secondary mb-3">
                            <?= $this->escape(
                                __(
                                    'Administratori uvijek smiju kreirati područja. Ovdje dodajte ostale '
                                    . 'korisnike ili grupe kojima to želite dopustiti.',
                                ),
                            ) ?>
                        </p>
                        <div class="row g-4">
                            <?php foreach (['user', 'group'] as $category) : ?>
                                <?php
                                $inputName = $category === 'user' ? 'creator_users' : 'creator_groups';
                                $subjects = $creatorSubjects[$category];
                                ?>
                                <div class="col-12 col-xl-6">
                                    <section data-workspace-creator-section="<?= $category ?>">
                                        <label
                                            class="form-label"
                                            for="workspace-creator-search-<?= $category ?>"
                                        >
                                            <?= $this->escape(
                                                $category === 'user' ? __('Dodaj korisnika') : __('Dodaj grupu'),
                                            ) ?>
                                        </label>
                                        <div
                                            class="workspace-subject-picker"
                                            data-workspace-subject-picker
                                            data-workspace-picker-mode="creator"
                                            data-workspace-subject-type="<?= $category ?>"
                                            data-workspace-search-url="<?= $this->escape($subjectSearchPath) ?>"
                                            data-workspace-min-query-length="2"
                                            data-workspace-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
                                            data-workspace-search-error="<?= $this->escape(
                                                __('Pretraživanje nije uspjelo.'),
                                            ) ?>"
                                        >
                                            <input
                                                id="workspace-creator-search-<?= $category ?>"
                                                class="form-control"
                                                type="search"
                                                role="combobox"
                                                autocomplete="off"
                                                aria-autocomplete="list"
                                                aria-expanded="false"
                                                aria-controls="workspace-creator-results-<?= $category ?>"
                                                placeholder="<?= $this->escape(
                                                    $category === 'user'
                                                    ? __('Upišite najmanje dva znaka korisnika')
                                                    : __('Upišite najmanje dva znaka grupe'),
                                                ) ?>"
                                                data-workspace-subject-search
                                            >
                                            <div
                                                id="workspace-creator-results-<?= $category ?>"
                                                class="workspace-subject-results list-group"
                                                role="listbox"
                                                data-workspace-subject-results
                                                hidden
                                            ></div>
                                        </div>
                                        <div class="table-responsive mt-2">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th scope="col"><?= $this->escape(__('Naziv')) ?></th>
                                                        <th scope="col" class="workspace-acl-action-column">
                                                            <span class="visually-hidden">
                                                                <?= $this->escape(__('Radnje')) ?>
                                                            </span>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody data-workspace-creator-rows="<?= $category ?>">
                                                    <?php foreach ($subjects as $subject) : ?>
                                                        <?php
                                                        if (!is_array($subject)) {
                                                            continue;
                                                        }
                                                        $subjectId = WorkspaceValue::int($subject['id'] ?? 0);
                                                        $label = WorkspaceValue::string($subject['label'] ?? '');
                                                        ?>
                                                        <tr data-workspace-creator-row="<?= $category ?>:<?= $subjectId ?>">
                                                            <th scope="row">
                                                                <?= $this->escape($label) ?>
                                                                <input
                                                                    type="hidden"
                                                                    name="<?= $inputName ?>[]"
                                                                    value="<?= $subjectId ?>"
                                                                >
                                                            </th>
                                                            <td class="text-end">
                                                                <button
                                                                    class="btn btn-sm btn-link text-danger workspace-acl-remove"
                                                                    type="button"
                                                                    data-workspace-creator-remove
                                                                    aria-label="<?= $this->escape(__('Ukloni') . ': ' . $label) ?>"
                                                                >×</button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr
                                                        data-workspace-creator-empty
                                                        <?= $subjects !== [] ? 'hidden' : '' ?>
                                                    >
                                                        <td colspan="2" class="text-body-secondary">
                                                            <?= $this->escape(__('Nema dodijeljenih subjekata.')) ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <hr class="my-4">
                    <section aria-labelledby="workspace-shorts-settings-title">
                        <h2 id="workspace-shorts-settings-title" class="h5 mb-1">
                            <?= $this->escape(__('Sažetci stranica')) ?>
                        </h2>
                        <p class="text-body-secondary mb-3">
                            <?= $this->escape(
                                __('Zadani prikaz javne zbirke isječaka objavljenih stranica svakog područja.'),
                            ) ?>
                        </p>
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="workspace-shorts-depth">
                                    <?= $this->escape(__('Prikazane razine')) ?>
                                </label>
                                <select id="workspace-shorts-depth" class="form-select" name="shorts_depth">
                                    <?php foreach ([1, 2, 3] as $depth) : ?>
                                        <option
                                            value="<?= $depth ?>"
                                        <?= WorkspaceValue::int($settings['shorts_depth'] ?? 2) === $depth
                                        ? 'selected'
                                        : '' ?>
                                        >
                                        <?= $this->escape($depth === 1
                                                ? __('Samo 1. razina')
                                                : __('Razine 1–') . $depth) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="workspace-shorts-limit">
                                    <?= $this->escape(__('Broj članaka')) ?>
                                </label>
                                <select id="workspace-shorts-limit" class="form-select" name="shorts_limit">
                                    <?php foreach ([5, 10, 25, 50] as $limit) : ?>
                                        <option
                                            value="<?= $limit ?>"
                                        <?= WorkspaceValue::int($settings['shorts_limit'] ?? 10) === $limit
                                        ? 'selected'
                                        : '' ?>
                                        ><?= $limit ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="workspace-shorts-order">
                                    <?= $this->escape(__('Redoslijed')) ?>
                                </label>
                                <select id="workspace-shorts-order" class="form-select" name="shorts_order">
                                    <?php foreach (
                                        [
                                            'hierarchy' => __('Prema hijerarhiji'),
                                            'newest' => __('Najnovije prvo'),
                                            'oldest' => __('Najstarije prvo'),
                                        ] as $order => $label
) : ?>
                                        <option
                                            value="<?= $order ?>"
    <?= WorkspaceValue::string(
        $settings['shorts_order'] ?? 'newest',
    ) === $order
    ? 'selected'
    : '' ?>
                                        ><?= $this->escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-check form-switch mt-3">
                            <input
                                id="workspace-shorts-display-options-visible"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                name="shorts_display_options_visible"
                                value="1"
                                <?= (bool)($settings['shorts_display_options_visible'] ?? false)
                                ? 'checked'
                                : '' ?>
                            >
                            <label
                                class="form-check-label"
                                for="workspace-shorts-display-options-visible"
                            >
                                <?= $this->escape(__('Opcije prikaza su početno prikazane')) ?>
                            </label>
                        </div>
                        <div class="form-text mt-2">
                            <?= $this->escape(
                                __(
                                    'Posjetitelj može privremeno promijeniti ove filtre. Opcija „Sve” '
                                    . 'dostupna je samo kada postoji manje od 100 vidljivih članaka.',
                                ),
                            ) ?>
                        </div>
                    </section>

                    <div class="alert alert-info mt-4 mb-0" role="note">
                        <strong><?= $this->escape(__('Integracija s HTML editorom')) ?></strong>
                        <div>
                            <?= $this->escape(
                                __(
                                    'Dok su Područja uključena, njihove putanje i ACL nadjačavaju '
                                    . 'samostalnu slug putanju editora.',
                                ),
                            ) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">
                        <?= $this->escape(__('Spremi postavke')) ?>
                    </button>
                </div>
            </section>
        </form>
    </div>
</div>
