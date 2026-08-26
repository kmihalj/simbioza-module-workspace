<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Kreira početnu prenosivu shemu za područja, članstva, stablo sadržaja
     * i ograničenja koja se nasljeđuju kroz roditeljske čvorove.
     *
     * EN: Creates the initial portable schema for workspaces, memberships, the
     * content tree, and restrictions inherited through parent nodes.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)) {
            $schema->create(ModuleWorkspace::TABLE_WORKSPACES, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->string('slug', 128)->unique();
                $table->string('name', 190)->index();
                $table->text('description')->nullable();
                $table->string('visibility', 32)->default('restricted')->index();
                $table->string('tree_visibility', 16)->default('inherit');
                $table->string('contents_visibility', 16)->default('inherit');
                $table->boolean('is_archived')->default(false)->index();
                $table->boolean('is_deleted')->default(false)->index();
                $table->bigInteger('created_by_user_id')->unsigned()->nullable()->index();
                $table->bigInteger('updated_by_user_id')->unsigned()->nullable()->index();
                $table->bigInteger('deleted_by_user_id')->unsigned()->nullable()->index();
                $table->timestamp('deleted_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_ACL)) {
            $schema->create(ModuleWorkspace::TABLE_WORKSPACE_ACL, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('workspace_id')->unsigned()->index();
                $table->string('subject_type', 16)->index();
                $table->bigInteger('subject_id')->unsigned()->index();
                $table->boolean('can_view')->default(true)->index();
                $table->boolean('can_add')->default(false)->index();
                $table->boolean('can_edit')->default(false)->index();
                $table->boolean('can_publish')->default(false)->index();
                $table->boolean('can_delete')->default(false)->index();
                $table->boolean('can_manage')->default(false)->index();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'subject_type', 'subject_id'],
                    'workspace_acl_subject_unique',
                );
            });
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)) {
            $schema->create(ModuleWorkspace::TABLE_WORKSPACE_NODES, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('workspace_id')->unsigned()->index();
                $table->bigInteger('parent_id')->unsigned()->nullable()->index();
                $table->string('node_type', 32)->default('document')->index();
                $table->string('slug', 128)->index();
                $table->string('title', 255);
                $table->string('document_key', 190)->nullable()->index();
                $table->string('route_name', 190)->nullable()->index();
                $table->string('target_url', 1024)->nullable();
                $table->integer('sort_order')->default(100)->index();
                $table->boolean('is_homepage')->default(false)->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->string('contents_visibility', 16)->default('inherit');
                $table->bigInteger('created_by_user_id')->unsigned()->nullable()->index();
                $table->bigInteger('updated_by_user_id')->unsigned()->nullable()->index();
                $table->timestamps();

                $table->unique(['workspace_id', 'slug'], 'workspace_node_slug_unique');
                $table->index(
                    ['workspace_id', 'parent_id', 'sort_order'],
                    'workspace_node_tree_order_idx',
                );
            });
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)) {
            $schema->create(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('node_id')->unsigned()->index();
                $table->string('subject_type', 16)->index();
                $table->bigInteger('subject_id')->unsigned()->index();
                $table->boolean('can_view')->default(true)->index();
                $table->boolean('can_add')->default(false)->index();
                $table->boolean('can_edit')->default(false)->index();
                $table->boolean('can_publish')->default(false)->index();
                $table->boolean('can_delete')->default(false)->index();
                $table->boolean('can_manage')->default(false)->index();
                $table->timestamps();

                $table->unique(
                    ['node_id', 'subject_type', 'subject_id'],
                    'workspace_node_acl_subject_unique',
                );
            });
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('node_id')->unsigned()->index();
                    $table->bigInteger('user_id')->unsigned()->index();
                    $table->boolean('can_view')->default(true);
                    $table->boolean('can_edit')->default(false);
                    $table->boolean('can_publish')->default(false);
                    $table->timestamps();

                    $table->unique(
                        ['node_id', 'user_id'],
                        'workspace_node_direct_user_unique',
                    );
                    $table->index(
                        ['user_id', 'node_id'],
                        'workspace_node_direct_user_node_idx',
                    );
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('node_id')->unsigned()->index();
                    $table->string('language_code', 16)->index();
                    $table->string('status', 32)->default('draft')->index();
                    $table->integer('current_version_number')->unsigned()->nullable()->index();
                    $table->integer('published_version_number')->unsigned()->nullable()->index();
                    $table->bigInteger('submitted_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamp('submitted_at')->nullable()->index();
                    $table->bigInteger('published_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamp('published_at')->nullable()->index();
                    $table->bigInteger('archived_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamp('archived_at')->nullable()->index();
                    $table->bigInteger('updated_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamps();

                    $table->unique(
                        ['node_id', 'language_code'],
                        'workspace_node_workflow_language_unique',
                    );
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('node_id')->unsigned()->index();
                    $table->string('label', 128)->index();
                    $table->timestamps();
                    $table->unique(['node_id', 'label'], 'workspace_node_label_unique');
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('node_id')->unsigned()->index();
                    $table->string('property_key', 128)->index();
                    $table->string('property_label', 255);
                    $table->string('property_type', 32)->default('text')->index();
                    $table->text('property_value')->nullable();
                    $table->integer('sort_order')->unsigned()->default(100)->index();
                    $table->timestamps();
                    $table->unique(
                        ['node_id', 'property_key'],
                        'workspace_node_property_unique',
                    );
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('public_node_id')->unsigned()->nullable()->index();
                    $table->string('public_target_type', 16)->default('page')->index();
                    $table->bigInteger('public_workspace_id')->unsigned()->nullable()->index();
                    $table->boolean('public_show_tree')->default(true);
                    $table->boolean('public_show_display_options')->default(true);
                    $table->bigInteger('authenticated_node_id')->unsigned()->nullable()->index();
                    $table->string('authenticated_target_type', 16)->default('page')->index();
                    $table->bigInteger('authenticated_workspace_id')->unsigned()->nullable()->index();
                    $table->boolean('authenticated_show_tree')->default(true);
                    $table->boolean('authenticated_show_display_options')->default(true);
                    $table->boolean('allow_user_selection')->default(true)->index();
                    $table->bigInteger('updated_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamps();
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('user_id')->unsigned()->unique();
                    $table->bigInteger('node_id')->unsigned()->index();
                    $table->string('target_type', 16)->default('page')->index();
                    $table->bigInteger('workspace_id')->unsigned()->nullable()->index();
                    $table->boolean('show_tree')->default(true);
                    $table->boolean('show_display_options')->default(true);
                    $table->timestamps();
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_THEMES)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_THEMES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('workspace_id')->unsigned()->unique();
                    $table->string('selection_type', 16)->default('default')->index();
                    $table->string('source_theme_id', 190)->nullable()->index();
                    $table->string('mode_policy', 16)->default('auto');
                    $table->longText('theme_json')->nullable();
                    $table->bigInteger('updated_by_user_id')->unsigned()->nullable()->index();
                    $table->timestamps();
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('source_workspace_id')->unsigned()->index();
                    $table->bigInteger('source_node_id')->unsigned()->index();
                    $table->string('source_language_code', 16)->index();
                    $table->integer('source_version_number')->unsigned();
                    $table->string('source_title', 255);
                    $table->bigInteger('target_workspace_id')->unsigned()->index();
                    $table->bigInteger('target_node_id')->unsigned()->index();
                    $table->string('link_text', 255)->nullable();
                    $table->timestamp('indexed_at')->index();
                    $table->timestamps();

                    $table->unique(
                        ['source_node_id', 'source_language_code', 'target_node_id'],
                        'workspace_backlink_source_target_unique',
                    );
                    $table->index(
                        ['target_node_id', 'source_language_code'],
                        'workspace_backlink_target_language_idx',
                    );
                },
            );
        }

        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE)) {
            $schema->create(
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->timestamp('rebuilt_at')->nullable()->index();
                    $table->timestamps();
                },
            );
        }
    }

    /**
     * HR: Briše Workspace tablice obrnutim redoslijedom bez diranja auth ili
     * editor podataka koji pripadaju drugim modulima.
     *
     * EN: Drops Workspace tables in reverse order without touching auth or
     * editor data owned by other modules.
     */
    public function down(Database $db): void
    {
        $schema = $db->schema();

        foreach (
            [
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE,
                ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS,
                ModuleWorkspace::TABLE_WORKSPACE_THEMES,
                ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS,
                ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL,
                ModuleWorkspace::TABLE_WORKSPACE_NODES,
                ModuleWorkspace::TABLE_WORKSPACE_ACL,
                ModuleWorkspace::TABLE_WORKSPACES,
            ] as $table
        ) {
            $schema->dropIfExists($table);
        }
    }
};
