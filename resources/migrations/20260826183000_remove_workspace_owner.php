<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\MigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements MigrationInterface {
    /**
     * HR: Uklanja zastarjeli koncept vlasnika; upravljanje se određuje isključivo ACL pravom.
     * EN: Removes the obsolete owner concept; management is determined solely by ACL permissions.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if (
            !$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)
            || !$schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, 'owner_user_id')
        ) {
            return;
        }

        $schema->table(
            ModuleWorkspace::TABLE_WORKSPACES,
            static fn(Blueprint $table): mixed => $table->dropColumn('owner_user_id'),
        );
    }
};
