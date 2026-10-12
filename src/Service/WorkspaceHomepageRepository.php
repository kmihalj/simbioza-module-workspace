<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Config\ConfigArrayMerger;
use RuntimeException;

use function date;
use function in_array;
use function is_array;
use function is_scalar;
use function strtolower;
use function trim;

/**
 * EN: Reads global policy from configuration; personal selections remain in the database.
 *
 * HR: Globalnu politiku čita iz konfiguracije; osobni odabiri ostaju u bazi.
 */
final readonly class WorkspaceHomepageRepository
{
    /**
     * EN: Receives the ORM database for portable SQLite, PostgreSQL, and MySQL operation.
     *
     * HR: Prima ORM bazu za prenosiv rad na SQLiteu, PostgreSQL-u i MySQL-u.
     */
    public function __construct(private Database $database, private WorkspaceConfig $config)
    {
    }

    /**
     * EN: Checks the required current personal-homepage schema.
     *
     * HR: Provjerava obaveznu aktualnu shemu osobnih naslovnica.
     */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
        && $schema->hasColumns(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES, [
            'target_type', 'workspace_id', 'show_tree', 'show_display_options',
        ]);
    }

    /**
     * EN: Checks whether the installed schema supports structured Shorts homepages.
     *
     * HR: Provjerava podržava li instalirana shema strukturirane Shorts naslovnice.
     */
    public function viewOptionsReady(): bool
    {
        if (!$this->tablesReady()) {
            return false;
        }

        $schema = $this->database->schema();

        return $schema->hasColumns(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES, [
            'target_type',
            'workspace_id',
            'show_tree',
            'show_display_options',
        ]);
    }

    /**
     * EN: Returns global policy from JSON configuration layers.
     *
     * HR: Vraća globalnu politiku iz JSON konfiguracijskih slojeva.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $this->config->refresh();
        return $this->config->homepageSettings();
    }

    /**
     * EN: Saves global policy under the configuration lock without changing personal selections.
     *
     * HR: Sprema globalnu politiku pod konfiguracijskim lockom; ne mijenja osobne odabire.
     *
     * @param array<string, mixed> $publicTarget
     * @param array<string, mixed> $authenticatedTarget
     */
    public function saveSettings(
        array $publicTarget,
        array $authenticatedTarget,
        bool $allowUserSelection,
    ): void {
        $publicTarget = $this->normalizeTarget($publicTarget);
        $authenticatedTarget = $this->normalizeTarget($authenticatedTarget);
        $this->config->configurationStore()->mutate(
            static fn(array $current): array => WorkspaceValue::stringKeyArray(ConfigArrayMerger::merge(
                $current,
                ['homepage' => [
                    'public_target' => $publicTarget,
                    'authenticated_target' => $authenticatedTarget,
                    'allow_user_selection' => $allowUserSelection,
                ]],
            )),
        );
        $this->config->refresh();
    }

    /**
     * EN: Returns the personal homepage ID or zero when the user follows the default policy.
     *
     * HR: Vraća ID osobne naslovnice ili nulu kada korisnik slijedi zadanu politiku.
     */
    public function userNodeId(int $userId): int
    {
        return $this->userTarget($userId)['node_id'];
    }

    /**
     * EN: Returns the structured personal target or an empty target that inherits defaults.
     *
     * HR: Vraća strukturirani osobni cilj ili prazan cilj koji nasljeđuje zadanu politiku.
     *
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    public function userTarget(int $userId): array
    {
        if (!$this->tablesReady() || $userId <= 0) {
            return $this->emptyTarget();
        }

        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
            ->where('user_id', '=', $userId)
            ->first();

        return is_array($row)
        ? $this->targetFromUserRow(WorkspaceValue::stringKeyArray($row))
        : $this->emptyTarget();
    }

    /**
     * EN: Stores a personal page or removes the selection so the user inherits the default.
     *
     * HR: Sprema osobnu stranicu ili briše odabir kako bi korisnik naslijedio zadanu.
     */
    public function saveUserNodeId(int $userId, int $nodeId): void
    {
        $this->saveUserTarget($userId, ['type' => 'page', 'node_id' => $nodeId]);
    }

    /**
     * EN: Stores a structured personal target or deletes it to restore inheritance.
     *
     * HR: Sprema strukturirani osobni cilj ili briše odabir radi nasljeđivanja.
     *
     * @param array<string, mixed> $target
     */
    public function saveUserTarget(int $userId, array $target): void
    {
        $this->assertTablesReady();
        if ($userId <= 0) {
            throw new RuntimeException(__('Za osobnu naslovnicu potrebna je prijava.'));
        }

        $target = $this->normalizeTarget($target);
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
            ->where('user_id', '=', $userId);
        if ($target['type'] === 'default') {
            $query->delete();
            return;
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'node_id' => $target['node_id'],
            'updated_at' => $now,
        ];
        $payload = [
            ...$payload,
            'target_type' => $target['type'],
            'workspace_id' => $target['workspace_id'] > 0 ? $target['workspace_id'] : null,
            'show_tree' => $target['show_tree'],
            'show_display_options' => $target['show_display_options'],
        ];

        $existing = $query->first();
        if (is_array($existing)) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
                ->where('user_id', '=', $userId)
                ->update($payload);
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)->insert([
            'user_id' => $userId,
            'created_at' => $now,
            ...$payload,
        ]);
    }

    /**
     * EN: Normalizes the current structured target.
     *
     * HR: Normalizira aktualni strukturirani cilj.
     *
     * @param array<string, mixed> $target
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function normalizeTarget(array $target): array
    {
        $type = is_scalar($target['type'] ?? null)
        ? strtolower(trim((string)$target['type']))
        : 'default';
        if (!in_array($type, ['page', 'shorts'], true)) {
            return $this->emptyTarget();
        }

        $normalized = [
            'type' => $type,
            'node_id' => $type === 'page' ? WorkspaceValue::int($target['node_id'] ?? 0) : 0,
            'workspace_id' => $type === 'shorts' ? WorkspaceValue::int($target['workspace_id'] ?? 0) : 0,
            'show_tree' => (bool)($target['show_tree'] ?? true),
            'show_display_options' => (bool)($target['show_display_options'] ?? true),
        ];

        return ($type === 'page' && $normalized['node_id'] > 0)
        || ($type === 'shorts' && $normalized['workspace_id'] > 0)
        ? $normalized
        : $this->emptyTarget();
    }

    /**
     * EN: Converts a current personal-homepage row into a structured target.
     *
     * HR: Pretvara aktualni zapis osobne naslovnice u strukturirani cilj.
     *
     * @param array<string, mixed> $row
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function targetFromUserRow(array $row): array
    {
        return $this->normalizeTarget([
            'type' => $row['target_type'] ?? 'page',
            'node_id' => $row['node_id'] ?? 0,
            'workspace_id' => $row['workspace_id'] ?? 0,
            'show_tree' => (bool)($row['show_tree'] ?? true),
            'show_display_options' => (bool)($row['show_display_options'] ?? true),
        ]);
    }

    /**
     * EN: Returns a neutral target that delegates homepage resolution to the next rule.
     *
     * HR: Vraća neutralni cilj koji prepušta naslovnicu sljedećem pravilu.
     *
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function emptyTarget(): array
    {
        return [
            'type' => 'default',
            'node_id' => 0,
            'workspace_id' => 0,
            'show_tree' => true,
            'show_display_options' => true,
        ];
    }

    /**
     * EN: Stops persistence with a clear message when the migration is missing.
     *
     * HR: Zaustavlja spremanje s jasnom porukom kada migracija nedostaje.
     */
    private function assertTablesReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija naslovnice područja nije primijenjena.'));
        }
    }
}
