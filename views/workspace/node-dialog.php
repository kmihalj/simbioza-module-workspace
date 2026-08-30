<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.Files.LineLength,Generic.WhiteSpace.ScopeIndent

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $workspace
 * @var list<array<string, mixed>> $restrictionSubjects
 * @var list<array<string, mixed>> $directPermissionSubjects
 * @var bool $canManagePagePermissions
 * @var array<string, mixed> $node
 * @var list<array<string, mixed>> $nodes
 * @var list<array{id:string,title:string}> $editorDocuments
 * @var bool $editorAvailable
 * @var bool $canAttachExistingDocuments
 * @var string $nodeSavePath
 * @var string $nodeDeletePath
 * @var string $nodeAclSavePath
 * @var string $nodeDirectPermissionSavePath
 * @var string $subjectSearchPath
 * @var int $returnNodeId
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$nodeId = WorkspaceValue::int($node['id'] ?? 0);
$permissions = WorkspaceValue::stringKeyArray($node['permissions'] ?? null);
$restrictions = WorkspaceValue::rows($node['restrictions'] ?? null);
$canManageRestrictions = $canManagePagePermissions;
$hasDirectRestrictions = array_filter(
    $restrictions,
    static fn(array $row): bool =>
        WorkspaceValue::string($row['subject_type'] ?? '') === 'user',
) !== [];

/**
 * HR: Provjerava jedno spremljeno pravo u ACL recima čvora.
 * EN: Checks one stored permission in the node ACL rows.
 *
 * @param list<array<string, mixed>> $rows
 */
$hasPermission = static function (
    array $rows,
    string $subjectType,
    int $subjectId,
    string $permission,
): bool {
    foreach ($rows as $row) {
        $row = WorkspaceValue::stringKeyArray($row);
        if (
            WorkspaceValue::string($row['subject_type'] ?? '') === $subjectType
            && WorkspaceValue::int($row['subject_id'] ?? 0) === $subjectId
        ) {
            return (bool)($row[$permission] ?? false);
        }
    }

    return false;
};

?>
<div class="modal-header">
    <div>
        <h2 class="modal-title fs-5 mb-0">
            <?= $this->escape(WorkspaceValue::string($node['title'] ?? '')) ?>
        </h2>
        <p class="small text-body-secondary mb-0">
            <?= $this->escape(__('Postavke stavke stabla/stranice')) ?>
        </p>
    </div>
    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="modal"
        aria-label="<?= $this->escape(__('Zatvori')) ?>"
    ></button>
</div>
<div class="modal-body">
    <?php if ((bool)($permissions['can_edit'] ?? false)) : ?>
        <form method="post" action="<?= $this->escape($nodeSavePath) ?>">
            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
            <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
            <input type="hidden" name="id" value="<?= $nodeId ?>">
            <input type="hidden" name="return_context" value="workspace">
            <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
            <?= $this->forModulePartial(
                'aaieduhr/heartphrame-module-workspace',
                'workspace/node-fields',
                [
                    'node' => $node,
                    'nodes' => $nodes,
                    'editorDocuments' => $editorDocuments,
                    'editorAvailable' => $editorAvailable,
                    'canAttachExistingDocuments' => $canAttachExistingDocuments,
                    'workspaceCanAdd' => true,
                    'treeOrganizerAvailable' => true,
                    'activeLanguage' => $activeLanguage ?? 'hr',
                    'primaryLanguage' => $primaryLanguage ?? 'hr',
                    'supportedLanguages' => $supportedLanguages ?? ['hr'],
                    'localeFlagPaths' => $localeFlagPaths ?? [],
                ],
            ) ?>
            <?php if (WorkspaceValue::string($node['node_type'] ?? '') === 'document') : ?>
                <div class="mt-3">
                    <label class="form-label" for="workspace-node-labels">
                        <?= $this->escape(__('Oznake stranice')) ?>
                    </label>
                    <input
                        id="workspace-node-labels"
                        class="form-control"
                        name="labels"
                        value="<?= $this->escape(implode(', ', array_values(array_filter(
                            is_array($node['labels'] ?? null) ? $node['labels'] : [],
                            is_string(...),
                        )))) ?>"
                        placeholder="2026, projekt, pristupačnost"
                    >
                </div>
                <fieldset class="mt-3" data-workspace-page-properties>
                    <legend class="h6 mb-2"><?= $this->escape(__('Svojstva stranice')) ?></legend>
                    <?php
                    $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
                    if ($properties === []) {
                        $properties[] = ['label' => '', 'type' => 'text', 'value' => ''];
                    }
                    ?>
                    <div class="vstack gap-2" data-workspace-page-property-rows>
                        <?php foreach ($properties as $property) : ?>
                            <?= $this->forModulePartial(
                                'aaieduhr/heartphrame-module-workspace',
                                'workspace/node-property-row',
                                ['property' => is_array($property) ? $property : []],
                            ) ?>
                        <?php endforeach; ?>
                    </div>
                    <button
                        class="btn btn-outline-primary btn-sm mt-2"
                        type="button"
                        data-workspace-page-property-add
                        title="<?= $this->escape(__('Dodaj svojstvo')) ?>"
                        aria-label="<?= $this->escape(__('Dodaj svojstvo')) ?>"
                    >
                        <span aria-hidden="true">+</span>
                    </button>
                    <template data-workspace-page-property-template>
                        <?= $this->forModulePartial(
                            'aaieduhr/heartphrame-module-workspace',
                            'workspace/node-property-row',
                            ['property' => ['label' => '', 'type' => 'text', 'value' => '']],
                        ) ?>
                    </template>
                </fieldset>
                <div class="mt-3">
                    <label class="form-label" for="workspace-node-contents-visibility">
                        <?= $this->escape(__('Zadani prikaz sadržaja stranice')) ?>
                    </label>
                    <select
                        id="workspace-node-contents-visibility"
                        class="form-select"
                        name="contents_visibility"
                    >
                        <?php foreach (
                            [
                                'inherit' => __('Naslijedi postavku područja'),
                                'shown' => __('Prikaži'),
                                'hidden' => __('Sakrij'),
                            ] as $policy => $label
) : ?>
                            <option value="<?= $policy ?>" <?= WorkspaceValue::string(
                                $node['contents_visibility'] ?? 'inherit',
                            ) === $policy ? 'selected' : '' ?>>
                                <?= $this->escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-end mt-3">
                <button class="btn btn-primary" type="submit">
                    <?= $this->escape(__('Spremi stavku')) ?>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($canManageRestrictions) : ?>
        <hr class="my-4">
        <section aria-labelledby="workspace-node-acl-title">
            <form
                method="post"
                action="<?= $this->escape($nodeAclSavePath) ?>"
                data-workspace-restriction-form
                data-workspace-remove-label="<?= $this->escape(__('Ukloni')) ?>"
                <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                    data-workspace-permission-<?= str_replace('_', '-', $permission) ?>-label="<?= $this->escape(__($permission)) ?>"
                <?php endforeach; ?>
            >
            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
            <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
            <input type="hidden" name="node_id" value="<?= $nodeId ?>">
            <input type="hidden" name="return_context" value="workspace">
            <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
            <h3 id="workspace-node-acl-title" class="h6">
                <?= $this->escape(__('Ograničenja naslijeđenih prava')) ?>
            </h3>
            <p class="small text-body-secondary mb-2">
                <?= $this->escape(
                    __(
                        'Ovdje se odabranom korisniku mogu uskratiti prava koja već ima na području '
                        . 'izravno ili članstvom u grupi. Ograničenje vrijedi na ovoj stranici i svim '
                        . 'njezinim podređenim stranicama; njime se ne mogu dodijeliti nova prava.',
                    ),
                ) ?>
            </p>
            <p class="small text-body-secondary mb-3">
                <?= $this->escape(
                    __(
                        'U popisu su samo korisnici s postojećim pravima na područje. Grupe se ovdje '
                        . 'ne ograničavaju: ograničenje se uvijek odnosi na odabranog korisnika.',
                    ),
                ) ?>
            </p>
            <div class="workspace-node-acl-legend small mb-3" aria-label="<?= $this->escape(
                __('Legenda ovlasti'),
            ) ?>">
                <span>
                    <span class="workspace-acl-legend-swatch workspace-acl-legend-inherited"></span>
                    <?= $this->escape(__('Pravo je naslijeđeno i zadržano')) ?>
                </span>
                <span>
                    <span class="workspace-acl-legend-swatch workspace-acl-legend-denied"></span>
                    <?= $this->escape(__('Naslijeđeno pravo je uskraćeno')) ?>
                </span>
                <span>
                    <span class="workspace-acl-legend-swatch workspace-acl-legend-unavailable"></span>
                    <?= $this->escape(__('Korisnik nema to naslijeđeno pravo')) ?>
                </span>
            </div>
            <?php if (!$hasDirectRestrictions) : ?>
                <div class="alert alert-success py-2" role="note">
                    <?= $this->escape(
                        __('Na ovoj stranici trenutačno nema korisničkih ograničenja.'),
                    ) ?>
                </div>
            <?php endif; ?>

            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="workspace-restriction-user-search">
                        <?= $this->escape(__('Dodaj korisnika')) ?>
                    </label>
                    <div
                        class="workspace-subject-picker"
                        data-workspace-subject-picker
                        data-workspace-picker-mode="restriction"
                        data-workspace-min-query-length="2"
                        data-workspace-subject-type="user"
                        data-workspace-search-url="<?= $this->escape($subjectSearchPath) ?>"
                        data-workspace-id="<?= $workspaceId ?>"
                        data-workspace-node-id="<?= $nodeId ?>"
                        data-workspace-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
                        data-workspace-search-error="<?= $this->escape(__('Pretraživanje nije uspjelo.')) ?>"
                    >
                        <input
                            id="workspace-restriction-user-search"
                            class="form-control"
                            type="search"
                            role="combobox"
                            autocomplete="off"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="workspace-restriction-user-results"
                            placeholder="<?= $this->escape(__('Pretraži korisnike s pravima na području')) ?>"
                            data-workspace-subject-search
                        >
                        <div
                            id="workspace-restriction-user-results"
                            class="workspace-subject-results list-group"
                            role="listbox"
                            data-workspace-subject-results
                            hidden
                        ></div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <p class="small text-body-secondary mb-1">
                        <?= $this->escape(
                            __('Odaberite korisnika pa isključite prava koja mu želite uskratiti.'),
                        ) ?>
                    </p>
                </div>
            </div>

            <div class="table-responsive mb-2">
                <table class="table table-sm align-middle workspace-acl-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= $this->escape(__('Korisnik')) ?></th>
                            <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                <th scope="col" class="text-center"><?= $this->escape(__($permission)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="workspace-acl-action-column">
                                <span class="visually-hidden"><?= $this->escape(__('Radnje')) ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody data-workspace-restriction-rows>
                        <?php foreach ($restrictionSubjects as $subject) : ?>
                            <?php
                            $subjectId = WorkspaceValue::int($subject['subject_id'] ?? 0);
                            $label = WorkspaceValue::string($subject['label'] ?? '');
                            ?>
                            <tr data-workspace-restriction-row="<?= $subjectId ?>">
                                <th scope="row">
                                    <?= $this->escape($label) ?>
                                    <input type="hidden" name="acl[user][<?= $subjectId ?>][_selected]" value="1">
                                </th>
                                <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                    <?php
                                    $inherited = (bool)($subject[$permission] ?? false);
                                    $allowed = $hasPermission(
                                        $restrictions,
                                        'user',
                                        $subjectId,
                                        $permission,
                                    );
                                    ?>
                                    <td class="text-center">
                                        <?php if ($inherited) : ?>
                                            <label class="workspace-node-restriction-toggle">
                                                <input
                                                    class="visually-hidden"
                                                    type="checkbox"
                                                    name="acl[user][<?= $subjectId ?>][<?= $permission ?>]"
                                                    value="1"
                                                    data-workspace-restriction-permission="<?= $permission ?>"
                                                    <?= $allowed ? 'checked' : '' ?>
                                                    aria-label="<?= $this->escape(
                                                        __($permission) . ': ' . $label,
                                                    ) ?>"
                                                >
                                                <span aria-hidden="true"></span>
                                            </label>
                                        <?php else : ?>
                                            <span
                                                class="workspace-node-restriction-unavailable"
                                                title="<?= $this->escape(__('Korisnik nema to naslijeđeno pravo')) ?>"
                                                aria-label="<?= $this->escape(
                                                    __('Korisnik nema to naslijeđeno pravo')
                                                    . ' - '
                                                    . __($permission)
                                                    . ': '
                                                    . $label,
                                                ) ?>"
                                            ></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-end">
                                    <button
                                        class="btn btn-sm btn-link text-danger workspace-acl-remove"
                                        type="button"
                                        title="<?= $this->escape(__('Ukloni')) ?>"
                                        aria-label="<?= $this->escape(__('Ukloni') . ': ' . $label) ?>"
                                        data-workspace-restriction-remove
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
                            data-workspace-restriction-empty
                            <?= $restrictionSubjects !== [] ? 'hidden' : '' ?>
                        >
                            <td colspan="8" class="text-body-secondary">
                                <?= $this->escape(__('Nema korisničkih ograničenja.')) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <p class="small text-body-secondary mb-0">
                    <?= $this->escape(
                        __('Uklonite korisnika iz tablice za potpuno vraćanje njegovih naslijeđenih prava.'),
                    ) ?>
                </p>
                <button class="btn btn-secondary" type="submit">
                    <?= $this->escape(__('Spremi ograničenja')) ?>
                </button>
            </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($canManagePagePermissions) : ?>
        <hr class="my-4">
        <section aria-labelledby="workspace-node-direct-permissions-title">
            <h3 id="workspace-node-direct-permissions-title" class="h6">
                <?= $this->escape(__('Izravna dopuštenja korisnicima')) ?>
            </h3>
            <p class="small text-body-secondary mb-2">
                <?= $this->escape(
                    __(
                        'Izravno dopuštenje vrijedi samo za ovu stranicu i ne nasljeđuju ga '
                        . 'podređene stranice. Korisniku bez pristupa području područje će se '
                        . 'pojaviti na popisu, ali će u njemu vidjeti samo izravno dopuštene stranice.',
                    ),
                ) ?>
            </p>
            <p class="small text-body-secondary mb-3">
                <?= $this->escape(
                    __(
                        'Ovdje se dodaju samo korisnici, ne i grupe. Uređivanje i objavljivanje '
                        . 'automatski uključuju čitanje.',
                    ),
                ) ?>
            </p>
            <form
                method="post"
                action="<?= $this->escape($nodeDirectPermissionSavePath) ?>"
                data-workspace-direct-permission-form
                data-workspace-remove-label="<?= $this->escape(__('Ukloni')) ?>"
                data-workspace-permission-can-view-label="<?= $this->escape(__('Čitanje')) ?>"
                data-workspace-permission-can-edit-label="<?= $this->escape(__('Uređivanje')) ?>"
                data-workspace-permission-can-publish-label="<?= $this->escape(__('Objavljivanje')) ?>"
            >
                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                <input type="hidden" name="node_id" value="<?= $nodeId ?>">
                <input type="hidden" name="return_context" value="workspace">
                <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">

                <div class="row g-2 align-items-end mb-2">
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="workspace-direct-permission-user-search">
                            <?= $this->escape(__('Dodaj korisnika')) ?>
                        </label>
                        <div
                            class="workspace-subject-picker"
                            data-workspace-subject-picker
                            data-workspace-picker-mode="direct-permission"
                            data-workspace-min-query-length="2"
                            data-workspace-subject-type="user"
                            data-workspace-search-url="<?= $this->escape($subjectSearchPath) ?>"
                            data-workspace-id="<?= $workspaceId ?>"
                            data-workspace-no-results="<?= $this->escape(__('Nema rezultata.')) ?>"
                            data-workspace-search-error="<?= $this->escape(__('Pretraživanje nije uspjelo.')) ?>"
                        >
                            <input
                                id="workspace-direct-permission-user-search"
                                class="form-control"
                                type="search"
                                role="combobox"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-expanded="false"
                                aria-controls="workspace-direct-permission-user-results"
                                placeholder="<?= $this->escape(__('Pretraži korisnike')) ?>"
                                data-workspace-subject-search
                            >
                            <div
                                id="workspace-direct-permission-user-results"
                                class="workspace-subject-results list-group"
                                role="listbox"
                                data-workspace-subject-results
                                hidden
                            ></div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <p class="small text-body-secondary mb-1">
                            <?= $this->escape(__('Pretražite po imenu ili korisničkoj oznaci.')) ?>
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle workspace-acl-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= $this->escape(__('Korisnik')) ?></th>
                                <?php foreach (['can_view' => __('Čitanje'), 'can_edit' => __('Uređivanje'), 'can_publish' => __('Objavljivanje')] as $permission => $label) : ?>
                                    <th scope="col" class="text-center">
                                        <?= $this->escape($label) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th scope="col" class="workspace-acl-action-column">
                                    <span class="visually-hidden"><?= $this->escape(__('Radnje')) ?></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody data-workspace-direct-permission-rows>
                            <?php foreach ($directPermissionSubjects as $subject) : ?>
                                <?php
                                $userId = WorkspaceValue::int($subject['user_id'] ?? 0);
                                $label = WorkspaceValue::string($subject['label'] ?? '');
                                ?>
                                <tr data-workspace-direct-permission-row="<?= $userId ?>">
                                    <th scope="row"><?= $this->escape($label) ?></th>
                                    <?php foreach (['can_view', 'can_edit', 'can_publish'] as $permission) : ?>
                                        <td class="text-center">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="direct_permissions[<?= $userId ?>][<?= $permission ?>]"
                                                value="1"
                                                <?= (bool)($subject[$permission] ?? false) ? 'checked' : '' ?>
                                                aria-label="<?= $this->escape(__($permission) . ': ' . $label) ?>"
                                            >
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-end">
                                        <button
                                            class="btn btn-sm btn-link text-danger workspace-acl-remove"
                                            type="button"
                                            title="<?= $this->escape(__('Ukloni')) ?>"
                                            aria-label="<?= $this->escape(__('Ukloni') . ': ' . $label) ?>"
                                            data-workspace-direct-permission-remove
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
                                data-workspace-direct-permission-empty
                                <?= $directPermissionSubjects !== [] ? 'hidden' : '' ?>
                            >
                                <td colspan="5" class="text-body-secondary">
                                    <?= $this->escape(__('Nema izravnih dopuštenja.')) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button class="btn btn-primary" type="submit">
                        <?= $this->escape(__('Spremi izravna dopuštenja')) ?>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ((bool)($permissions['can_delete'] ?? false)) : ?>
        <hr class="my-4">
        <form
            class="d-flex align-items-center justify-content-between gap-3"
            method="post"
            action="<?= $this->escape($nodeDeletePath) ?>"
            onsubmit="return confirm('<?= $this->escape(
                __('Obrisati stranicu i cijelu njezinu podgranu?'),
            ) ?>')"
        >
            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
            <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
            <input type="hidden" name="node_id" value="<?= $nodeId ?>">
            <input type="hidden" name="return_context" value="workspace">
            <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
            <p class="small text-body-secondary mb-0">
                <?= $this->escape(__('Brisanje obuhvaća i sve podređene stavke.')) ?>
            </p>
            <button class="btn btn-danger" type="submit">
                <?= $this->escape(__('Obriši podgranu')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        <?= $this->escape(__('Zatvori')) ?>
    </button>
</div>
