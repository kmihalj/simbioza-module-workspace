<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

// phpcs:disable Generic.WhiteSpace.ScopeIndent,PSR12.ControlStructures.ControlStructureSpacing

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string, mixed> $workspace
 * @var list<array<string, mixed>> $nodes
 * @var int|null $activeNodeId
 * @var string $treeOrderSavePath
 * @var string $nodeDialogPath
 * @var string $nodeSavePath
 * @var list<array{id:string,title:string}> $editorDocuments
 * @var bool $editorAvailable
 * @var bool $canAttachExistingDocuments
 * @var bool $workspaceCanAdd
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$returnNodeId = WorkspaceValue::int($activeNodeId ?? 0);
?>
<form
    method="post"
    action="<?= $this->escape($treeOrderSavePath) ?>"
    data-workspace-tree-order-form
>
    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
    <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
    <input type="hidden" name="return_context" value="workspace">
    <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
    <div
        class="list-group list-group-flush workspace-tree workspace-tree-order-list"
        data-workspace-tree-order-list
        data-can-use-root="1"
    >
        <?php if ($nodes === []) : ?>
            <p class="small text-body-secondary px-3 mb-0">
                <?= $this->escape(__('Stablo je prazno.')) ?>
            </p>
        <?php endif; ?>
        <?php foreach ($nodes as $node) : ?>
            <?php
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $parentId = WorkspaceValue::int($node['parent_id'] ?? 0);
            $nodePermissions = WorkspaceValue::stringKeyArray($node['permissions'] ?? null);
            $canBeParent = WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                && (bool)($nodePermissions['can_add'] ?? false);
            $dialogUrl = $nodeDialogPath . '?' . http_build_query([
                'workspace_id' => $workspaceId,
                'node_id' => $nodeId,
                'return_node_id' => $returnNodeId,
            ]);
            ?>
            <div
                class="list-group-item workspace-tree-order-row workspace-tree-editor-row"
                data-workspace-tree-order-row
                data-node-id="<?= $nodeId ?>"
                data-can-parent="<?= $canBeParent ? '1' : '0' ?>"
            >
                <input
                    class="workspace-tree-node-id"
                    type="hidden"
                    name="items[<?= $nodeId ?>][id]"
                    value="<?= $nodeId ?>"
                >
                <input
                    class="workspace-tree-parent-id"
                    type="hidden"
                    name="items[<?= $nodeId ?>][parent_id]"
                    value="<?= $parentId > 0 ? $parentId : '' ?>"
                >
                <input
                    class="workspace-tree-sort-order"
                    type="hidden"
                    name="items[<?= $nodeId ?>][sort_order]"
                    value="<?= WorkspaceValue::int($node['sort_order'] ?? 100) ?>"
                >
                <button
                    class="btn btn-outline-secondary btn-sm workspace-tree-node-edit"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#workspace-node-editor-modal"
                    data-workspace-node-dialog-url="<?= $this->escape($dialogUrl) ?>"
                    title="<?= $this->escape(__('Uredi stavku')) ?>"
                    aria-label="<?= $this->escape(
                        __('Uredi stavku') . ': ' . WorkspaceValue::string($node['title'] ?? ''),
                    ) ?>"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 20h9"/>
                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4z"/>
                    </svg>
                </button>
                <div
                    class="workspace-tree-order-label"
                    style="--workspace-tree-level: <?= WorkspaceValue::int(
                        $node['tree_depth'] ?? 0,
                    ) ?>;"
                >
                    <span><?= $this->escape(WorkspaceValue::string($node['title'] ?? '')) ?></span>
                </div>
                <div
                    class="workspace-tree-order-controls"
                    role="group"
                    aria-label="<?= $this->escape(
                        __('Položaj: ') . WorkspaceValue::string($node['title'] ?? ''),
                    ) ?>"
                >
                    <?php foreach (
                        [
                            'up' => ['label' => __('Pomakni gore'), 'path' => 'M12 19V5M5 12l7-7 7 7'],
                            'down' => ['label' => __('Pomakni dolje'), 'path' => 'M12 5v14M19 12l-7 7-7-7'],
                            'outdent' => ['label' => __('Izvuci jednu razinu'), 'path' => 'M19 12H5M12 19l-7-7 7-7'],
                            'indent' => ['label' => __('Uvuci jednu razinu'), 'path' => 'M5 12h14M12 5l7 7-7 7'],
                        ] as $action => $actionData
                    ) : ?>
                        <button
                            class="btn btn-outline-secondary btn-sm workspace-tree-order-action"
                            type="button"
                            data-workspace-tree-action="<?= $action ?>"
                            title="<?= $this->escape($actionData['label']) ?>"
                            aria-label="<?= $this->escape($actionData['label']) ?>"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="<?= $actionData['path'] ?>"/>
                            </svg>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="workspace-tree-editor-footer">
        <button
            class="btn btn-outline-secondary btn-sm"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#workspace-add-tree-item-modal"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            <span><?= $this->escape(__('Dodaj stavku')) ?></span>
        </button>
        <?php if ($nodes !== []) : ?>
            <button class="btn btn-primary btn-sm" type="submit">
                <?= $this->escape(__('Spremi raspored')) ?>
            </button>
        <?php endif; ?>
    </div>
</form>

<div
    class="modal fade"
    id="workspace-add-tree-item-modal"
    tabindex="-1"
    aria-labelledby="workspace-add-tree-item-title"
    aria-hidden="true"
    data-workspace-lazy-modal
>
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 id="workspace-add-tree-item-title" class="modal-title fs-5 mb-0">
                        <?= $this->escape(__('Dodaj poveznicu ili postojeći dokument')) ?>
                    </h2>
                    <p class="small text-body-secondary mb-0">
                        <?= $this->escape(__('Nova stavka bit će dodana u stablo ovog područja.')) ?>
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="<?= $this->escape(__('Zatvori')) ?>"
                ></button>
            </div>
            <form method="post" action="<?= $this->escape($nodeSavePath) ?>">
                <div class="modal-body">
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                    <input type="hidden" name="return_context" value="workspace">
                    <input type="hidden" name="return_node_id" value="<?= $returnNodeId ?>">
                    <?= $this->forModulePartial(
                        'aaieduhr/simbioza-module-workspace',
                        'workspace/node-fields',
                        [
                            'node' => ['node_type' => 'internal_link'],
                            'nodes' => $nodes,
                            'editorDocuments' => $editorDocuments,
                            'editorAvailable' => $editorAvailable,
                            'canAttachExistingDocuments' => $canAttachExistingDocuments,
                            'workspaceCanAdd' => $workspaceCanAdd,
                            'treeOrganizerAvailable' => true,
                            'activeLanguage' => $activeLanguage ?? 'hr',
                            'primaryLanguage' => $primaryLanguage ?? 'hr',
                            'supportedLanguages' => $supportedLanguages ?? ['hr'],
                            'localeFlagPaths' => $localeFlagPaths ?? [],
                        ],
                    ) ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $this->escape(__('Odustani')) ?>
                    </button>
                    <button class="btn btn-primary" type="submit">
                        <?= $this->escape(__('Dodaj stavku')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
