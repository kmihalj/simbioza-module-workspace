<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /** HR: Dodaje navigacijsku oznaku za skrivene stavke i grane. EN: Adds the navigation flag for hidden items and branches. */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if (
            !$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            || $schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_NODES, 'is_tree_hidden')
        ) {
            return;
        }

        $schema->table(
            ModuleWorkspace::TABLE_WORKSPACE_NODES,
            static fn(Blueprint $table): mixed => $table->boolean('is_tree_hidden')->default(false),
        );
    }

    /** HR: Uklanja navigacijsku oznaku skrivenih stavki. EN: Removes the hidden-navigation flag. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_NODES, 'is_tree_hidden')) {
            $schema->table(
                ModuleWorkspace::TABLE_WORKSPACE_NODES,
                static fn(Blueprint $table): mixed => $table->dropColumn('is_tree_hidden'),
            );
        }
    }
};
