<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /** HR: Bilježi početak nastavivog brisanja kako se nepotpuno područje ne može vratiti. EN: Marks resumable purges so an incomplete Workspace cannot be restored. */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)
            && !$schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, 'purge_started_at')) {
            $schema->table(ModuleWorkspace::TABLE_WORKSPACES,
                static function (Blueprint $table): void {
                    $table->timestamp('purge_started_at')->nullable();
                    $table->bigInteger('purge_total_items')->unsigned()->default(0);
                    $table->bigInteger('purge_completed_items')->unsigned()->default(0);
                });
        }
    }

    /** HR: Vraća shemu na ranije stanje. EN: Restores the previous schema. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)
            && $schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, 'purge_started_at')) {
            $schema->table(ModuleWorkspace::TABLE_WORKSPACES,
                static function (Blueprint $table): void {
                    $table->dropColumn('purge_completed_items');
                    $table->dropColumn('purge_total_items');
                    $table->dropColumn('purge_started_at');
                });
        }
    }
};
