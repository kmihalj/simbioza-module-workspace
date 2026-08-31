<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Dodaje izvedeni, ponovno izgradivi indeks povratnih poveznica.
     * EN: Adds the derived, rebuildable backlink index.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
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

    /** HR: Uklanja samo izvedeni indeks. EN: Removes only the derived index. */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_BACKLINK_INDEX_STATE);
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS);
    }
};
