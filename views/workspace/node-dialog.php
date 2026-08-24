<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.Files.LineLength,Generic.WhiteSpace.ScopeIndent

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $workspace
 * @var list<array<string, mixed>> $workspaceAclSubjects
 * @var array<string, mixed> $node
 * @var list<array<string, mixed>> $nodes
 * @var list<array{id:string,title:string}> $editorDocuments
 * @var bool $editorAvailable
 * @var bool $canAttachExistingDocuments
 * @var string $nodeSavePath
 * @var string $nodeDeletePath
 * @var string $nodeAclSavePath
 * @var int $returnNodeId
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$nodeId = WorkspaceValue::int($node['id'] ?? 0);
$permissions = WorkspaceValue::stringKeyArray($node['permissions'] ?? null);
$restrictions = WorkspaceValue::rows($node['restrictions'] ?? null);
$canManageRestrictions = (bool)($permissions['can_manage'] ?? false);
$hasDirectRestrictions = $restrictions !== [];

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
            <?= $this->escape(__('Postavke stavke stabla')) ?>
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
                <fieldset class="mt-3">
                    <legend class="h6 mb-2"><?= $this->escape(__('Svojstva stranice')) ?></legend>
                    <div class="vstack gap-2">
                        <?php
                        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
                        $properties[] = ['label' => '', 'type' => 'text', 'value' => ''];
                        ?>
                        <?php foreach ($properties as $property) : ?>
                            <?php $property = is_array($property) ? $property : []; ?>
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-4">
                                    <label class="form-label small"><?= $this->escape(__('Naziv svojstva')) ?></label>
                                    <input class="form-control" name="properties[label][]" value="<?= $this->escape(WorkspaceValue::string($property['label'] ?? '')) ?>">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small"><?= $this->escape(__('Vrsta')) ?></label>
                                    <select class="form-select" name="properties[type][]">
                                        <?php foreach (['text', 'status', 'number', 'date', 'user', 'link'] as $type) : ?>
                                            <option value="<?= $type ?>" <?= WorkspaceValue::string($property['type'] ?? 'text') === $type ? 'selected' : '' ?>><?= $this->escape(__($type)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label small"><?= $this->escape(__('Vrijednost')) ?></label>
                                    <input class="form-control" name="properties[value][]" value="<?= $this->escape(WorkspaceValue::string($property['value'] ?? '')) ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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

    <?php if ($workspaceAclSubjects !== []) : ?>
        <hr class="my-4">
        <?php if ($canManageRestrictions) : ?>
            <form method="post" action="<?= $this->escape($nodeAclSavePath) ?>">
            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
            <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
            <input type="hidden" name="node_id" value="<?= $nodeId ?>">
            <input type="hidden" name="return_context" value="workspace">
            <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
        <?php else : ?>
            <section aria-labelledby="workspace-node-acl-title">
        <?php endif; ?>
            <h3 id="workspace-node-acl-title" class="h6">
                <?= $this->escape(__('Nasljedna ograničenja')) ?>
            </h3>
            <p class="small text-body-secondary">
                <?= $this->escape(
                    __(
                        'Zeleno prikazuje pravo naslijeđeno iz područja i nadređenih stranica. '
                        . 'Crveno prikazuje pravo '
                        . 'koje je zadržano izravnim ograničenjem ove stranice.',
                    ),
                ) ?>
            </p>
            <div class="workspace-node-acl-legend small mb-3" aria-label="<?= $this->escape(
                __('Legenda ovlasti'),
            ) ?>">
                <span>
                    <span class="workspace-acl-legend-swatch workspace-acl-legend-inherited"></span>
                    <?= $this->escape(__('Naslijeđeno iz područja i predaka')) ?>
                </span>
                <span>
                    <span class="workspace-acl-legend-swatch workspace-acl-legend-direct"></span>
                    <?= $this->escape(__('Izravno ograničenje stranice')) ?>
                </span>
            </div>
            <?php if (!$canManageRestrictions) : ?>
                <div class="alert alert-secondary py-2" role="note">
                    <?= $this->escape(
                        __('Ovlasti su prikazane samo za čitanje. Za promjenu je potrebno pravo upravljanja.'),
                    ) ?>
                </div>
            <?php elseif (!$hasDirectRestrictions) : ?>
                <div class="alert alert-success py-2" role="note">
                    <?= $this->escape(
                        __('Stranica trenutačno potpuno nasljeđuje ovlasti područja.'),
                    ) ?>
                </div>
            <?php endif; ?>
            <?php foreach (['user', 'group'] as $category) : ?>
                <?php
                $eligibleSubjects = array_values(array_filter(
                    $workspaceAclSubjects,
                    static fn(array $subject): bool =>
                        WorkspaceValue::string($subject['category'] ?? '') === $category,
                ));
                ?>
                <?php if ($eligibleSubjects !== []) : ?>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm align-middle workspace-acl-table">
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <?= $this->escape(
                                            $category === 'user' ? __('Korisnici') : __('Grupe'),
                                        ) ?>
                                    </th>
                                    <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                        <th scope="col" class="text-center">
                                            <?= $this->escape(__($permission)) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eligibleSubjects as $subject) : ?>
                                    <?php
                                    $subjectType = WorkspaceValue::string($subject['subject_type'] ?? '');
                                    $subjectId = WorkspaceValue::int($subject['subject_id'] ?? 0);
                                    $label = WorkspaceValue::string($subject['label'] ?? '');
                                    $publicReadOnly = (bool)($subject['is_read_only'] ?? false);
                                    ?>
                                    <tr>
                                        <th scope="row"><?= $this->escape($label) ?></th>
                                        <?php foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) : ?>
                                            <?php
                                            $inherited = (bool)($subject[$permission] ?? false);
                                            $direct = $hasPermission(
                                                $restrictions,
                                                $subjectType,
                                                $subjectId,
                                                $permission,
                                            );
                                            ?>
                                            <td class="text-center">
                                                <span class="workspace-node-acl-cell">
                                                    <input
                                                        class="form-check-input workspace-acl-checkbox-inherited"
                                                        type="checkbox"
                                                        <?= $inherited ? 'checked' : '' ?>
                                                        disabled
                                                        aria-label="<?= $this->escape(
                                                            __('Naslijeđeno iz područja i predaka')
                                                            . ' - '
                                                            . __($permission)
                                                            . ': '
                                                            . $label,
                                                        ) ?>"
                                                    >
                                                    <input
                                                        class="form-check-input workspace-acl-checkbox-direct"
                                                        type="checkbox"
                                                        name="acl[<?= $subjectType ?>][<?= $subjectId ?>][<?= $permission ?>]"
                                                        value="1"
                                                        <?= (!$canManageRestrictions
                                                            || !$inherited
                                                            || ($publicReadOnly && $permission !== 'can_view'))
                                                            ? 'disabled'
                                                            : '' ?>
                                                        aria-label="<?= $this->escape(
                                                            __('Izravno ograničenje stranice')
                                                            . ' - '
                                                            . __($permission)
                                                            . ': '
                                                            . $label,
                                                        ) ?>"
                                                        <?= $direct ? 'checked' : '' ?>
                                                    >
                                                </span>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($canManageRestrictions) : ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <p class="small text-body-secondary mb-0">
                        <?= $this->escape(
                            __('Uklonite sve crvene oznake za potpuno nasljeđivanje ovlasti područja.'),
                        ) ?>
                    </p>
                    <button class="btn btn-secondary" type="submit">
                        <?= $this->escape(__('Spremi ograničenja')) ?>
                    </button>
                </div>
            </form>
            <?php else : ?>
            </section>
            <?php endif; ?>
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
