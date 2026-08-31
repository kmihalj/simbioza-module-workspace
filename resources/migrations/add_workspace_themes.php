<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Dodaje izoliranu konfiguraciju teme za svako područje postojećoj instalaciji.
     * EN: Adds isolated per-workspace theme configuration to an existing installation.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_THEMES)) {
            return;
        }

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

    /**
     * HR: Uklanja samo tablicu privatnih tema područja.
     * EN: Drops only the private workspace-theme table.
     */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_THEMES);
    }
};
