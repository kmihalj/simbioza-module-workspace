<?php

declare(strict_types=1);

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * @var \HeartPhrame\View\View $this
 * @var list<array<string, mixed>> $nodes
 * @var int|null $activeNodeId
 * @var int $level
 * @var string $treeBranchPath
 * @var int $workspaceId
 * @var string $language
 */
$level = max(1, $level ?? 1);
$treeBranchPath = WorkspaceValue::string($treeBranchPath ?? '');
$workspaceId = WorkspaceValue::int($workspaceId ?? 0);
$language = WorkspaceValue::string($language ?? 'hr');

/**
 * HR: Provjerava sadrži li podstablo aktivnu stranicu. Time se pri izravnom
 *     otvaranju URL-a šire samo preci aktivne stranice, dok ostale grane druge
 *     i dubljih razina ostaju sažete.
 * EN: Checks whether a subtree contains the active page. This expands only the
 *     active page ancestors on a direct URL visit while keeping unrelated
 *     second-level and deeper branches collapsed.
 *
 * @param list<array<string, mixed>> $candidateNodes
 */
$containsActiveNode = static function (
    array $candidateNodes,
    ?int $candidateActiveNodeId,
) use (&$containsActiveNode): bool {
    if ($candidateActiveNodeId === null || $candidateActiveNodeId <= 0) {
        return false;
    }

    foreach ($candidateNodes as $candidateNodeValue) {
        $candidateNode = WorkspaceValue::stringKeyArray($candidateNodeValue);
        if (WorkspaceValue::int($candidateNode['id'] ?? 0) === $candidateActiveNodeId) {
            return true;
        }

        $candidateChildren = WorkspaceValue::rows($candidateNode['children'] ?? null);
        if ($candidateChildren !== [] && $containsActiveNode($candidateChildren, $candidateActiveNodeId)) {
            return true;
        }
    }

    return false;
};
?>
<?php if ($nodes !== []) : ?>
    <?php foreach ($nodes as $treeNode) : ?>
        <?php
        $treeNodeId = WorkspaceValue::int($treeNode['id'] ?? 0);
        $children = WorkspaceValue::rows($treeNode['children'] ?? null);
        $hasChildren = (bool)($treeNode['has_children'] ?? ($children !== []));
        $childrenLoaded = (bool)($treeNode['children_loaded'] ?? true);
        $isActive = $treeNodeId > 0 && $treeNodeId === ($activeNodeId ?? null);
        $workflowLabel = WorkspaceValue::string($treeNode['workflow_label'] ?? '');
        $workflowStatus = WorkspaceValue::string($treeNode['workflow_status'] ?? '');
        $title = WorkspaceValue::string($treeNode['title'] ?? '');
        $branchId = 'workspace-tree-branch-' . $treeNodeId;
        $containsActiveChild = $containsActiveNode($children, $activeNodeId ?? null);
        $branchExpanded = $hasChildren && ($level === 1 || $containsActiveChild);
        $branchActionLabel = $branchExpanded
        ? sprintf(__('Sažmi podstranice: %s'), $title)
        : sprintf(__('Proširi podstranice: %s'), $title);
        $branchUrl = $treeBranchPath . '?' . http_build_query([
            'workspace_id' => $workspaceId,
            'parent_id' => $treeNodeId,
            'active_node_id' => $activeNodeId ?? 0,
            'level' => $level + 1,
            'lang' => $language,
        ]);
        ?>
        <div
            class="workspace-tree-node"
            data-workspace-tree-node="<?= $treeNodeId ?>"
            data-workspace-tree-level="<?= $this->escape((string)$level) ?>"
        >
            <div class="workspace-tree-row">
        <?php if ($hasChildren) : ?>
                <button
                    class="workspace-tree-branch-toggle"
                    type="button"
                    data-workspace-tree-branch-toggle
                    aria-controls="<?= $this->escape($branchId) ?>"
                    aria-expanded="<?= $branchExpanded ? 'true' : 'false' ?>"
                    data-expanded-label="<?= $this->escape(sprintf(__('Sažmi podstranice: %s'), $title)) ?>"
                    data-collapsed-label="<?= $this->escape(sprintf(__('Proširi podstranice: %s'), $title)) ?>"
                    aria-label="<?= $this->escape($branchActionLabel) ?>"
                    title="<?= $this->escape($branchActionLabel) ?>"
                    data-workspace-tree-branch-url="<?= $this->escape($branchUrl) ?>"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m8 10 4 4 4-4"/>
                    </svg>
                </button>
        <?php else : ?>
                <span class="workspace-tree-branch-spacer" aria-hidden="true"></span>
        <?php endif; ?>
                <a
                    class="list-group-item list-group-item-action workspace-tree-link
                        workspace-tree-link-level-<?= $this->escape((string)$level) ?><?= $isActive ? ' active' : '' ?>"
                    href="<?= $this->escape(WorkspaceValue::string($treeNode['href'] ?? '#')) ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
        <?= WorkspaceValue::string($treeNode['node_type'] ?? '') === 'external_link'
        ? 'target="_blank" rel="noopener noreferrer"'
        : '' ?>
                >
                    <span class="workspace-tree-link-title"><?= $this->escape($title) ?></span>
        <?php if ($workflowLabel !== '') : ?>
                    <span
                        class="badge rounded-pill workspace-tree-status
            <?= $workflowStatus === 'in_review' ? 'text-bg-warning' : 'text-bg-info' ?>"
                        title="<?= $this->escape($workflowLabel) ?>"
                    >
            <?= $this->escape($workflowLabel) ?>
                    </span>
        <?php endif; ?>
                </a>
            </div>
        <?php if ($hasChildren) : ?>
            <div
                id="<?= $this->escape($branchId) ?>"
                class="workspace-tree-branch"
                data-workspace-tree-branch
                data-workspace-tree-loaded="<?= $childrenLoaded ? '1' : '0' ?>"
            <?= $branchExpanded ? '' : 'hidden' ?>
            >
            <?php if ($childrenLoaded) : ?>
                <?= $this->forModulePartial(
                    'aaieduhr/simbioza-module-workspace',
                    'workspace/tree',
                    [
                        'nodes' => $children,
                        'activeNodeId' => $activeNodeId,
                        'level' => $level + 1,
                        'treeBranchPath' => $treeBranchPath,
                        'workspaceId' => $workspaceId,
                        'language' => $language,
                    ],
                ) ?>
            <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
