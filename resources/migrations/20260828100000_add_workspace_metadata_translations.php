<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Dodaje višejezične nazive i opise područja te naslove stranica.
     *     Postojeće razvojne podatke premješta u hrvatsku inačicu.
     * EN: Adds multilingual Workspace names and descriptions and page titles.
     *     Existing development data is moved into the Croatian variant.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)) {
            $schema->table(
                ModuleWorkspace::TABLE_WORKSPACES,
                static function (Blueprint $table) use ($schema): void {
                    if (!$schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, 'name_translations')) {
                        $table->text('name_translations')->nullable();
                    }
                    if (!$schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, 'description_translations')) {
                        $table->text('description_translations')->nullable();
                    }
                },
            );

            foreach ($db->table(ModuleWorkspace::TABLE_WORKSPACES)->get() as $row) {
                $values = (array)$row;
                $nameTranslations = $this->translations($values['name_translations'] ?? null);
                $descriptionTranslations = $this->translations($values['description_translations'] ?? null);
                $nameTranslations['hr'] ??= trim((string)($values['name'] ?? ''));
                $descriptionTranslations['hr'] ??= trim((string)($values['description'] ?? ''));
                $db->table(ModuleWorkspace::TABLE_WORKSPACES)
                    ->where('id', '=', (int)($values['id'] ?? 0))
                    ->update([
                        'name_translations' => json_encode(
                            $nameTranslations,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                        'description_translations' => json_encode(
                            $descriptionTranslations,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                    ]);
            }
        }

        if ($schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)) {
            if (!$schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_NODES, 'title_translations')) {
                $schema->table(
                    ModuleWorkspace::TABLE_WORKSPACE_NODES,
                    static fn(Blueprint $table): mixed => $table->text('title_translations')->nullable(),
                );
            }

            foreach ($db->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->get() as $row) {
                $values = (array)$row;
                $titleTranslations = $this->translations($values['title_translations'] ?? null);
                $titleTranslations['hr'] ??= trim((string)($values['title'] ?? ''));
                $db->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('id', '=', (int)($values['id'] ?? 0))
                    ->update([
                        'title_translations' => json_encode(
                            $titleTranslations,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                    ]);
            }
        }
    }

    /**
     * HR: Čuva postojeće prijevode ako se migracija ponavlja nad već proširenom razvojnom bazom.
     * EN: Preserves existing translations when rerun against an already extended development database.
     *
     * @return array<string, string>
     */
    private function translations(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        $translations = [];
        foreach ($decoded as $locale => $translation) {
            if (is_string($locale) && is_scalar($translation) && trim((string)$translation) !== '') {
                $translations[strtolower(trim($locale))] = trim((string)$translation);
            }
        }

        return $translations;
    }

    /** HR: Uklanja višejezične stupce. EN: Removes multilingual columns. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        foreach (['name_translations', 'description_translations'] as $column) {
            if ($schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACES, $column)) {
                $schema->table(
                    ModuleWorkspace::TABLE_WORKSPACES,
                    static fn(Blueprint $table): mixed => $table->dropColumn($column),
                );
            }
        }
        if ($schema->hasColumn(ModuleWorkspace::TABLE_WORKSPACE_NODES, 'title_translations')) {
            $schema->table(
                ModuleWorkspace::TABLE_WORKSPACE_NODES,
                static fn(Blueprint $table): mixed => $table->dropColumn('title_translations'),
            );
        }
    }
};
