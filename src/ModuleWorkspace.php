<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace;

/**
 * @see \AaiEduHr\HeartPhrameModuleWorkspace\Tests\ModuleWorkspaceTest
 */
final class ModuleWorkspace
{
    public const PACKAGE_NAME = 'aaieduhr/heartphrame-module-workspace';

    public const TABLE_WORKSPACES = 'workspaces';

    public const TABLE_WORKSPACE_ACL = 'workspace_acl';

    public const TABLE_WORKSPACE_NODES = 'workspace_nodes';

    public const TABLE_WORKSPACE_NODE_ACL = 'workspace_node_acl';

    public const TABLE_WORKSPACE_NODE_WORKFLOWS = 'workspace_node_workflows';

    public const TABLE_WORKSPACE_NODE_LABELS = 'workspace_node_labels';

    public const TABLE_WORKSPACE_NODE_PROPERTIES = 'workspace_node_properties';

    public const TABLE_WORKSPACE_HOMEPAGE_SETTINGS = 'workspace_homepage_settings';

    public const TABLE_WORKSPACE_USER_HOMEPAGES = 'workspace_user_homepages';

    public const TABLE_WORKSPACE_THEMES = 'workspace_themes';

    public const TABLE_WORKSPACE_BACKLINKS = 'workspace_backlinks';

    public const TABLE_WORKSPACE_BACKLINK_INDEX_STATE = 'workspace_backlink_index_state';

    /**
     * HR: Sprječava instanciranje klase koja služi samo kao registar stabilnog naziva paketa.
     * EN: Prevents instantiation of a class used only as a registry for the stable package name.
     */
    private function __construct()
    {
    }
}
