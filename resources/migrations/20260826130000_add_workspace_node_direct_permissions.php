<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Dodaje izravna korisnička prava pojedinoj stranici odvojeno od nasljednih ograničenja.
     * EN: Adds direct per-page user grants separately from inherited restrictions.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)) {
            return;
        }

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

    /**
     * HR: Uklanja samo tablicu koju je dodala ova nadogradnja.
     * EN: Drops only the table introduced by this upgrade.
     */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS);
    }
};
