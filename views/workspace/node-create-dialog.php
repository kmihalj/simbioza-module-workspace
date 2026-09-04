<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var array<string,mixed> $workspace
 * @var array<string,mixed> $node
 * @var list<array<string,mixed>> $nodes
 * @var list<array{id:string,title:string}> $editorDocuments
 * @var bool $editorAvailable
 * @var bool $canAttachExistingDocuments
 * @var bool $workspaceCanAdd
 * @var string $nodeSavePath
 * @var int $returnNodeId
 * @var string $activeLanguage
 * @var string $primaryLanguage
 * @var list<string> $supportedLanguages
 * @var array<string,string> $localeFlagPaths
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
?>
<div class="modal-header">
    <div>
        <h2 class="modal-title fs-5 mb-0"><?= $this->escape(__('Dodaj stavku')) ?></h2>
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
        <input type="hidden" name="return_node_id" value="<?= WorkspaceValue::int($returnNodeId ?? 0) ?>">
        <?= $this->forModulePartial(
            'aaieduhr/simbioza-module-workspace',
            'workspace/node-fields',
            [
                'node' => $node,
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
        <button class="btn btn-primary" type="submit"><?= $this->escape(__('Dodaj stavku')) ?></button>
    </div>
</form>
