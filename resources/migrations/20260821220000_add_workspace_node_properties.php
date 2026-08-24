<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /** HR: Dodaje strukturirana svojstva stranica. EN: Adds structured page properties. */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)) {
            return;
        }

        $schema->create(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES, static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('node_id')->unsigned()->index();
            $table->string('property_key', 128)->index();
            $table->string('property_label', 255);
            $table->string('property_type', 32)->default('text')->index();
            $table->text('property_value')->nullable();
            $table->integer('sort_order')->unsigned()->default(100)->index();
            $table->timestamps();
            $table->unique(['node_id', 'property_key'], 'workspace_node_property_unique');
        });
    }

    /** HR: Uklanja tablicu strukturiranih svojstava. EN: Removes the structured properties table. */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES);
    }
};
