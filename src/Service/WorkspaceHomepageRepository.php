<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use RuntimeException;

use function date;
use function in_array;
use function is_array;
use function is_scalar;
use function strtolower;
use function trim;

/**
 * HR: Sprema naslovnice aplikacije i osobne korisničke odabire u tablice
 * Workspace modula. Auth modul ne poznaje niti čita ove podatke.
 * EN: Stores application homepages and personal user selections in Workspace
 * module tables. The Auth module neither knows nor reads these data.
 */
final readonly class WorkspaceHomepageRepository
{
    private const SETTINGS_ROW_ID = 1;

    /**
     * HR: Prima ORM bazu za prenosiv rad na SQLiteu, PostgreSQL-u i MySQL-u.
     * EN: Receives the ORM database for portable SQLite, PostgreSQL, and MySQL operation.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * HR: Provjerava jesu li primijenjene tablice nadogradnje naslovnice.
     * EN: Checks whether the homepage upgrade tables have been applied.
     */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES);
    }

    /**
     * HR: Provjerava podržava li instalirana shema strukturirane Shorts naslovnice.
     * EN: Checks whether the installed schema supports structured Shorts homepages.
     */
    public function viewOptionsReady(): bool
    {
        if (!$this->tablesReady()) {
            return false;
        }

        $schema = $this->database->schema();

        return $schema->hasColumns(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS, [
            'public_target_type',
            'public_workspace_id',
            'public_show_tree',
            'public_show_display_options',
            'authenticated_target_type',
            'authenticated_workspace_id',
            'authenticated_show_tree',
            'authenticated_show_display_options',
        ]) && $schema->hasColumns(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES, [
            'target_type',
            'workspace_id',
            'show_tree',
            'show_display_options',
        ]);
    }

    /**
     * HR: Vraća jedine globalne postavke ili sigurne zadane vrijednosti.
     * EN: Returns the single global settings row or safe defaults.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if (!$this->tablesReady()) {
            return $this->defaultSettings();
        }

        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
            ->where('id', '=', self::SETTINGS_ROW_ID)
            ->first();
        if (!is_array($row)) {
            return $this->defaultSettings();
        }

        $normalizedRow = WorkspaceValue::stringKeyArray($row);
        $publicTarget = $this->targetFromRow($normalizedRow, 'public');
        $authenticatedTarget = $this->targetFromRow($normalizedRow, 'authenticated');

        return [
            'public_node_id' => $publicTarget['node_id'],
            'authenticated_node_id' => $authenticatedTarget['node_id'],
            'public_target' => $publicTarget,
            'authenticated_target' => $authenticatedTarget,
            'allow_user_selection' => (bool)($row['allow_user_selection'] ?? true),
        ];
    }

    /**
     * HR: Sprema javnu, prijavljenu i korisničku politiku u jednom retku.
     * EN: Stores the public, authenticated, and user-selection policy in one row.
     *
     * @param array<string, mixed>|int $publicTarget
     * @param array<string, mixed>|int $authenticatedTarget
     */
    public function saveSettings(
        array|int $publicTarget,
        array|int $authenticatedTarget,
        bool $allowUserSelection,
        int $actorUserId,
    ): void {
        $this->assertTablesReady();
        $publicTarget = $this->normalizeTarget($publicTarget);
        $authenticatedTarget = $this->normalizeTarget($authenticatedTarget);
        if (
            !$this->viewOptionsReady()
            && ($publicTarget['type'] === 'shorts' || $authenticatedTarget['type'] === 'shorts')
        ) {
            throw new RuntimeException(__('Migracija opcija prikaza naslovnice nije primijenjena.'));
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'public_node_id' => $publicTarget['node_id'] > 0 ? $publicTarget['node_id'] : null,
            'authenticated_node_id' => $authenticatedTarget['node_id'] > 0
            ? $authenticatedTarget['node_id']
            : null,
            'allow_user_selection' => $allowUserSelection,
            'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'updated_at' => $now,
        ];
        if ($this->viewOptionsReady()) {
            $payload = [
                ...$payload,
                ...$this->targetPayload('public', $publicTarget),
                ...$this->targetPayload('authenticated', $authenticatedTarget),
            ];
        }

        $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
            ->where('id', '=', self::SETTINGS_ROW_ID)
            ->first();
        if (is_array($existing)) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
                ->where('id', '=', self::SETTINGS_ROW_ID)
                ->update($payload);
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)->insert([
            'id' => self::SETTINGS_ROW_ID,
            'created_at' => $now,
            ...$payload,
        ]);
    }

    /**
     * HR: Vraća ID osobne naslovnice ili nulu kada korisnik slijedi zadanu politiku.
     * EN: Returns the personal homepage ID or zero when the user follows the default policy.
     */
    public function userNodeId(int $userId): int
    {
        return $this->userTarget($userId)['node_id'];
    }

    /**
     * HR: Vraća strukturirani osobni cilj ili prazan cilj koji nasljeđuje zadanu politiku.
     * EN: Returns the structured personal target or an empty target that inherits defaults.
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
     * HR: Sprema osobnu stranicu ili briše odabir kako bi korisnik naslijedio zadanu.
     * EN: Stores a personal page or removes the selection so the user inherits the default.
     */
    public function saveUserNodeId(int $userId, int $nodeId): void
    {
        $this->saveUserTarget($userId, $nodeId);
    }

    /**
     * HR: Sprema strukturirani osobni cilj ili briše odabir radi nasljeđivanja.
     * EN: Stores a structured personal target or deletes it to restore inheritance.
     *
     * @param array<string, mixed>|int $target
     */
    public function saveUserTarget(int $userId, array|int $target): void
    {
        $this->assertTablesReady();
        if ($userId <= 0) {
            throw new RuntimeException(__('Za osobnu naslovnicu potrebna je prijava.'));
        }

        $target = $this->normalizeTarget($target);
        if (!$this->viewOptionsReady() && $target['type'] === 'shorts') {
            throw new RuntimeException(__('Migracija opcija prikaza naslovnice nije primijenjena.'));
        }

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
        if ($this->viewOptionsReady()) {
            $payload = [
                ...$payload,
                'target_type' => $target['type'],
                'workspace_id' => $target['workspace_id'] > 0 ? $target['workspace_id'] : null,
                'show_tree' => $target['show_tree'],
                'show_display_options' => $target['show_display_options'],
            ];
        }

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
     * HR: Vraća sigurne vrijednosti prije prvog administratorskog spremanja.
     * EN: Returns safe values before the first administrator save.
     *
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        return [
            'public_node_id' => 0,
            'authenticated_node_id' => 0,
            'public_target' => $this->emptyTarget(),
            'authenticated_target' => $this->emptyTarget(),
            'allow_user_selection' => true,
        ];
    }

    /**
     * HR: Normalizira stari ID stranice ili novi strukturirani cilj.
     * EN: Normalizes a legacy page ID or a new structured target.
     *
     * @param array<string, mixed>|int $target
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function normalizeTarget(array|int $target): array
    {
        if (is_int($target)) {
            return $target > 0
            ? [...$this->emptyTarget(), 'type' => 'page', 'node_id' => $target]
            : $this->emptyTarget();
        }

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
     * HR: Čita jedan javni ili prijavljeni cilj iz retka postavki.
     * EN: Reads one public or authenticated target from the settings row.
     *
     * @param array<string, mixed> $row
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function targetFromRow(array $row, string $prefix): array
    {
        $nodeId = WorkspaceValue::int($row[$prefix . '_node_id'] ?? 0);
        if (!$this->viewOptionsReady()) {
            return $nodeId > 0
            ? [...$this->emptyTarget(), 'type' => 'page', 'node_id' => $nodeId]
            : $this->emptyTarget();
        }

        return $this->normalizeTarget([
            'type' => $row[$prefix . '_target_type'] ?? ($nodeId > 0 ? 'page' : 'default'),
            'node_id' => $nodeId,
            'workspace_id' => $row[$prefix . '_workspace_id'] ?? 0,
            'show_tree' => (bool)($row[$prefix . '_show_tree'] ?? true),
            'show_display_options' => (bool)($row[$prefix . '_show_display_options'] ?? true),
        ]);
    }

    /**
     * HR: Pretvara korisnički zapis naslovnice u kompatibilan strukturirani cilj.
     * EN: Converts a user homepage row into a backward-compatible structured target.
     *
     * @param array<string, mixed> $row
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function targetFromUserRow(array $row): array
    {
        if (!$this->viewOptionsReady()) {
            return $this->normalizeTarget(WorkspaceValue::int($row['node_id'] ?? 0));
        }

        return $this->normalizeTarget([
            'type' => $row['target_type'] ?? 'page',
            'node_id' => $row['node_id'] ?? 0,
            'workspace_id' => $row['workspace_id'] ?? 0,
            'show_tree' => (bool)($row['show_tree'] ?? true),
            'show_display_options' => (bool)($row['show_display_options'] ?? true),
        ]);
    }

    /**
     * HR: Gradi stupce jednog javnog ili prijavljenog strukturiranog cilja.
     * EN: Builds columns for one public or authenticated structured target.
     *
     * @param array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool} $target
     * @return array<string, mixed>
     */
    private function targetPayload(string $prefix, array $target): array
    {
        return [
            $prefix . '_target_type' => $target['type'] === 'default' ? 'page' : $target['type'],
            $prefix . '_workspace_id' => $target['workspace_id'] > 0 ? $target['workspace_id'] : null,
            $prefix . '_show_tree' => $target['show_tree'],
            $prefix . '_show_display_options' => $target['show_display_options'],
        ];
    }

    /**
     * HR: Vraća neutralni cilj koji prepušta naslovnicu sljedećem pravilu.
     * EN: Returns a neutral target that delegates homepage resolution to the next rule.
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
     * HR: Zaustavlja spremanje s jasnom porukom kada migracija nedostaje.
     * EN: Stops persistence with a clear message when the migration is missing.
     */
    private function assertTablesReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija naslovnice područja nije primijenjena.'));
        }
    }
}
