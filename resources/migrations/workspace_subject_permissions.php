<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /** EN: Adds page-only group and audience exceptions without changing existing user grants.
     * HR: Dodaje iznimke grupa i publika samo za stranicu, bez izmjene postojećih korisničkih prava.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if (!$schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS)) {
            $schema->create(ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS, static function (Blueprint $t): void {
                $t->id();
                $t->bigInteger('node_id')->unsigned()->index();
                $t->string('subject_type', 16);
                $t->bigInteger('subject_id')->unsigned();
                $t->boolean('can_view')->default(true);
                $t->boolean('can_edit')->default(false);
                $t->boolean('can_publish')->default(false);
                $t->timestamps();
                $t->unique(['node_id', 'subject_type', 'subject_id'], 'workspace_node_subject_unique');
                $t->index(['subject_type', 'subject_id', 'node_id'], 'workspace_node_subject_lookup');
            });
        }
    }

    /** EN: Refuses to discard configured exceptions on rollback.
     * HR: Odbija odbacivanje podešenih iznimaka pri povratku.
     */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if (
            $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS)
            && $db->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS)->first() !== null
        ) {
            throw new RuntimeException('Remove page subject grants before rollback.');
        }
        $schema->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS);
    }
};
