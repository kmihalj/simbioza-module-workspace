<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /** HR: Dodaje prenosive izvorne oznake stranica za importe i integracije. EN: Adds portable source page labels for imports and integrations. */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)) {
            return;
        }

        $schema->create(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS, static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('node_id')->unsigned()->index();
            $table->string('label', 128)->index();
            $table->timestamps();
            $table->unique(['node_id', 'label'], 'workspace_node_label_unique');
        });
    }

    /** HR: Uklanja samo tablicu oznaka koju je migracija dodala. EN: Drops only the label table added by this migration. */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS);
    }
};
