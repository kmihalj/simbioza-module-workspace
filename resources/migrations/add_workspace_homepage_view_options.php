<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Nadograđuje postojeće odabire naslovnice strukturiranim Shorts ciljem
     * i odvojenim postavkama vidljivosti stabla i opcija prikaza.
     *
     * EN: Upgrades existing homepage selections with a structured Shorts target
     * and separate tree and display-options visibility settings.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)) {
            $schema->table(
                ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                static function (Blueprint $table) use ($schema): void {
                    $name = ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS;
                    if (!$schema->hasColumn($name, 'public_target_type')) {
                        $table->string('public_target_type', 16)->default('page');
                    }
                    if (!$schema->hasColumn($name, 'public_workspace_id')) {
                        $table->bigInteger('public_workspace_id')->unsigned()->nullable();
                    }
                    if (!$schema->hasColumn($name, 'public_show_tree')) {
                        $table->boolean('public_show_tree')->default(true);
                    }
                    if (!$schema->hasColumn($name, 'public_show_display_options')) {
                        $table->boolean('public_show_display_options')->default(true);
                    }
                    if (!$schema->hasColumn($name, 'authenticated_target_type')) {
                        $table->string('authenticated_target_type', 16)->default('page');
                    }
                    if (!$schema->hasColumn($name, 'authenticated_workspace_id')) {
                        $table->bigInteger('authenticated_workspace_id')->unsigned()->nullable();
                    }
                    if (!$schema->hasColumn($name, 'authenticated_show_tree')) {
                        $table->boolean('authenticated_show_tree')->default(true);
                    }
                    if (!$schema->hasColumn($name, 'authenticated_show_display_options')) {
                        $table->boolean('authenticated_show_display_options')->default(true);
                    }
                },
            );
        }

        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)) {
            $schema->table(
                ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                static function (Blueprint $table) use ($schema): void {
                    $name = ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES;
                    if (!$schema->hasColumn($name, 'target_type')) {
                        $table->string('target_type', 16)->default('page');
                    }
                    if (!$schema->hasColumn($name, 'workspace_id')) {
                        $table->bigInteger('workspace_id')->unsigned()->nullable();
                    }
                    if (!$schema->hasColumn($name, 'show_tree')) {
                        $table->boolean('show_tree')->default(true);
                    }
                    if (!$schema->hasColumn($name, 'show_display_options')) {
                        $table->boolean('show_display_options')->default(true);
                    }
                },
            );
        }
    }

    /**
     * HR: Uklanja samo strukturirane opcije i čuva postojeće odabire stranica.
     * EN: Removes only structured options and preserves existing page selections.
     */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)) {
            foreach (['show_display_options', 'show_tree', 'workspace_id', 'target_type'] as $column) {
                if ($schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES, $column)) {
                    $schema->table(
                        ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
                        static fn(Blueprint $table): mixed => $table->dropColumn($column),
                    );
                }
            }
        }

        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)) {
            foreach (
                [
                    'authenticated_show_display_options',
                    'authenticated_show_tree',
                    'authenticated_workspace_id',
                    'authenticated_target_type',
                    'public_show_display_options',
                    'public_show_tree',
                    'public_workspace_id',
                    'public_target_type',
                ] as $column
            ) {
                if ($schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS, $column)) {
                    $schema->table(
                        ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
                        static fn(Blueprint $table): mixed => $table->dropColumn($column),
                    );
                }
            }
        }
    }
};
