<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function preg_replace;
use function sort;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function trim;
use function usort;

final readonly class WorkspaceRepository
{
    public const SUBJECT_USER = 'user';

    public const SUBJECT_GROUP = 'group';

    public const SUBJECT_PUBLIC = 'public';

    public const SUBJECT_AUTHENTICATED = 'authenticated';

    public const BUILT_IN_SUBJECT_ID = 1;

    private const DIRECTORY_RESULT_LIMIT = 20;

    private const RESTRICTION_CANDIDATE_LIMIT = 100;

    private const AUTH_USERS_TABLE = 'auth_users';

    private const AUTH_GROUPS_TABLE = 'auth_groups';

    private const AUTH_USER_GROUPS_TABLE = 'auth_user_groups';

    private const AUTH_ATTRIBUTE_VALUES_TABLE = 'auth_user_attribute_values';

    /**
     * HR: Prima ORM bazu i postaje jedino mjesto koje izravno čita Workspace tablice.
     * EN: Receives the ORM database and becomes the only direct reader of Workspace tables.
     */
    public function __construct(
        private Database $database,
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
        private ?WorkspaceContentChangeBatch $contentChangeBatch = null,
        private ?WorkspaceRepositoryRequestCache $requestCache = null,
    ) {
    }

    /**
     * HR: Provjerava je li inicijalna Workspace migracija primijenjena.
     * EN: Checks whether the initial Workspace migration has been applied.
     */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACES)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_ACL)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
        && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES);
    }

    /**
     * HR: Vraća sva aktivna područja za kasnije filtriranje kroz ACL servis.
     * EN: Returns every active workspace for later filtering by the ACL service.
     *
     * @return list<array<string, mixed>>
     */
    public function activeWorkspaces(): array
    {
        $this->assertTablesReady();

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('is_deleted', '=', false)
                ->orderBy('name', 'ASC')
                ->get(),
        );
    }

    /**
     * HR: Vraća sva područja administratoru, uključujući arhivirana.
     * EN: Returns all non-deleted workspaces to administrators, including archived ones.
     *
     * @return list<array<string, mixed>>
     */
    public function allWorkspaces(): array
    {
        return $this->activeWorkspaces();
    }

    /**
     * HR: Vraća soft-obrisana područja za administratorsko vraćanje.
     * EN: Returns soft-deleted workspaces for administrator restoration.
     *
     * @return list<array<string, mixed>>
     */
    public function deletedWorkspaces(): array
    {
        $this->assertTablesReady();

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('is_deleted', '=', true)
                ->orderBy('deleted_at', 'DESC')
                ->get(),
        );
    }

    /**
     * HR: Učitava aktivno područje po javnom slugu.
     * EN: Loads an active workspace by its public slug.
     *
     * @return array<string, mixed>|null
     */
    public function findWorkspaceBySlug(string $slug): ?array
    {
        $this->assertTablesReady();
        $slug = $this->slug($slug, 'workspace');
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('slug', '=', $slug)
            ->where('is_deleted', '=', false)
            ->first();

        return $this->row($row);
    }

    /**
     * HR: Učitava područje po internom ID-u, po potrebi i kada je obrisano.
     * EN: Loads a workspace by internal ID, optionally including deleted rows.
     *
     * @return array<string, mixed>|null
     */
    public function findWorkspaceById(int $workspaceId, bool $includeDeleted = false): ?array
    {
        $this->assertTablesReady();
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', $workspaceId);
        if (!$includeDeleted) {
            $query->where('is_deleted', '=', false);
        }

        $row = $query->first();

        return $this->row($row);
    }

    /**
     * HR: Kreira ili ažurira područje nakon što je poslovni sloj provjerio
     *     ovlasti. Opcionalni početni upravitelj omogućuje sustavskom izvršitelju
     *     da zadrži audit, a sva prava dodijeli drugom korisniku.
     * EN: Creates or updates a Workspace after the business layer has checked
     *     permissions. An optional initial manager lets a system actor retain
     *     the audit record while granting every permission to another user.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveWorkspace(
        array $data,
        int $actorUserId,
        ?int $initialManagerUserId = null,
    ): array {
        $this->assertTablesReady();
        $workspaceId = $this->intValue($data['id'] ?? 0);
        $existing = $workspaceId > 0 ? $this->findWorkspaceById($workspaceId) : null;
        $primaryLanguage = $this->languageCode($data['primary_language'] ?? 'hr');
        $submittedNameTranslations = $this->translationMap($data['name_translations'] ?? null);
        $submittedDescriptionTranslations = $this->translationMap($data['description_translations'] ?? null);
        $supportedLanguages = $this->languageCodes(
            $data['supported_languages']
                ?? array_merge(
                    [$primaryLanguage],
                    array_keys($submittedNameTranslations),
                    array_keys($submittedDescriptionTranslations),
                ),
        );
        $nameTranslations = $this->normalizedTranslations(
            $data['name_translations'] ?? null,
            $supportedLanguages,
            $primaryLanguage,
            $data['name'] ?? '',
        );
        $descriptionTranslations = $this->normalizedTranslations(
            $data['description_translations'] ?? null,
            $supportedLanguages,
            $primaryLanguage,
            $data['description'] ?? '',
        );
        $name = $nameTranslations[$primaryLanguage] ?? '';
        if ($name === '') {
            throw new RuntimeException(__('Naziv područja je obavezan.'));
        }

        $slug = $this->uniqueWorkspaceSlug(
            $this->slug($data['slug'] ?? $name, 'workspace'),
            $workspaceId,
        );
        $visibility = $this->visibility(
            $data['visibility'] ?? (is_array($existing) ? $existing['visibility'] ?? 'restricted' : 'restricted'),
        );
        $now = date('Y-m-d H:i:s');
        $values = [
            'slug' => $slug,
            'name' => $name,
            'name_translations' => $this->encodeTranslations($nameTranslations),
            'description' => $descriptionTranslations[$primaryLanguage] ?? '',
            'description_translations' => $this->encodeTranslations($descriptionTranslations),
            'visibility' => $visibility,
            'tree_visibility' => $this->displayPolicy(
                $data['tree_visibility']
                    ?? (is_array($existing) ? $existing['tree_visibility'] ?? 'inherit' : 'inherit'),
            ),
            'contents_visibility' => $this->displayPolicy(
                $data['contents_visibility']
                    ?? (is_array($existing) ? $existing['contents_visibility'] ?? 'inherit' : 'inherit'),
            ),
            'is_archived' => $this->boolValue($data['is_archived'] ?? false),
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $now,
        ];

        $isNew = $workspaceId <= 0;
        if (!$isNew) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('id', '=', $workspaceId)
                ->where('is_deleted', '=', false)
                ->update($values);
        } else {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
                'uuid' => $this->uuid(),
                'is_deleted' => false,
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
                ...$values,
            ]);
            $workspaceId = (int)$this->database->lastInsertId();
        }

        if ($isNew) {
            $this->insertBuiltInVisibilityAcl($workspaceId, $visibility, $now);
            // HR: Sustavski servis može evidentirati stvarnog izvršitelja, a
            //     početno upravljanje dodijeliti korisniku za kojega stvara
            //     područje. Obično korisničko stvaranje i dalje koristi isti ID.
            // EN: A system service may retain the real actor for auditing while
            //     granting initial management to the user for whom it creates
            //     the Workspace. Regular creation still uses the same ID.
            $this->grantWorkspaceManagement(
                $workspaceId,
                $initialManagerUserId ?? $actorUserId,
            );
        }

        $workspace = $this->findWorkspaceById($workspaceId);
        if (!is_array($workspace)) {
            throw new RuntimeException(__('Spremljeno područje nije moguće učitati.'));
        }

        $this->contentChanged(
            $workspaceId,
            $isNew ? 'workspace_created' : 'workspace_updated',
            actorUserId: $actorUserId,
        );

        return $workspace;
    }

    /**
     * HR: Soft-briše područje, dok stablo i povezni dokumenti ostaju dostupni za vraćanje.
     * EN: Soft-deletes a workspace while preserving its tree and linked documents for restoration.
     */
    public function softDeleteWorkspace(int $workspaceId, int $actorUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', $workspaceId)
            ->where('is_deleted', '=', false)
            ->update([
                'is_deleted' => true,
                'deleted_by_user_id' => $actorUserId,
                'deleted_at' => $now,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => $now,
            ]);

        $this->contentChanged($workspaceId, 'workspace_deleted', actorUserId: $actorUserId);
    }

    /**
     * HR: Vraća soft-obrisano područje pod slobodnim slugom.
     * EN: Restores a soft-deleted workspace under an available slug.
     *
     * @return array<string, mixed>
     */
    public function restoreWorkspace(int $workspaceId, string $preferredSlug, int $actorUserId): array
    {
        $workspace = $this->findWorkspaceById($workspaceId, true);
        if (!is_array($workspace) || !(bool)($workspace['is_deleted'] ?? false)) {
            throw new RuntimeException(__('Obrisano područje nije pronađeno.'));
        }

        $slug = $this->uniqueWorkspaceSlug(
            $this->slug($preferredSlug !== '' ? $preferredSlug : $workspace['slug'] ?? '', 'workspace'),
            $workspaceId,
        );
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', $workspaceId)
            ->update([
                'slug' => $slug,
                'is_deleted' => false,
                'deleted_by_user_id' => null,
                'deleted_at' => null,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => $now,
            ]);

        $restored = $this->findWorkspaceById($workspaceId);
        if (!is_array($restored)) {
            throw new RuntimeException(__('Vraćeno područje nije moguće učitati.'));
        }

        $this->contentChanged($workspaceId, 'workspace_restored', actorUserId: $actorUserId);

        return $restored;
    }

    /**
     * HR: Nepovratno uklanja isključivo soft-obrisano područje i sve retke u
     *     vlasništvu Workspace modula. Podatke drugih modula prethodno uklanja
     *     orkestracijski servis preko javnog događaja.
     * EN: Irreversibly removes only a soft-deleted Workspace and all rows owned
     *     by the Workspace module. The orchestration service first removes data
     *     owned by other modules through a public event.
     */
    public function permanentlyDeleteWorkspace(int $workspaceId): void
    {
        $workspace = $this->findWorkspaceById($workspaceId, true);
        if (!is_array($workspace) || !(bool)($workspace['is_deleted'] ?? false)) {
            throw new RuntimeException(__('Samo obrisano područje može se trajno ukloniti.'));
        }

        $nodeIds = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->select(['id'])
            ->where('workspace_id', '=', $workspaceId)
            ->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['id'] ?? null) && (int)$row['id'] > 0) {
                $nodeIds[] = (int)$row['id'];
            }
        }

        $this->database->transaction(function (Database $database) use ($workspaceId, $nodeIds): void {
            if ($nodeIds !== []) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                    ->whereIn('node_id', $nodeIds)
                    ->delete();
            }

            $database->table(ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES)
                ->where('workspace_id', '=', $workspaceId)
                ->delete();
            foreach (['public_node_id', 'authenticated_node_id'] as $column) {
                if ($nodeIds !== []) {
                    $database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
                        ->whereIn($column, $nodeIds)
                        ->update([$column => null]);
                }
            }

            foreach (['public_workspace_id', 'authenticated_workspace_id'] as $column) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS)
                    ->where($column, '=', $workspaceId)
                    ->update([$column => null]);
            }

            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)
                ->where('source_workspace_id', '=', $workspaceId)
                ->delete();
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_BACKLINKS)
                ->where('target_workspace_id', '=', $workspaceId)
                ->delete();
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
                ->where('workspace_id', '=', $workspaceId)
                ->delete();
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->delete();
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
                ->where('workspace_id', '=', $workspaceId)
                ->delete();
            $database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('id', '=', $workspaceId)
                ->where('is_deleted', '=', true)
                ->delete();
        });

        $this->clearWorkspaceNodeCache($workspaceId);
    }

    /**
     * HR: Vraća ACL retke jednog područja.
     * EN: Returns ACL rows for one workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function workspaceAclRows(int $workspaceId): array
    {
        $this->assertTablesReady();

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
                ->where('workspace_id', '=', $workspaceId)
                ->orderBy('subject_type', 'ASC')
                ->orderBy('subject_id', 'ASC')
                ->get(),
        );
    }

    /**
     * HR: Vraća ACL retke više područja jednim upitom kako popisi ne bi
     *     izvodili zaseban upit za svako područje.
     * EN: Returns ACL rows for multiple Workspaces in one query so listings do
     *     not execute a separate query for every Workspace.
     *
     * @param list<int> $workspaceIds
     * @return list<array<string, mixed>>
     */
    public function workspaceAclRowsForWorkspaces(array $workspaceIds): array
    {
        if ($workspaceIds === []) {
            return [];
        }

        $this->assertTablesReady();

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
                ->whereIn('workspace_id', $workspaceIds)
                ->orderBy('workspace_id', 'ASC')
                ->orderBy('subject_type', 'ASC')
                ->orderBy('subject_id', 'ASC')
                ->get(),
        );
    }

    /**
     * HR: Vraća samo ACL subjekte koji su dodani području, s prikaznim nazivima
     *     dohvaćenima grupno. Stara visibility vrijednost se prikazuje kao
     *     ugrađeni subjekt dok se ACL prvi put ne spremi.
     * EN: Returns only ACL subjects assigned to a Workspace, with display labels
     *     fetched in batches. A legacy visibility value is represented as a
     *     built-in subject until the ACL is saved for the first time.
     *
     * @return list<array<string, mixed>>
     */
    public function workspaceAclSubjects(int $workspaceId): array
    {
        $rows = $this->workspaceAclRows($workspaceId);
        $hasPublic = false;
        $hasAuthenticated = false;
        foreach ($rows as $row) {
            $type = $this->stringValue($row['subject_type'] ?? '');
            $hasPublic = $hasPublic || $type === self::SUBJECT_PUBLIC;
            $hasAuthenticated = $hasAuthenticated || $type === self::SUBJECT_AUTHENTICATED;
        }

        if (!$hasPublic && !$hasAuthenticated) {
            $workspace = $this->findWorkspaceById($workspaceId);
            $visibility = $this->visibility($workspace['visibility'] ?? 'restricted');
            if ($visibility === self::SUBJECT_PUBLIC || $visibility === self::SUBJECT_AUTHENTICATED) {
                $rows[] = [
                    'workspace_id' => $workspaceId,
                    'subject_type' => $visibility,
                    'subject_id' => self::BUILT_IN_SUBJECT_ID,
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_publish' => false,
                    'can_delete' => false,
                    'can_manage' => false,
                ];
            }
        }

        $userIds = [];
        $groupIds = [];
        foreach ($rows as $row) {
            $type = $this->stringValue($row['subject_type'] ?? '');
            $id = $this->intValue($row['subject_id'] ?? 0);
            if ($type === self::SUBJECT_USER && $id > 0) {
                $userIds[] = $id;
            } elseif ($type === self::SUBJECT_GROUP && $id > 0) {
                $groupIds[] = $id;
            }
        }

        $users = $this->usersByIds($userIds);
        $groups = $this->groupsByIds($groupIds);
        $subjects = [];
        foreach ($rows as $row) {
            $type = $this->stringValue($row['subject_type'] ?? '');
            $id = $this->intValue($row['subject_id'] ?? 0);
            $subject = $row;
            $subject['category'] = $type === self::SUBJECT_USER ? self::SUBJECT_USER : self::SUBJECT_GROUP;
            $subject['is_builtin'] = in_array(
                $type,
                [self::SUBJECT_PUBLIC, self::SUBJECT_AUTHENTICATED],
                true,
            );
            $subject['is_read_only'] = $type === self::SUBJECT_PUBLIC;

            if ($type === self::SUBJECT_USER && isset($users[$id])) {
                $subject['label'] = $this->stringValue($users[$id]['label'] ?? '');
            } elseif ($type === self::SUBJECT_GROUP && isset($groups[$id])) {
                $subject['label'] = $this->stringValue($groups[$id]['group_name'] ?? '');
            } elseif ($type === self::SUBJECT_PUBLIC && $id === self::BUILT_IN_SUBJECT_ID) {
                $subject['label'] = __('Javno');
            } elseif ($type === self::SUBJECT_AUTHENTICATED && $id === self::BUILT_IN_SUBJECT_ID) {
                $subject['label'] = __('Svi prijavljeni');
            } else {
                continue;
            }

            $subjects[] = $subject;
        }

        usort(
            $subjects,
            static fn(array $left, array $right): int => strcasecmp(
                (string)($left['label'] ?? ''),
                (string)($right['label'] ?? ''),
            ),
        );

        return $subjects;
    }

    /**
     * HR: Vraća ACL subjekte s pravima koja su stvarno naslijeđena na
     *     odabranom čvoru, nakon ograničenja svih njegovih predaka. Izravno
     *     ograničenje samog odabranog čvora namjerno se ne primjenjuje.
     * EN: Returns ACL subjects with the rights actually inherited at the
     *     selected node after all ancestor restrictions. The selected node's
     *     own direct restriction is intentionally not applied.
     *
     * @return list<array<string, mixed>>
     */
    public function inheritedAclSubjectsAtNode(int $workspaceId, int $nodeId): array
    {
        $subjects = $this->workspaceAclSubjects($workspaceId);
        $ancestorIds = $this->ancestorNodeIds($workspaceId, $nodeId);
        if ($ancestorIds !== [] && end($ancestorIds) === $nodeId) {
            array_pop($ancestorIds);
        }

        $rowsByNode = [];
        foreach ($this->nodeAclRowsForNodes($ancestorIds) as $row) {
            $rowsByNode[$this->intValue($row['node_id'] ?? 0)][] = $row;
        }

        $permissions = [
            'can_view',
            'can_add',
            'can_edit',
            'can_publish',
            'can_delete',
            'can_manage',
        ];

        foreach ($subjects as &$subject) {
            $subjectType = $this->stringValue($subject['subject_type'] ?? '');
            $subjectId = $this->intValue($subject['subject_id'] ?? 0);
            foreach ($ancestorIds as $ancestorId) {
                $restrictionRows = $rowsByNode[$ancestorId] ?? [];
                if ($restrictionRows === []) {
                    continue;
                }

                $matchingRow = null;
                foreach ($restrictionRows as $row) {
                    if (
                        $this->stringValue($row['subject_type'] ?? '') === $subjectType
                        && $this->intValue($row['subject_id'] ?? 0) === $subjectId
                    ) {
                        $matchingRow = $row;
                        break;
                    }
                }

                foreach ($permissions as $permission) {
                    $subject[$permission] = (bool)($subject[$permission] ?? false)
                    && is_array($matchingRow)
                    && (bool)($matchingRow[$permission] ?? false);
                }
            }
        }

        unset($subject);

        return $subjects;
    }

    /**
     * HR: Vraća korisnike koji već imaju pravo na područje izravno ili
     *     članstvom u grupi, s pravima naslijeđenima do odabranog čvora.
     *     Administratori nisu ponuđeni jer njihova prava nije moguće ograničiti
     *     na stranici.
     * EN: Returns users who already hold Workspace rights directly or through
     *     group membership, with permissions inherited up to the selected node.
     *     Administrators are excluded because page restrictions cannot narrow
     *     their rights.
     *
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     */
    public function restrictionUserSubjectsAtNode(
        int $workspaceId,
        int $nodeId,
        array $userIds,
    ): array {
        $users = $this->usersByIds($userIds);

        return $this->restrictionUserSubjectsFromUsers(
            $workspaceId,
            $nodeId,
            array_values($users),
        );
    }

    /**
     * HR: Vraća trenutačno spremljena korisnička ograničenja jednoga čvora
     *     zajedno s pravima koja su vrijedila neposredno prije toga čvora.
     * EN: Returns the currently stored user restrictions for one node together
     *     with the permissions inherited immediately before that node.
     *
     * @return list<array<string, mixed>>
     */
    public function nodeRestrictionSubjects(int $workspaceId, int $nodeId): array
    {
        $userIds = [];
        foreach ($this->nodeAclRows($nodeId) as $row) {
            if ($this->stringValue($row['subject_type'] ?? '') !== self::SUBJECT_USER) {
                continue;
            }

            $userId = $this->intValue($row['subject_id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        return $this->restrictionUserSubjectsAtNode($workspaceId, $nodeId, $userIds);
    }

    /**
     * HR: Pretražuje samo korisnike kojima se na odabranoj stranici stvarno
     *     mogu dodatno ograničiti prava. Kandidati i rezultat su strogo ograničeni.
     * EN: Searches only users whose existing permissions can actually be narrowed
     *     on the selected page. Both candidates and results are strictly bounded.
     *
     * @return list<array<string, mixed>>
     */
    public function searchRestrictionUsers(
        int $workspaceId,
        int $nodeId,
        string $search,
    ): array {
        $users = $this->searchUsers(trim($search), self::RESTRICTION_CANDIDATE_LIMIT);

        return array_slice(
            $this->restrictionUserSubjectsFromUsers($workspaceId, $nodeId, $users),
            0,
            self::DIRECTORY_RESULT_LIMIT,
        );
    }

    /**
     * HR: Pretražuje aktivne korisnike ili grupe u malom, ograničenom skupu
     *     rezultata za asinkrone ACL i administracijske pickere.
     * EN: Searches active users or groups in a small, bounded result set for
     *     asynchronous ACL and administration pickers.
     *
     * @return list<array<string, mixed>>
     */
    public function searchDirectorySubjects(string $category, string $search): array
    {
        $search = trim($search);
        if ($category === self::SUBJECT_USER) {
            return $this->searchUsers($search, self::DIRECTORY_RESULT_LIMIT);
        }

        if ($category !== self::SUBJECT_GROUP) {
            return [];
        }

        return $this->searchGroups($search, self::DIRECTORY_RESULT_LIMIT);
    }

    /**
     * HR: Učitava samo zadane aktivne korisnike ili grupe za spremljene postavke.
     * EN: Loads only the supplied active users or groups for persisted settings.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function directorySubjectsByIds(string $category, array $ids): array
    {
        if ($category === self::SUBJECT_USER) {
            return $this->sortPickerUsers(array_values($this->usersByIds($ids, true)));
        }

        if ($category !== self::SUBJECT_GROUP) {
            return [];
        }

        $subjects = [];
        foreach ($this->groupsByIds($ids) as $group) {
            $id = $this->intValue($group['id'] ?? 0);
            $label = $this->stringValue($group['group_name'] ?? '');
            if ($id <= 0) {
                continue;
            }

            if ($label === '') {
                continue;
            }

            $subjects[] = [
                'id' => $id,
                'type' => self::SUBJECT_GROUP,
                'category' => self::SUBJECT_GROUP,
                'label' => $label,
                'is_builtin' => false,
                'is_read_only' => false,
            ];
        }

        usort(
            $subjects,
            fn(array $left, array $right): int => strcasecmp(
                $this->stringValue($left['label'] ?? ''),
                $this->stringValue($right['label'] ?? ''),
            ),
        );

        return $subjects;
    }

    /**
     * HR: Zamjenjuje ACL područja stvarno odabranim korisnicima, grupama i
     *     ugrađenim publikama iz administratorske forme.
     * EN: Replaces Workspace ACL entries with the users, groups, and built-in
     *     audiences actually selected in the administration form.
     *
     * @param array<string, mixed> $acl
     */
    public function replaceWorkspaceAcl(int $workspaceId, array $acl): void
    {
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', $workspaceId)
            ->delete();

        $now = date('Y-m-d H:i:s');
        $savedBuiltIns = [];
        foreach (
            [
                self::SUBJECT_USER,
                self::SUBJECT_GROUP,
                self::SUBJECT_PUBLIC,
                self::SUBJECT_AUTHENTICATED,
            ] as $subjectType
        ) {
            $subjects = is_array($acl[$subjectType] ?? null) ? $acl[$subjectType] : [];
            foreach ($subjects as $subjectId => $permissions) {
                $subjectId = $this->intValue($subjectId);
                if ($subjectId <= 0) {
                    continue;
                }

                if (!is_array($permissions)) {
                    continue;
                }

                if (!$this->subjectExists($subjectType, $subjectId)) {
                    continue;
                }

                $normalized = $this->permissionValues(WorkspaceValue::stringKeyArray($permissions));
                if ($subjectType === self::SUBJECT_PUBLIC) {
                    $normalized = [
                        'can_view' => $normalized['can_view'],
                        'can_add' => false,
                        'can_edit' => false,
                        'can_publish' => false,
                        'can_delete' => false,
                        'can_manage' => false,
                    ];
                }

                if (!$this->hasAnyPermission($normalized)) {
                    continue;
                }

                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)->insert([
                    'workspace_id' => $workspaceId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    ...$normalized,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($subjectType === self::SUBJECT_PUBLIC || $subjectType === self::SUBJECT_AUTHENTICATED) {
                    $savedBuiltIns[$subjectType] = $normalized;
                }
            }
        }

        $visibility = isset($savedBuiltIns[self::SUBJECT_PUBLIC])
        ? self::SUBJECT_PUBLIC
        : (isset($savedBuiltIns[self::SUBJECT_AUTHENTICATED])
            ? self::SUBJECT_AUTHENTICATED
            : 'restricted');
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', $workspaceId)
            ->update([
                'visibility' => $visibility,
                'updated_at' => $now,
            ]);
    }

    /**
     * HR: Vraća sve aktivne čvorove područja u stabilnom hijerarhijskom redoslijedu.
     * EN: Returns every active workspace node in stable hierarchical order.
     *
     * @return list<array<string, mixed>>
     */
    public function nodesForWorkspace(int $workspaceId): array
    {
        $this->assertTablesReady();

        if (
            $this->requestCache instanceof WorkspaceRepositoryRequestCache
            && array_key_exists($workspaceId, $this->requestCache->nodesByWorkspace)
        ) {
            return $this->requestCache->nodesByWorkspace[$workspaceId];
        }

        $nodes = $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->where('is_enabled', '=', true)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(),
        );

        if ($this->requestCache instanceof WorkspaceRepositoryRequestCache) {
            $this->requestCache->nodesByWorkspace[$workspaceId] = $nodes;
            foreach ($nodes as $node) {
                $this->rememberNode($node);
            }
        }

        return $nodes;
    }

    /**
     * HR: Vraća početnu stranicu područja bez učitavanja cijelog stabla.
     * EN: Returns the Workspace homepage without loading the complete tree.
     *
     * @return array<string,mixed>|null
     */
    public function homepageNode(int $workspaceId): ?array
    {
        $this->assertTablesReady();

        return $this->row(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->where('node_type', '=', 'document')
                ->where('is_homepage', '=', true)
                ->where('is_enabled', '=', true)
                ->first(),
        );
    }

    /**
     * HR: Vraća lanac aktivnih predaka od korijena do zadanog čvora koristeći
     *     samo potrebne retke. Time ACL nasljeđivanje ostaje potpuno, a veliko
     *     stablo se ne učitava za običan pregled stranice.
     * EN: Returns the active ancestor chain from the root to the supplied node
     *     using only required rows. ACL inheritance stays complete without
     *     loading a large tree for a regular page view.
     *
     * @return list<array<string,mixed>>
     */
    public function ancestorNodes(int $workspaceId, int $nodeId): array
    {
        $this->assertTablesReady();
        if (
            $this->requestCache instanceof WorkspaceRepositoryRequestCache
            && isset($this->requestCache->ancestorNodesByWorkspaceAndNode[$workspaceId][$nodeId])
        ) {
            return $this->requestCache->ancestorNodesByWorkspaceAndNode[$workspaceId][$nodeId];
        }

        $chain = [];
        $visited = [];
        $currentId = $nodeId;

        while ($currentId > 0 && !isset($visited[$currentId]) && count($chain) < 256) {
            $visited[$currentId] = true;
            $node = $this->row(
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('workspace_id', '=', $workspaceId)
                    ->where('id', '=', $currentId)
                    ->where('is_enabled', '=', true)
                    ->first(),
            );
            if (!is_array($node)) {
                break;
            }

            $chain[] = $node;
            $currentId = $this->intValue($node['parent_id'] ?? 0);
        }

        $chain = array_reverse($chain);
        if ($this->requestCache instanceof WorkspaceRepositoryRequestCache) {
            $this->requestCache->ancestorNodesByWorkspaceAndNode[$workspaceId][$nodeId] = $chain;
        }

        return $chain;
    }

    /**
     * HR: Dohvaća mali prozor stabla: korijene, aktivni put te neposrednu djecu
     *     korijena i aktivnog puta. Ostale se grane učitavaju na zahtjev.
     * EN: Loads a compact tree window: roots, the active path, and immediate
     *     children of roots and the active path. Other branches load on demand.
     *
     * @return list<array<string,mixed>>
     */
    public function treeWindowNodes(int $workspaceId, int $activeNodeId = 0): array
    {
        $this->assertTablesReady();
        $roots = $this->rows($this->database->fetchAll(
            'SELECT * FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES
            . ' WHERE workspace_id = ? AND is_enabled = ? AND parent_id IS NULL'
            . ' ORDER BY sort_order ASC, id ASC',
            [$workspaceId, true],
        ));
        $ancestors = $activeNodeId > 0 ? $this->ancestorNodes($workspaceId, $activeNodeId) : [];

        $expandedParentIds = [];
        foreach ([...$roots, ...$ancestors] as $node) {
            $nodeId = $this->intValue($node['id'] ?? 0);
            if ($nodeId > 0) {
                $expandedParentIds[$nodeId] = $nodeId;
            }
        }

        $nodes = [...$roots, ...$ancestors];
        if ($expandedParentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($expandedParentIds), '?'));
            $children = $this->rows($this->database->fetchAll(
                'SELECT * FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES
                . ' WHERE workspace_id = ? AND is_enabled = ? AND parent_id IN (' . $placeholders . ')'
                . ' ORDER BY sort_order ASC, id ASC',
                [$workspaceId, true, ...array_values($expandedParentIds)],
            ));
            $nodes = [...$nodes, ...$children];
        }

        return $this->markTreeWindowNodes($workspaceId, $nodes, array_keys($expandedParentIds));
    }

    /**
     * HR: Dohvaća roditeljski put i neposrednu djecu jedne grane radi sigurnog
     *     ACL-filtriranog učitavanja na zahtjev.
     * EN: Loads the parent path and immediate children of one branch for safe,
     *     ACL-filtered on-demand rendering.
     *
     * @return list<array<string,mixed>>
     */
    public function treeBranchNodes(int $workspaceId, int $parentId): array
    {
        $ancestors = $this->ancestorNodes($workspaceId, $parentId);
        if ($ancestors === []) {
            return [];
        }

        $children = $this->rows($this->database->fetchAll(
            'SELECT * FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES
            . ' WHERE workspace_id = ? AND is_enabled = ? AND parent_id = ?'
            . ' ORDER BY sort_order ASC, id ASC',
            [$workspaceId, true, $parentId],
        ));

        return $this->markTreeWindowNodes($workspaceId, [...$ancestors, ...$children], [$parentId]);
    }

    /**
     * HR: Uklanja duplikate i označava postoje li skrivena djeca te je li grana učitana.
     * EN: Removes duplicates and marks hidden children and loaded branches.
     *
     * @param list<array<string,mixed>> $nodes
     * @param list<int> $loadedParentIds
     * @return list<array<string,mixed>>
     */
    private function markTreeWindowNodes(int $workspaceId, array $nodes, array $loadedParentIds): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $nodeId = $this->intValue($node['id'] ?? 0);
            if ($nodeId > 0) {
                $byId[$nodeId] = $node;
            }
        }

        if ($byId === []) {
            return [];
        }

        $nodeIds = array_keys($byId);
        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $parentRows = $this->rows($this->database->fetchAll(
            'SELECT DISTINCT parent_id FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES
            . ' WHERE workspace_id = ? AND is_enabled = ? AND parent_id IN (' . $placeholders . ')',
            [$workspaceId, true, ...$nodeIds],
        ));
        $parentsWithChildren = [];
        foreach ($parentRows as $row) {
            $parentId = $this->intValue($row['parent_id'] ?? 0);
            if ($parentId > 0) {
                $parentsWithChildren[$parentId] = true;
            }
        }

        $loaded = array_fill_keys($loadedParentIds, true);
        foreach ($byId as $nodeId => &$node) {
            $node['has_children'] = isset($parentsWithChildren[$nodeId]);
            $node['children_loaded'] = !$node['has_children'] || isset($loaded[$nodeId]);
        }

        unset($node);

        return array_values($byId);
    }

    /**
     * HR: Vraća aktivne dokument-stranice označene zadanom poslovnom oznakom.
     * EN: Returns active document pages carrying the requested business label.
     *
     * @return list<array<string,mixed>>
     */
    public function nodesWithLabel(int $workspaceId, string $label): array
    {
        $this->assertTablesReady();
        $label = $this->nodeLabel($label);
        if ($workspaceId <= 0 || $label === '') {
            return [];
        }

        return $this->rows($this->database->fetchAll(
            'SELECT n.* FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES . ' n '
            . 'INNER JOIN ' . ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS . ' l ON l.node_id = n.id '
            . 'WHERE n.workspace_id = ? AND n.node_type = ? AND n.is_enabled = ? AND l.label = ? '
            . 'ORDER BY n.updated_at DESC, n.title ASC',
            [$workspaceId, 'document', true, $label],
        ));
    }

    /**
     * HR: Vraća sve oznake jednog čvora.
     * EN: Returns all labels assigned to one node.
     *
     * @return list<string>
     */
    public function nodeLabels(int $nodeId): array
    {
        return $this->nodeLabelsForNodes([$nodeId])[$nodeId] ?? [];
    }

    /**
     * HR: Vraća oznake više čvorova jednim upitom kako stablo ne bi stvaralo N+1 upite.
     * EN: Returns labels for multiple nodes in one query so tree rendering avoids N+1 queries.
     *
     * @param list<int> $nodeIds
     * @return array<int,list<string>>
     */
    public function nodeLabelsForNodes(array $nodeIds): array
    {
        $this->assertTablesReady();
        $nodeIds = array_values(array_unique(array_filter(array_map(
            static fn(int $nodeId): int => max($nodeId, 0),
            $nodeIds,
        ))));
        if ($nodeIds === []) {
            return [];
        }

        $labels = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
            ->whereIn('node_id', $nodeIds)
            ->orderBy('node_id', 'ASC')
            ->orderBy('label', 'ASC')
            ->get() as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            if (!is_scalar($row['label'] ?? null)) {
                continue;
            }

            $nodeId = WorkspaceValue::int($row['node_id'] ?? 0);
            if ($nodeId > 0) {
                $labels[$nodeId][] = (string)$row['label'];
            }
        }

        return $labels;
    }

    /**
     * HR: Atomarno zamjenjuje oznake stranice normaliziranim skupom bez duplikata.
     * EN: Atomically replaces page labels with a normalized duplicate-free set.
     *
     * @param list<string> $labels
     */
    public function replaceNodeLabels(int $nodeId, array $labels): void
    {
        $this->assertTablesReady();
        $normalized = [];
        foreach ($labels as $label) {
            $label = $this->nodeLabel($label);
            if ($label !== '') {
                $normalized[$label] = true;
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->database->transaction(static function (Database $database) use ($nodeId, $normalized, $now): void {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                ->where('node_id', '=', $nodeId)
                ->delete();
            foreach (array_keys($normalized) as $label) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
                    'node_id' => $nodeId,
                    'label' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * HR: Vraća strukturirana svojstva jedne stranice.
     * EN: Returns one page's structured properties.
     *
     * @return list<array{key:string,label:string,type:string,value:string,sort_order:int}>
     */
    public function nodeProperties(int $nodeId): array
    {
        return $this->nodePropertiesForNodes([$nodeId])[$nodeId] ?? [];
    }

    /**
     * HR: Vraća strukturirana svojstva više stranica jednim upitom za dinamičke izvještaje.
     * EN: Returns structured properties for multiple pages in one query for dynamic reports.
     *
     * @param list<int> $nodeIds
     * @return array<int,list<array{key:string,label:string,type:string,value:string,sort_order:int}>>
     */
    public function nodePropertiesForNodes(array $nodeIds): array
    {
        $this->assertTablesReady();
        $nodeIds = array_values(array_unique(array_filter(array_map(
            static fn(int $nodeId): int => max($nodeId, 0),
            $nodeIds,
        ))));
        if ($nodeIds === []) {
            return [];
        }

        $properties = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
            ->whereIn('node_id', $nodeIds)
            ->orderBy('node_id', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('property_label', 'ASC')
            ->get() as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $nodeId = $this->intValue($row['node_id'] ?? 0);
            $key = $this->nodePropertyKey($row['property_key'] ?? '');
            if ($nodeId <= 0) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            $properties[$nodeId][] = [
                'key' => $key,
                'label' => $this->stringValue($row['property_label'] ?? $key),
                'type' => $this->nodePropertyType($row['property_type'] ?? 'text'),
                'value' => $this->stringValue($row['property_value'] ?? ''),
                'sort_order' => $this->intValue($row['sort_order'] ?? 100),
            ];
        }

        return $properties;
    }

    /**
     * HR: Atomarno zamjenjuje sva strukturirana svojstva stranice.
     * EN: Atomically replaces all structured properties assigned to a page.
     *
     * @param list<array<string,mixed>> $properties
     */
    public function replaceNodeProperties(int $nodeId, array $properties): void
    {
        $this->assertTablesReady();
        $normalized = [];
        foreach ($properties as $index => $property) {
            if (!is_array($property)) {
                continue;
            }

            $label = $this->stringValue($property['label'] ?? $property['key'] ?? '');
            $key = $this->nodePropertyKey($property['key'] ?? $label);
            if ($key === '') {
                continue;
            }

            if ($label === '') {
                continue;
            }

            $normalized[$key] = [
                'key' => $key,
                'label' => mb_substr($label, 0, 255),
                'type' => $this->nodePropertyType($property['type'] ?? 'text'),
                'value' => $this->stringValue($property['value'] ?? ''),
                'sort_order' => max(0, $this->intValue($property['sort_order'] ?? (($index + 1) * 10))),
            ];
        }

        $now = date('Y-m-d H:i:s');
        $this->database->transaction(static function (Database $database) use ($nodeId, $normalized, $now): void {
            $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                ->where('node_id', '=', $nodeId)
                ->delete();
            foreach ($normalized as $property) {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)->insert([
                    'node_id' => $nodeId,
                    'property_key' => $property['key'],
                    'property_label' => $property['label'],
                    'property_type' => $property['type'],
                    'property_value' => $property['value'],
                    'sort_order' => $property['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * HR: Vraća javne nazive korisnika indeksirane po internom ID-u.
     * EN: Returns public user display names indexed by internal ID.
     *
     * @param list<int> $userIds
     * @return array<int,string>
     */
    public function userDisplayNames(array $userIds): array
    {
        $decorated = $this->usersByIds($userIds);
        $names = [];
        foreach ($decorated as $userId => $user) {
            $names[$userId] = $this->stringValue($user['label'] ?? __('Korisnik'));
        }

        return $names;
    }

    /**
     * HR: Učitava aktivni čvor po internom ID-u.
     * EN: Loads an active node by internal ID.
     *
     * @return array<string, mixed>|null
     */
    public function findNodeById(int $nodeId): ?array
    {
        $this->assertTablesReady();
        if (
            $this->requestCache instanceof WorkspaceRepositoryRequestCache
            && array_key_exists($nodeId, $this->requestCache->nodesById)
        ) {
            return $this->requestCache->nodesById[$nodeId];
        }

        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('id', '=', $nodeId)
            ->where('is_enabled', '=', true)
            ->first();

        return $this->rememberNode($this->row($row));
    }

    /**
     * HR: Učitava aktivni čvor po području i URL slugu.
     * EN: Loads an active node by workspace and URL slug.
     *
     * @return array<string, mixed>|null
     */
    public function findNodeBySlug(int $workspaceId, string $slug): ?array
    {
        $this->assertTablesReady();
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)
            ->where('slug', '=', $this->slug($slug, 'page'))
            ->where('is_enabled', '=', true)
            ->first();

        return $this->rememberNode($this->row($row));
    }

    /**
     * HR: Pronalazi aktivni Workspace čvor povezan s editor dokumentom.
     * EN: Finds the active Workspace node linked to an editor document.
     *
     * @return array<string, mixed>|null
     */
    public function findNodeByDocumentKey(string $documentKey): ?array
    {
        $this->assertTablesReady();
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('document_key', '=', trim($documentKey))
            ->where('node_type', '=', 'document')
            ->where('is_enabled', '=', true)
            ->first();

        return $this->rememberNode($this->row($row));
    }

    /**
     * HR: Grupno učitava aktivne čvorove dokumenata i njihova područja. Ova
     *     metoda služi popisima i biračima koji moraju provjeriti velik broj
     *     dokumenata bez izvođenja zasebnih upita za svaki dokument.
     * EN: Batch-loads active document nodes and their workspaces. This method
     *     supports lists and pickers that must authorize many documents without
     *     issuing separate queries for every document.
     *
     * @param list<string> $documentKeys
     * @return array<string, array{
     *     node: array<string, mixed>,
     *     workspace: array<string, mixed>
     * }>
     */
    public function documentContextsByKeys(array $documentKeys): array
    {
        $this->assertTablesReady();

        $normalizedKeys = [];
        foreach ($documentKeys as $documentKey) {
            /*
             * HR: PHP brojčane string-ključeve polja pretvara u cijele brojeve.
             *     Pretvorba u tekst zato mora prethoditi normalizaciji.
             * EN: PHP converts numeric string array keys to integers. Cast to
             *     string before normalization so imported numeric keys work too.
             */
            $documentKey = trim((string)$documentKey);
            if ($documentKey !== '') {
                $normalizedKeys[$documentKey] = true;
            }
        }

        $documentKeys = array_keys($normalizedKeys);
        if ($documentKeys === []) {
            return [];
        }

        $nodes = $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->whereIn('document_key', $documentKeys)
                ->where('node_type', '=', 'document')
                ->where('is_enabled', '=', true)
                ->get(),
        );

        $workspaceIds = [];
        foreach ($nodes as $node) {
            $workspaceId = $this->intValue($node['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                $workspaceIds[$workspaceId] = true;
            }
        }

        if ($workspaceIds === []) {
            return [];
        }

        $workspacesById = [];
        foreach (
            $this->rows(
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                    ->whereIn('id', array_keys($workspaceIds))
                    ->where('is_deleted', '=', false)
                    ->get(),
            ) as $workspace
        ) {
            $workspaceId = $this->intValue($workspace['id'] ?? 0);
            if ($workspaceId > 0) {
                $workspacesById[$workspaceId] = $workspace;
            }
        }

        $contexts = [];
        foreach ($nodes as $node) {
            $documentKey = trim($this->stringValue($node['document_key'] ?? ''));
            $workspaceId = $this->intValue($node['workspace_id'] ?? 0);
            if ($documentKey === '') {
                continue;
            }

            if (!isset($workspacesById[$workspaceId])) {
                continue;
            }

            $contexts[$documentKey] = [
                'node' => $node,
                'workspace' => $workspacesById[$workspaceId],
            ];
        }

        return $contexts;
    }

    /**
     * HR: Učitava workflow stanje jednog jezika stranice ili vraća null dok
     *     stranica još nije ušla u kontrolirani proces objave.
     * EN: Loads one page-language workflow state or returns null until the page
     *     has entered the managed publishing process.
     *
     * @return array<string, mixed>|null
     */
    public function nodeWorkflow(int $nodeId, string $language): ?array
    {
        $this->assertTablesReady();
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
            ->where('node_id', '=', $nodeId)
            ->where('language_code', '=', $this->language($language))
            ->first();

        return $this->row($row);
    }

    /**
     * HR: Jednim prenosivim upitom učitava aktivni dokument-čvor, njegovo
     *     područje i workflow odabranog jezika za izvedene indekse.
     * EN: Loads an active document node, its Workspace, and the selected
     *     language workflow in one portable query for derived indexes.
     *
     * @return array<string, mixed>|null
     */
    public function publishedNodeContext(int $nodeId, string $language): ?array
    {
        $this->assertTablesReady();
        if ($nodeId <= 0) {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT '
            . 'n.id AS node_id, n.workspace_id, n.node_type, n.slug AS node_slug, '
            . 'n.title AS node_title, n.title_translations AS node_title_translations, n.document_key, '
            . 'w.slug AS workspace_slug, w.name AS workspace_name, '
            . 'w.name_translations AS workspace_name_translations, '
            . 'f.status, f.current_version_number, f.published_version_number, '
            . 'f.published_by_user_id, f.published_at, '
            . 'u.login_identifier AS author_login_identifier '
            . 'FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES . ' n '
            . 'INNER JOIN ' . ModuleWorkspace::TABLE_WORKSPACES . ' w ON w.id = n.workspace_id '
            . 'LEFT JOIN ' . ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS . ' f '
            . 'ON f.node_id = n.id AND f.language_code = ? '
            . 'LEFT JOIN ' . ModuleAuth::TABLE_AUTH_USERS . ' u ON u.id = f.published_by_user_id '
            . 'WHERE n.id = ? AND n.is_enabled = ? AND w.is_deleted = ?',
            [$this->language($language), $nodeId, true, false],
        );

        return $this->row($row);
    }

    /**
     * HR: Grupno učitava workflow stanja zadanih stranica za jedan jezik kako
     *     velika stabla ne bi izvodila zaseban upit za svaki čvor.
     * EN: Loads workflow states for the requested pages and one locale in a
     *     single batch so large trees do not issue one query per node.
     *
     * @param list<int> $nodeIds
     * @return array<int, array<string, mixed>>
     */
    public function nodeWorkflowsForNodes(array $nodeIds, string $language): array
    {
        $this->assertTablesReady();
        $nodeIds = array_values(array_unique(array_filter(
            $nodeIds,
            static fn(int $nodeId): bool => $nodeId > 0,
        )));
        if ($nodeIds === []) {
            return [];
        }

        $indexed = [];
        foreach (
            $this->rows(
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                    ->where('language_code', '=', $this->language($language))
                    ->whereIn('node_id', $nodeIds)
                    ->get(),
            ) as $workflow
        ) {
            $nodeId = $this->intValue($workflow['node_id'] ?? 0);
            if ($nodeId > 0) {
                $indexed[$nodeId] = $workflow;
            }
        }

        return $indexed;
    }

    /**
     * HR: Grupno učitava workflow stanja svih jezika za zadane stranice. To
     * omogućuje naslovnici aplikacije siguran jezični fallback bez N+1 upita.
     * EN: Loads workflow states for every language of the requested pages in
     * one batch. This lets the application homepage use a safe locale fallback
     * without N+1 queries.
     *
     * @param list<int> $nodeIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function nodeWorkflowsForNodesAllLanguages(array $nodeIds): array
    {
        $this->assertTablesReady();
        $nodeIds = array_values(array_unique(array_filter(
            $nodeIds,
            static fn(int $nodeId): bool => $nodeId > 0,
        )));
        if ($nodeIds === []) {
            return [];
        }

        $indexed = [];
        foreach (
            $this->rows(
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                    ->whereIn('node_id', $nodeIds)
                    ->orderBy('language_code', 'ASC')
                    ->get(),
            ) as $workflow
        ) {
            $nodeId = $this->intValue($workflow['node_id'] ?? 0);
            if ($nodeId > 0) {
                $indexed[$nodeId][] = $workflow;
            }
        }

        return $indexed;
    }

    /**
     * HR: Sprema aktualnu workflow snimku uz jedinstven zapis po stranici i
     *     jeziku. Poslovni servis prije poziva provjerava dopušteni prijelaz.
     * EN: Stores the current workflow snapshot in one unique row per page and
     *     language. The business service validates the transition first.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function saveNodeWorkflow(
        int $nodeId,
        string $language,
        array $values,
        int $actorUserId,
    ): array {
        $this->assertTablesReady();
        $language = $this->language($language);
        $existing = $this->nodeWorkflow($nodeId, $language);
        $now = date('Y-m-d H:i:s');
        $payload = [
            'status' => $this->stringValue($values['status'] ?? $existing['status'] ?? 'draft'),
            'current_version_number' => $this->nullablePositiveInt(
                $this->workflowValue($values, $existing, 'current_version_number'),
            ),
            'published_version_number' => $this->nullablePositiveInt(
                $this->workflowValue($values, $existing, 'published_version_number'),
            ),
            'submitted_by_user_id' => $this->nullablePositiveInt(
                $this->workflowValue($values, $existing, 'submitted_by_user_id'),
            ),
            'submitted_at' => $this->workflowValue($values, $existing, 'submitted_at'),
            'published_by_user_id' => $this->nullablePositiveInt(
                $this->workflowValue($values, $existing, 'published_by_user_id'),
            ),
            'published_at' => $this->workflowValue($values, $existing, 'published_at'),
            'archived_by_user_id' => $this->nullablePositiveInt(
                $this->workflowValue($values, $existing, 'archived_by_user_id'),
            ),
            'archived_at' => $this->workflowValue($values, $existing, 'archived_at'),
            'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'updated_at' => $now,
        ];

        if (is_array($existing)) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                ->where('id', '=', $this->intValue($existing['id'] ?? 0))
                ->update($payload);
        } else {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert([
                'node_id' => $nodeId,
                'language_code' => $language,
                'created_at' => $now,
                ...$payload,
            ]);
        }

        $saved = $this->nodeWorkflow($nodeId, $language);
        if (!is_array($saved)) {
            throw new RuntimeException(__('Workflow stranice nije moguće spremiti.'));
        }

        /*
         * HR: Nacrt koji još pokazuje zadnju objavljenu verziju ne mijenja indeks.
         *     Objavu, arhivu i povratak u neobjavljeni nacrt šaljemo slušateljima.
         * EN: A draft that still points to the last published version does not
         *     change the index. Publish, archive, and unpublished restore do.
         */
        $publishedChanged = $this->nullablePositiveInt($existing['published_version_number'] ?? null)
        !== $this->nullablePositiveInt($saved['published_version_number'] ?? null);
        $archiveChanged = $this->stringValue($existing['status'] ?? '')
        !== $this->stringValue($saved['status'] ?? '')
        && in_array($this->stringValue($saved['status'] ?? ''), ['archived', 'published'], true);
        if ($publishedChanged || $archiveChanged) {
            $node = $this->findNodeById($nodeId);
            $workspaceId = is_array($node) ? $this->intValue($node['workspace_id'] ?? 0) : 0;
            $this->contentChanged(
                $workspaceId,
                'publication_changed',
                $nodeId,
                $language,
                $actorUserId,
            );
        }

        return $saved;
    }

    /**
     * HR: Kreira ili mijenja čvor stabla nakon centralne provjere ovlasti.
     * EN: Creates or updates a tree node after centralized permission checks.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveNode(int $workspaceId, array $data, int $actorUserId): array
    {
        $this->assertTablesReady();
        $nodeId = $this->intValue($data['id'] ?? 0);
        $primaryLanguage = $this->languageCode($data['primary_language'] ?? 'hr');
        $submittedTitleTranslations = $this->translationMap($data['title_translations'] ?? null);
        $supportedLanguages = $this->languageCodes(
            $data['supported_languages']
                ?? array_merge([$primaryLanguage], array_keys($submittedTitleTranslations)),
        );
        $titleTranslations = $this->normalizedTranslations(
            $data['title_translations'] ?? null,
            $supportedLanguages,
            $primaryLanguage,
            $data['title'] ?? '',
        );
        $title = $titleTranslations[$primaryLanguage] ?? '';
        if ($title === '') {
            throw new RuntimeException(__('Naslov čvora je obavezan.'));
        }

        $nodeType = $this->nodeType($data['node_type'] ?? 'document');
        $slugInput = $this->stringValue($data['slug'] ?? '');
        $slug = $this->uniqueNodeSlug(
            $workspaceId,
            $this->slug($slugInput !== '' ? $slugInput : $title, 'page'),
            $nodeId,
        );
        $parentId = $this->nullablePositiveInt($data['parent_id'] ?? null);
        if ($parentId !== null) {
            $parent = $this->findNodeById($parentId);
            if (!is_array($parent) || $this->intValue($parent['workspace_id'] ?? 0) !== $workspaceId) {
                throw new RuntimeException(__('Roditeljska stranica nije valjana.'));
            }

            if ($nodeId > 0 && $this->wouldCreateCycle($nodeId, $parentId)) {
                throw new RuntimeException(__('Stranicu nije moguće premjestiti u vlastitu podgranu.'));
            }
        }

        $documentKey = $nodeType === 'document'
        ? $this->stringValue($data['document_key'] ?? '')
        : null;
        $routeName = $nodeType === 'internal_link'
        ? $this->stringValue($data['route_name'] ?? '')
        : null;
        $targetUrl = in_array($nodeType, ['internal_link', 'external_link'], true)
        ? $this->stringValue($data['target_url'] ?? '')
        : null;
        if ($nodeType === 'document' && $documentKey === '') {
            throw new RuntimeException(__('HTML dokument nije odabran.'));
        }

        if ($nodeType === 'document') {
            $documentNode = $this->findNodeByDocumentKey((string)$documentKey);
            if (
                is_array($documentNode)
                && $this->intValue($documentNode['id'] ?? 0) !== $nodeId
            ) {
                throw new RuntimeException(__('HTML dokument već pripada drugoj stranici područja.'));
            }
        }

        if ($nodeType === 'external_link' && !$this->validExternalUrl((string)$targetUrl)) {
            throw new RuntimeException(__('Vanjski URL nije valjan.'));
        }

        if ($nodeType === 'internal_link' && $routeName === '' && $targetUrl === '') {
            throw new RuntimeException(__('Interna ruta ili putanja je obavezna.'));
        }

        if ($nodeType === 'internal_link' && $targetUrl !== '' && !$this->validInternalPath((string)$targetUrl)) {
            throw new RuntimeException(__('Interna putanja nije valjana.'));
        }

        $now = date('Y-m-d H:i:s');
        $existingNode = $nodeId > 0 ? $this->findNodeById($nodeId) : null;
        $values = [
            'workspace_id' => $workspaceId,
            'parent_id' => $parentId,
            'node_type' => $nodeType,
            'slug' => $slug,
            'title' => $title,
            'title_translations' => $this->encodeTranslations($titleTranslations),
            'document_key' => $documentKey,
            'route_name' => $routeName,
            'target_url' => $targetUrl,
            'sort_order' => $this->intValue($data['sort_order'] ?? 100),
            'is_homepage' => $nodeType === 'document' && $this->boolValue($data['is_homepage'] ?? false),
            'is_enabled' => true,
            'contents_visibility' => $this->displayPolicy(
                $data['contents_visibility'] ?? (is_array($existingNode)
                    ? $existingNode['contents_visibility'] ?? 'inherit'
                    : 'inherit'),
            ),
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $now,
        ];

        if ($values['is_homepage']) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->update(['is_homepage' => false]);
        }

        if ($nodeId > 0) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('id', '=', $nodeId)
                ->where('workspace_id', '=', $workspaceId)
                ->where('is_enabled', '=', true)
                ->update($values);
        } else {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
                'uuid' => $this->uuid(),
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
                ...$values,
            ]);
            $nodeId = (int)$this->database->lastInsertId();
        }

        $this->clearWorkspaceNodeCache($workspaceId);
        $node = $this->findNodeById($nodeId);
        if (!is_array($node)) {
            throw new RuntimeException(__('Spremljeni čvor nije moguće učitati.'));
        }

        $this->contentChanged(
            $workspaceId,
            $existingNode === null ? 'node_created' : 'node_updated',
            $nodeId,
            actorUserId: $actorUserId,
        );

        return $node;
    }

    /**
     * HR: Atomski zamjenjuje roditelja i redoslijed svih aktivnih čvorova jednog
     *     područja nakon potpune provjere roditelja i ciklusa.
     * EN: Atomically replaces parent and order values for every active node in
     *     one Workspace after complete parent and cycle validation.
     *
     * Početnici / Beginners:
     * HR: Cijelo se stablo provjerava prije prvog UPDATE-a, pa pogrešan raspored
     *     ne može ostaviti samo djelomično spremljenu hijerarhiju.
     * EN: The complete tree is validated before the first UPDATE, so an invalid
     *     arrangement cannot leave a partially saved hierarchy.
     *
     * @param list<array<string, mixed>> $placements
     */
    public function reorderNodes(int $workspaceId, array $placements, int $actorUserId): void
    {
        $this->assertTablesReady();
        $nodes = $this->nodesForWorkspace($workspaceId);
        if ($nodes === [] && $placements === []) {
            return;
        }

        $nodesById = [];
        foreach ($nodes as $node) {
            $nodesById[$this->intValue($node['id'] ?? 0)] = $node;
        }

        $normalized = [];
        foreach ($placements as $placement) {
            $nodeId = $this->intValue($placement['id'] ?? 0);
            if ($nodeId <= 0 || isset($normalized[$nodeId]) || !isset($nodesById[$nodeId])) {
                throw new RuntimeException(__('Raspored stabla nije potpun ili sadrži nepoznatu stranicu.'));
            }

            $normalized[$nodeId] = [
                'parent_id' => $this->nullablePositiveInt($placement['parent_id'] ?? null),
                'sort_order' => $this->intValue($placement['sort_order'] ?? 0),
            ];
        }

        $expectedIds = array_keys($nodesById);
        $submittedIds = array_keys($normalized);
        sort($expectedIds);
        sort($submittedIds);
        if (count($normalized) !== count($nodesById) || $expectedIds !== $submittedIds) {
            throw new RuntimeException(__('Raspored stabla nije potpun ili sadrži nepoznatu stranicu.'));
        }

        $parentByNode = [];
        foreach ($normalized as $nodeId => $placement) {
            $parentId = $placement['parent_id'];
            if ($parentId !== null) {
                $parent = $nodesById[$parentId] ?? null;
                if (!is_array($parent)) {
                    throw new RuntimeException(__('Roditeljska stranica nije valjana.'));
                }

                if ($this->stringValue($parent['node_type'] ?? '') !== 'document') {
                    throw new RuntimeException(__('Samo dokument-stranica može imati podređene stavke.'));
                }
            }

            $parentByNode[$nodeId] = $parentId ?? 0;
        }

        foreach (array_keys($parentByNode) as $nodeId) {
            $visited = [];
            $currentId = $nodeId;
            while ($currentId > 0) {
                if (isset($visited[$currentId])) {
                    throw new RuntimeException(__('Stranicu nije moguće premjestiti u vlastitu podgranu.'));
                }

                $visited[$currentId] = true;
                $currentId = $parentByNode[$currentId] ?? 0;
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->database->transaction(
            static function (Database $database) use (
                $workspaceId,
                $normalized,
                $actorUserId,
                $now,
            ): void {
                foreach ($normalized as $nodeId => $placement) {
                    $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                        ->where('id', '=', $nodeId)
                        ->where('workspace_id', '=', $workspaceId)
                        ->where('is_enabled', '=', true)
                        ->update([
                            'parent_id' => $placement['parent_id'],
                            'sort_order' => $placement['sort_order'],
                            'updated_by_user_id' => $actorUserId,
                            'updated_at' => $now,
                        ]);
                }
            },
        );
        $this->clearWorkspaceNodeCache($workspaceId);
    }

    /**
     * HR: Onemogućuje čvor i cijelu njegovu podgranu bez fizičkog brisanja zapisa.
     * EN: Disables a node and its complete subtree without physically deleting records.
     */
    public function disableNodeTree(int $workspaceId, int $nodeId, int $actorUserId): void
    {
        $ids = $this->descendantIds($workspaceId, $nodeId);
        $now = date('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('id', '=', $id)
                ->where('workspace_id', '=', $workspaceId)
                ->update([
                    'is_enabled' => false,
                    'is_homepage' => false,
                    'updated_by_user_id' => $actorUserId,
                    'updated_at' => $now,
                ]);
        }

        $this->clearWorkspaceNodeCache($workspaceId);
        $this->contentChanged($workspaceId, 'node_tree_deleted', $nodeId, actorUserId: $actorUserId);
    }

    /**
     * HR: Trajno briše jedan nikad objavljeni čvor, njegove ACL i workflow
     *     retke, a izravnu djecu premješta njegovu roditelju. Metoda ne služi
     *     za obične objavljene stranice koje koriste soft-delete.
     *
     * EN: Permanently deletes one never-published node and its ACL/workflow
     *     rows while reparenting direct children to its parent. This method is
     *     not used for ordinary published pages, which use soft deletion.
     */
    public function deleteUnpublishedNodePermanently(
        int $workspaceId,
        int $nodeId,
        int $actorUserId,
    ): void {
        $node = $this->findNodeById($nodeId);
        if (
            !is_array($node)
            || $this->intValue($node['workspace_id'] ?? 0) !== $workspaceId
        ) {
            throw new RuntimeException(__('Stranica nije pronađena.'));
        }

        $parentId = $this->nullablePositiveInt($node['parent_id'] ?? null);
        $now = date('Y-m-d H:i:s');
        if ($this->events instanceof EventDispatcherInterface) {
            $this->events->dispatch(new WorkspacePagesPermanentlyDeleting([[
                'workspace_id' => $workspaceId,
                'node_id' => $nodeId,
                'document_key' => $this->stringValue($node['document_key'] ?? ''),
            ]], $actorUserId));
        }

        $this->database->transaction(
            static function (Database $database) use (
                $workspaceId,
                $nodeId,
                $parentId,
                $actorUserId,
                $now,
            ): void {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('workspace_id', '=', $workspaceId)
                    ->where('parent_id', '=', $nodeId)
                    ->update([
                        'parent_id' => $parentId,
                        'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        'updated_at' => $now,
                    ]);
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                    ->where('node_id', '=', $nodeId)
                    ->delete();
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('workspace_id', '=', $workspaceId)
                    ->where('id', '=', $nodeId)
                    ->delete();
            },
        );
        $this->clearWorkspaceNodeCache($workspaceId);

        $this->contentChanged(
            $workspaceId,
            'unpublished_node_deleted',
            $nodeId,
            actorUserId: $actorUserId,
        );
    }

    /**
     * HR: Vraća aktivne čvorove odabrane podgrane prije brisanja ili druge skupne radnje.
     * EN: Returns active nodes in a selected subtree before deletion or another bulk action.
     *
     * @return list<array<string, mixed>>
     */
    public function nodesInSubtree(int $workspaceId, int $nodeId): array
    {
        $ids = $this->descendantIds($workspaceId, $nodeId);
        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $this->nodesForWorkspace($workspaceId),
            fn(array $node): bool => in_array($this->intValue($node['id'] ?? 0), $ids, true),
        ));
    }

    /**
     * HR: Vraća ograničenja postavljena izravno na jednom čvoru.
     * EN: Returns restrictions assigned directly to one node.
     *
     * @return list<array<string, mixed>>
     */
    public function nodeAclRows(int $nodeId): array
    {
        $this->assertTablesReady();

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
                ->where('node_id', '=', $nodeId)
                ->orderBy('subject_type', 'ASC')
                ->orderBy('subject_id', 'ASC')
                ->get(),
        );
    }

    /**
     * HR: Vraća sva ograničenja za zadani skup predaka u jednome prolazu.
     * EN: Returns all restrictions for a set of ancestors in one pass.
     *
     * @param list<int> $nodeIds
     * @return list<array<string, mixed>>
     */
    public function nodeAclRowsForNodes(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        return $this->rows(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
                ->whereIn('node_id', $nodeIds)
                ->get(),
        );
    }

    /**
     * HR: Vraća izravna korisnička prava za skup stranica u jednom upitu.
     * EN: Returns direct user grants for a set of pages in one query.
     *
     * @param list<int> $nodeIds
     * @return list<array<string, mixed>>
     */
    public function nodeDirectPermissionRowsForNodes(array $nodeIds, int $userId = 0): array
    {
        $nodeIds = array_values(array_unique(array_filter(
            $nodeIds,
            static fn(int $nodeId): bool => $nodeId > 0,
        )));
        if ($nodeIds === []) {
            return [];
        }

        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
            ->whereIn('node_id', $nodeIds);
        if ($userId > 0) {
            $query->where('user_id', '=', $userId);
        }

        return $this->rows($query->get());
    }

    /**
     * HR: Vraća izravna prava jednog čvora s javnim oznakama aktivnih korisnika.
     * EN: Returns one node's direct grants with public labels of active users.
     *
     * @return list<array<string, mixed>>
     */
    public function nodeDirectPermissionSubjects(int $nodeId): array
    {
        $rows = $this->nodeDirectPermissionRowsForNodes([$nodeId]);
        $users = $this->usersByIds(array_values(array_map(
            fn(array $row): int => $this->intValue($row['user_id'] ?? 0),
            $rows,
        )));
        $subjects = [];
        foreach ($rows as $row) {
            $userId = $this->intValue($row['user_id'] ?? 0);
            if (!isset($users[$userId])) {
                continue;
            }

            $subjects[] = [
                ...$row,
                'label' => $this->stringValue($users[$userId]['label'] ?? ''),
            ];
        }

        usort(
            $subjects,
            fn(array $left, array $right): int => strcasecmp(
                $this->stringValue($left['label'] ?? ''),
                $this->stringValue($right['label'] ?? ''),
            ),
        );

        return $subjects;
    }

    /**
     * HR: Vraća područja u kojima korisnik ima barem jednu izravno dopuštenu stranicu.
     * EN: Returns Workspaces in which the user has at least one directly granted page.
     *
     * @param list<int> $workspaceIds
     * @return list<int>
     */
    public function workspaceIdsWithDirectPermissionForUser(array $workspaceIds, int $userId): array
    {
        $workspaceIds = array_values(array_unique(array_filter(
            $workspaceIds,
            static fn(int $workspaceId): bool => $workspaceId > 0,
        )));
        if ($workspaceIds === [] || $userId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workspaceIds), '?'));
        $rows = $this->rows($this->database->fetchAll(
            'SELECT DISTINCT n.workspace_id FROM '
            . ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS . ' p'
            . ' INNER JOIN ' . ModuleWorkspace::TABLE_WORKSPACE_NODES . ' n ON n.id = p.node_id'
            . ' WHERE p.user_id = ? AND n.workspace_id IN (' . $placeholders . ')'
            . ' AND n.is_enabled = ? AND (p.can_view = ? OR p.can_edit = ? OR p.can_publish = ?)',
            [$userId, ...$workspaceIds, true, true, true, true],
        ));

        return array_values(array_unique(array_filter(array_map(
            fn(array $row): int => $this->intValue($row['workspace_id'] ?? 0),
            $rows,
        ))));
    }

    /**
     * HR: Učitava samo izravno dopuštene aktivne stranice jednog korisnika u području.
     * EN: Loads only a user's directly granted active pages in one Workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function directPermissionNodesForUser(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return [];
        }

        return $this->rows($this->database->fetchAll(
            'SELECT n.* FROM ' . ModuleWorkspace::TABLE_WORKSPACE_NODES . ' n'
            . ' INNER JOIN ' . ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS
            . ' p ON p.node_id = n.id'
            . ' WHERE n.workspace_id = ? AND n.is_enabled = ? AND p.user_id = ?'
            . ' AND (p.can_view = ? OR p.can_edit = ? OR p.can_publish = ?)'
            . ' ORDER BY n.sort_order ASC, n.id ASC',
            [$workspaceId, true, $userId, true, true, true],
        ));
    }

    /**
     * HR: Zamjenjuje izravna prava stranice aktivnim korisnicima i samo dopuštenim operacijama.
     * EN: Replaces a page's direct grants with active users and the allowed operations only.
     *
     * @param array<int|string, mixed> $permissions
     */
    public function replaceNodeDirectPermissions(
        int $workspaceId,
        int $nodeId,
        array $permissions,
    ): void {
        $node = $this->findNodeById($nodeId);
        if (!is_array($node) || $this->intValue($node['workspace_id'] ?? 0) !== $workspaceId) {
            throw new RuntimeException(__('Sadržaj nije pronađen'));
        }

        $requested = [];
        foreach ($permissions as $userId => $values) {
            $userId = $this->intValue($userId);
            if ($userId <= 0) {
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            $view = (bool)($values['can_view'] ?? false);
            $edit = (bool)($values['can_edit'] ?? false);
            $publish = (bool)($values['can_publish'] ?? false);
            if (!$view && !$edit && !$publish) {
                continue;
            }

            $requested[$userId] = [
                'can_view' => $view || $edit || $publish,
                'can_edit' => $edit,
                'can_publish' => $publish,
            ];
        }

        $activeUsers = $this->usersByIds(array_keys($requested));
        $now = date('Y-m-d H:i:s');
        $this->database->transaction(
            function (Database $database) use ($nodeId, $requested, $activeUsers, $now): void {
                $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->where('node_id', '=', $nodeId)
                    ->delete();

                foreach ($requested as $userId => $values) {
                    if (!isset($activeUsers[$userId])) {
                        continue;
                    }

                    $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)->insert([
                        'node_id' => $nodeId,
                        'user_id' => $userId,
                        ...$values,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            },
        );
    }

    /**
     * HR: Zamjenjuje ograničenja čvora samo subjektima koji već imaju Workspace ACL zapis.
     * EN: Replaces node restrictions only for subjects already present in the Workspace ACL.
     *
     * @param array<string, mixed> $acl
     */
    public function replaceNodeAcl(int $workspaceId, int $nodeId, array $acl): void
    {
        $requested = is_array($acl[self::SUBJECT_USER] ?? null) ? $acl[self::SUBJECT_USER] : [];
        $requestedUserIds = [];
        foreach (array_keys($requested) as $subjectId) {
            $subjectId = $this->intValue($subjectId);
            if ($subjectId > 0) {
                $requestedUserIds[] = $subjectId;
            }
        }

        $eligible = [];
        $eligibleSubjects = $this->restrictionUserSubjectsAtNode(
            $workspaceId,
            $nodeId,
            $requestedUserIds,
        );
        foreach ($eligibleSubjects as $subject) {
            $eligible[$this->intValue($subject['subject_id'] ?? 0)] = $subject;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
            ->where('node_id', '=', $nodeId)
            ->delete();
        $now = date('Y-m-d H:i:s');

        foreach ($requested as $subjectId => $permissions) {
            $subjectId = $this->intValue($subjectId);
            if ($subjectId <= 0) {
                continue;
            }

            if (!isset($eligible[$subjectId])) {
                continue;
            }

            if (!is_array($permissions)) {
                continue;
            }

            $normalized = $this->permissionValues(WorkspaceValue::stringKeyArray($permissions));
            $inherited = $this->permissionValues($eligible[$subjectId]);
            foreach (array_keys($normalized) as $permission) {
                $normalized[$permission] = $normalized[$permission] && $inherited[$permission];
            }

            // HR: Ne spremamo red koji ništa ne mijenja; uklanjanje svih
            //     uskraćivanja vraća potpuno nasljeđivanje.
            // EN: A row that changes nothing is omitted; removing every denial
            //     restores complete inheritance.
            if ($normalized === $inherited) {
                continue;
            }

            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)->insert([
                'node_id' => $nodeId,
                'subject_type' => self::SUBJECT_USER,
                'subject_id' => $subjectId,
                ...$normalized,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * HR: Paketno spaja izravna korisnička i grupna prava područja te na njih
     *     primjenjuje samo korisnička ograničenja predaka.
     * EN: Batch-merges direct-user and group Workspace rights, then applies only
     *     user-specific ancestor restrictions.
     *
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    private function restrictionUserSubjectsFromUsers(
        int $workspaceId,
        int $nodeId,
        array $users,
    ): array {
        if ($users === []) {
            return [];
        }

        $workspace = $this->findWorkspaceById($workspaceId);
        if (!is_array($workspace)) {
            return [];
        }

        $userIds = [];
        foreach ($users as $user) {
            $userId = $this->intValue($user['id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $administratorIds = [];
        if (
            $this->database->schema()->hasTable(self::AUTH_USERS_TABLE)
            && $this->database->schema()->hasColumn(self::AUTH_USERS_TABLE, 'is_admin')
        ) {
            foreach (
                $this->rows(
                    $this->database->table(self::AUTH_USERS_TABLE)
                        ->select(['id'])
                        ->where('is_active', '=', true)
                        ->where('is_admin', '=', true)
                        ->whereIn('id', array_values(array_unique($userIds)))
                        ->get(),
                ) as $administrator
            ) {
                $administratorIds[$this->intValue($administrator['id'] ?? 0)] = true;
            }
        }

        $groupsByUser = $this->groupIdsForUsers($userIds);
        $workspaceRows = $this->workspaceAclRows($workspaceId);
        $ancestorIds = $this->ancestorNodeIds($workspaceId, $nodeId);
        if ($ancestorIds !== [] && end($ancestorIds) === $nodeId) {
            array_pop($ancestorIds);
        }

        $restrictions = [];
        foreach ($this->nodeAclRowsForNodes($ancestorIds) as $row) {
            if ($this->stringValue($row['subject_type'] ?? '') !== self::SUBJECT_USER) {
                continue;
            }

            $restrictionNodeId = $this->intValue($row['node_id'] ?? 0);
            $restrictionUserId = $this->intValue($row['subject_id'] ?? 0);
            $restrictions[$restrictionNodeId][$restrictionUserId] = $row;
        }

        $subjects = [];
        foreach ($users as $user) {
            $userId = $this->intValue($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            if (isset($administratorIds[$userId])) {
                continue;
            }

            $permissions = $this->permissionValues([]);
            foreach ($workspaceRows as $row) {
                $subjectType = $this->stringValue($row['subject_type'] ?? '');
                $subjectId = $this->intValue($row['subject_id'] ?? 0);
                $matches = ($subjectType === self::SUBJECT_USER && $subjectId === $userId)
                || ($subjectType === self::SUBJECT_GROUP
                    && in_array($subjectId, $groupsByUser[$userId] ?? [], true));
                if (!$matches) {
                    continue;
                }

                $rowPermissions = $this->permissionValues($row);
                foreach (array_keys($permissions) as $permission) {
                    $permissions[$permission] = $permissions[$permission]
                    || $rowPermissions[$permission];
                }
            }

            if (!$this->hasAnyPermission($permissions)) {
                continue;
            }

            foreach ($ancestorIds as $ancestorId) {
                $row = $restrictions[$ancestorId][$userId] ?? null;
                if (!is_array($row)) {
                    continue;
                }

                $allowed = $this->permissionValues($row);
                foreach (array_keys($permissions) as $permission) {
                    $permissions[$permission] = $permissions[$permission] && $allowed[$permission];
                }
            }

            if (!$this->hasAnyPermission($permissions)) {
                continue;
            }

            $subjects[] = [
                ...$user,
                'subject_type' => self::SUBJECT_USER,
                'subject_id' => $userId,
                ...$permissions,
            ];
        }

        return $subjects;
    }

    /**
     * HR: Pretražuje korisnički login i display name bez učitavanja cijelog imenika.
     * EN: Searches user login and display name without loading the full directory.
     *
     * @return list<array<string, mixed>>
     */
    private function searchUsers(string $search, int $limit): array
    {
        if (!$this->database->schema()->hasTable(self::AUTH_USERS_TABLE)) {
            return [];
        }

        $usersById = [];
        $query = $this->database->table(self::AUTH_USERS_TABLE)
            ->where('is_active', '=', true)
            ->orderBy('login_identifier', 'ASC')
            ->limit($limit);
        if ($search !== '') {
            $query->where('login_identifier', 'LIKE', '%' . $search . '%');
        }

        foreach ($this->rows($query->get()) as $user) {
            $usersById[$this->intValue($user['id'] ?? 0)] = $user;
        }

        if (
            $search !== ''
            && $this->database->schema()->hasTable(self::AUTH_ATTRIBUTE_VALUES_TABLE)
        ) {
            $attributeRows = $this->rows(
                $this->database->table(self::AUTH_ATTRIBUTE_VALUES_TABLE)
                    ->select(['user_id'])
                    ->where('field_key', '=', 'display_name')
                    ->where('value_text', 'LIKE', '%' . $search . '%')
                    ->limit($limit)
                    ->get(),
            );
            $attributeUserIds = [];
            foreach ($attributeRows as $attributeRow) {
                $userId = $this->intValue($attributeRow['user_id'] ?? 0);
                if ($userId > 0) {
                    $attributeUserIds[] = $userId;
                }
            }

            if ($attributeUserIds !== []) {
                $matchingUsers = $this->rows(
                    $this->database->table(self::AUTH_USERS_TABLE)
                        ->where('is_active', '=', true)
                        ->whereIn('id', array_values(array_unique($attributeUserIds)))
                        ->get(),
                );
                foreach ($matchingUsers as $user) {
                    $usersById[$this->intValue($user['id'] ?? 0)] = $user;
                }
            }
        }

        return array_slice(
            $this->sortPickerUsers(array_values($this->decorateUsers(array_values($usersById), true))),
            0,
            $limit,
        );
    }

    /**
     * HR: Sortira picker korisnike po prezimenu i imenu te uklanja interna polja sortiranja.
     * EN: Sorts picker users by last and first name and removes internal sort fields.
     *
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    private function sortPickerUsers(array $users): array
    {
        usort(
            $users,
            function (array $left, array $right): int {
                foreach (['_picker_last_name', '_picker_first_name', 'label'] as $key) {
                    $comparison = strcasecmp(
                        $this->stringValue($left[$key] ?? ''),
                        $this->stringValue($right[$key] ?? ''),
                    );
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return $this->intValue($left['id'] ?? 0) <=> $this->intValue($right['id'] ?? 0);
            },
        );

        foreach ($users as &$user) {
            unset($user['_picker_last_name'], $user['_picker_first_name']);
        }

        unset($user);

        return $users;
    }

    /**
     * HR: Pretražuje stvarne grupe te u isti rezultat umeće ugrađene publike.
     * EN: Searches real groups and inserts built-in audiences into the same result.
     *
     * @return list<array<string, mixed>>
     */
    private function searchGroups(string $search, int $limit): array
    {
        $normalizedSearch = mb_strtolower($search);
        $subjects = [];
        foreach (
            [
                [self::SUBJECT_PUBLIC, __('Javno'), true],
                [self::SUBJECT_AUTHENTICATED, __('Svi prijavljeni'), false],
            ] as [$type, $label, $readOnly]
        ) {
            if ($search !== '' && !str_contains(mb_strtolower($label), $normalizedSearch)) {
                continue;
            }

            $subjects[] = [
                'id' => self::BUILT_IN_SUBJECT_ID,
                'type' => $type,
                'category' => self::SUBJECT_GROUP,
                'label' => $label,
                'is_builtin' => true,
                'is_read_only' => $readOnly,
            ];
        }

        if (!$this->database->schema()->hasTable(self::AUTH_GROUPS_TABLE)) {
            return $subjects;
        }

        $query = $this->database->table(self::AUTH_GROUPS_TABLE)
            ->where('is_enabled', '=', true)
            ->orderBy('group_name', 'ASC')
            ->limit($limit);
        if ($search !== '') {
            $query->where('group_name', 'LIKE', '%' . $search . '%');
        }

        foreach ($this->rows($query->get()) as $group) {
            $subjects[] = [
                'id' => $this->intValue($group['id'] ?? 0),
                'type' => self::SUBJECT_GROUP,
                'category' => self::SUBJECT_GROUP,
                'label' => $this->stringValue($group['group_name'] ?? ''),
                'is_builtin' => false,
                'is_read_only' => false,
            ];
        }

        return array_slice($subjects, 0, $limit);
    }

    /**
     * HR: Učitava aktivne korisnike prema ID-u i vraća ih indeksirane radi brzog spajanja s ACL-om.
     * EN: Loads active users by ID and indexes them for fast ACL merging.
     *
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function usersByIds(array $userIds, bool $includePickerSort = false): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));
        if ($userIds === [] || !$this->database->schema()->hasTable(self::AUTH_USERS_TABLE)) {
            return [];
        }

        return $this->decorateUsers($this->rows(
            $this->database->table(self::AUTH_USERS_TABLE)
                ->where('is_active', '=', true)
                ->whereIn('id', $userIds)
                ->get(),
        ), $includePickerSort);
    }

    /**
     * HR: Učitava aktivne grupe prema ID-u i indeksira ih radi brzog spajanja s ACL-om.
     * EN: Loads active groups by ID and indexes them for fast ACL merging.
     *
     * @param list<int> $groupIds
     * @return array<int, array<string, mixed>>
     */
    private function groupsByIds(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter($groupIds, static fn(int $id): bool => $id > 0)));
        if ($groupIds === [] || !$this->database->schema()->hasTable(self::AUTH_GROUPS_TABLE)) {
            return [];
        }

        $groups = [];
        foreach (
            $this->rows(
                $this->database->table(self::AUTH_GROUPS_TABLE)
                    ->where('is_enabled', '=', true)
                    ->whereIn('id', $groupIds)
                    ->get(),
            ) as $group
        ) {
            $groups[$this->intValue($group['id'] ?? 0)] = $group;
        }

        return $groups;
    }

    /**
     * HR: Grupno dodaje javne atribute imena korisnicima i indeksira rezultat po
     *     ID-u. Sortirna polja dodaje samo privremeno za korisnički picker.
     * EN: Adds public name attributes to users in one batch and indexes the
     *     result by ID. Sort fields are added only temporarily for user pickers.
     *
     * @param list<array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    private function decorateUsers(array $users, bool $includePickerSort = false): array
    {
        $ids = [];
        foreach ($users as $user) {
            $userId = $this->intValue($user['id'] ?? 0);
            if ($userId > 0) {
                $ids[] = $userId;
            }
        }

        $nameAttributes = [];
        if ($ids !== [] && $this->database->schema()->hasTable(self::AUTH_ATTRIBUTE_VALUES_TABLE)) {
            $attributes = $this->rows(
                $this->database->table(self::AUTH_ATTRIBUTE_VALUES_TABLE)
                    ->select(['user_id', 'field_key', 'value_text'])
                    ->whereIn('field_key', ['display_name', 'first_name', 'last_name'])
                    ->whereIn('user_id', array_values(array_unique($ids)))
                    ->get(),
            );
            foreach ($attributes as $attribute) {
                $userId = $this->intValue($attribute['user_id'] ?? 0);
                $fieldKey = $this->stringValue($attribute['field_key'] ?? '');
                $value = $this->stringValue($attribute['value_text'] ?? '');
                if ($userId > 0 && $fieldKey !== '' && $value !== '') {
                    $nameAttributes[$userId][$fieldKey] = $value;
                }
            }
        }

        $decorated = [];
        foreach ($users as $user) {
            $userId = $this->intValue($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            // HR: Imenik vraća samo javna picker polja jer Auth red može sadržavati tajne.
            // EN: The directory returns only public picker fields because an Auth row may contain secrets.
            $label = $this->stringValue($nameAttributes[$userId]['display_name'] ?? '')
            ?: $this->stringValue($user['login_identifier'] ?? __('Korisnik'));
            $decorated[$userId] = [
                'id' => $userId,
                'label' => $label,
                'type' => self::SUBJECT_USER,
                'category' => self::SUBJECT_USER,
                'is_builtin' => false,
                'is_read_only' => false,
            ];
            if ($includePickerSort) {
                $decorated[$userId]['_picker_last_name'] = $this->stringValue(
                    $nameAttributes[$userId]['last_name'] ?? $label,
                );
                $decorated[$userId]['_picker_first_name'] = $this->stringValue(
                    $nameAttributes[$userId]['first_name'] ?? $label,
                );
            }
        }

        return $decorated;
    }

    /**
     * HR: Vraća ID-eve grupa kojima korisnik trenutačno pripada.
     * EN: Returns IDs of groups to which the user currently belongs.
     *
     * @return list<int>
     */
    public function groupIdsForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->database->schema()->hasTable(self::AUTH_USER_GROUPS_TABLE)) {
            return [];
        }

        $rows = $this->database->table(self::AUTH_USER_GROUPS_TABLE)
            ->select(['group_id'])
            ->where('user_id', '=', $userId)
            ->get();
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['group_id'] ?? null)) {
                $ids[] = (int)$row['group_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * HR: Vraća minimalni skup aktivnih Auth korisnika potreban za grupni ACL
     *     izračun primatelja obavijesti.
     * EN: Returns the minimal active Auth user set required for batch ACL
     *     notification-recipient calculation.
     *
     * @return list<array<string, mixed>>
     */
    public function activeAclUsers(): array
    {
        if (!$this->database->schema()->hasTable(self::AUTH_USERS_TABLE)) {
            return [];
        }

        return $this->rows(
            $this->database->table(self::AUTH_USERS_TABLE)
                ->select(['id', 'is_admin'])
                ->where('is_active', '=', true)
                ->orderBy('id', 'ASC')
                ->get(),
        );
    }

    /**
     * HR: U jednom upitu grupira sva Auth članstva zadanih korisnika po
     *     korisničkom ID-u.
     * EN: Loads all Auth memberships for the supplied users in one query and
     *     groups them by user ID.
     *
     * @param list<int> $userIds
     * @return array<int, list<int>>
     */
    public function groupIdsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            static fn(int $userId): bool => $userId > 0,
        )));
        if ($userIds === [] || !$this->database->schema()->hasTable(self::AUTH_USER_GROUPS_TABLE)) {
            return [];
        }

        $groupsByUser = [];
        $rows = $this->database->table(self::AUTH_USER_GROUPS_TABLE)
            ->select(['user_id', 'group_id'])
            ->whereIn('user_id', $userIds)
            ->get();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!is_numeric($row['user_id'] ?? null)) {
                continue;
            }

            if (!is_numeric($row['group_id'] ?? null)) {
                continue;
            }

            $userId = (int)$row['user_id'];
            $groupId = (int)$row['group_id'];
            if ($userId > 0 && $groupId > 0) {
                $groupsByUser[$userId][$groupId] = $groupId;
            }
        }

        foreach ($groupsByUser as $userId => $groupIds) {
            $groupsByUser[$userId] = array_values($groupIds);
        }

        return $groupsByUser;
    }

    /**
     * HR: Gradi lanac predaka od korijena do odabranog čvora.
     * EN: Builds the ancestor chain from the root to the selected node.
     *
     * @return list<int>
     */
    public function ancestorNodeIds(int $workspaceId, int $nodeId): array
    {
        return array_values(array_filter(array_map(
            fn(array $node): int => $this->intValue($node['id'] ?? 0),
            $this->ancestorNodes($workspaceId, $nodeId),
        )));
    }

    /**
     * HR: Pretvara proizvoljne retke ORM-a u sigurnu listu polja.
     * EN: Converts arbitrary ORM rows into a safe list of arrays.
     *
     * @param mixed[] $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return WorkspaceValue::rows($rows);
    }

    /**
     * HR: Normalizira jedan proizvoljni ORM red ili vraća null.
     * EN: Normalizes one arbitrary ORM row or returns null.
     *
     * @return array<string, mixed>|null
     */
    private function row(mixed $row): ?array
    {
        $normalized = WorkspaceValue::stringKeyArray($row);

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * HR: Odbija operaciju kada inicijalna migracija nedostaje.
     * EN: Rejects an operation when the initial migration is missing.
     */
    private function assertTablesReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Workspace migracija još nije pokrenuta.'));
        }
    }

    /**
     * HR: Normalizira vidljivost na podržani skup vrijednosti.
     * EN: Normalizes visibility to the supported value set.
     */
    private function visibility(mixed $value): string
    {
        $visibility = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($visibility, ['restricted', 'authenticated', 'public'], true)
        ? $visibility
        : 'restricted';
    }

    /**
     * HR: Normalizira politiku prikaza na nasljeđivanje, prikaz ili skrivanje.
     * EN: Normalizes a display policy to inherit, show, or hide.
     */
    private function displayPolicy(mixed $value): string
    {
        $policy = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($policy, ['inherit', 'shown', 'hidden'], true)
        ? $policy
        : 'inherit';
    }

    /**
     * HR: Sprema zadani prikaz sadržaja stranice bez promjene ostalih podataka čvora.
     * EN: Saves a page's default outline visibility without changing other node data.
     */
    public function updateNodeContentsVisibility(
        int $nodeId,
        string $policy,
        int $actorUserId,
    ): void {
        $this->assertTablesReady();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('id', '=', $nodeId)
            ->where('is_enabled', '=', true)
            ->update([
                'contents_visibility' => $this->displayPolicy($policy),
                'updated_by_user_id' => $actorUserId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $node = $this->findNodeById($nodeId);
        $workspaceId = is_array($node) ? $this->intValue($node['workspace_id'] ?? 0) : 0;
        if ($workspaceId > 0) {
            $this->clearWorkspaceNodeCache($workspaceId);
        }
    }

    /**
     * HR: Poništava request cache stabla odmah nakon promjene njegovih čvorova.
     * EN: Invalidates the request tree cache immediately after node mutations.
     */
    private function clearWorkspaceNodeCache(int $workspaceId): void
    {
        if ($this->requestCache instanceof WorkspaceRepositoryRequestCache) {
            unset(
                $this->requestCache->nodesByWorkspace[$workspaceId],
                $this->requestCache->ancestorNodesByWorkspaceAndNode[$workspaceId],
            );
            foreach ($this->requestCache->nodesById as $nodeId => $node) {
                if ($this->intValue($node['workspace_id'] ?? 0) === $workspaceId) {
                    unset($this->requestCache->nodesById[$nodeId]);
                }
            }
        }
    }

    /**
     * HR: Pamti aktivni čvor po ID-u samo tijekom aktualnog zahtjeva.
     * EN: Remembers an active node by ID only for the current request.
     *
     * @param array<string, mixed>|null $node
     * @return array<string, mixed>|null
     */
    private function rememberNode(?array $node): ?array
    {
        if (!is_array($node) || !$this->requestCache instanceof WorkspaceRepositoryRequestCache) {
            return $node;
        }

        $nodeId = $this->intValue($node['id'] ?? 0);
        if ($nodeId > 0) {
            $this->requestCache->nodesById[$nodeId] = $node;
        }

        return $node;
    }

    /**
     * HR: Normalizira tip čvora na dokument ili podržani link.
     * EN: Normalizes node type to a document or supported link.
     */
    private function nodeType(mixed $value): string
    {
        $type = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($type, ['document', 'internal_link', 'external_link'], true)
        ? $type
        : 'document';
    }

    /**
     * HR: Gradi jedinstveni slug područja uz predvidljiv numerički nastavak.
     * EN: Builds a unique workspace slug with a predictable numeric suffix.
     */
    private function uniqueWorkspaceSlug(string $base, int $ignoreId): string
    {
        $candidate = $base;
        $counter = 2;
        while (true) {
            $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('slug', '=', $candidate)
                ->first();
            if (!is_array($row) || $this->intValue($row['id'] ?? 0) === $ignoreId) {
                return $candidate;
            }

            $candidate = $base . '-' . $counter;
            ++$counter;
        }
    }

    /**
     * HR: Gradi jedinstveni slug čvora unutar jednog područja.
     * EN: Builds a node slug unique within one workspace.
     */
    private function uniqueNodeSlug(int $workspaceId, string $base, int $ignoreId): string
    {
        $candidate = $base;
        $counter = 2;
        while (true) {
            $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->where('slug', '=', $candidate)
                ->first();
            if (!is_array($row) || $this->intValue($row['id'] ?? 0) === $ignoreId) {
                return $candidate;
            }

            $candidate = $base . '-' . $counter;
            ++$counter;
        }
    }

    /**
     * HR: Provjerava bi li novi roditelj stvorio ciklus stabla.
     * EN: Checks whether a new parent would create a tree cycle.
     */
    private function wouldCreateCycle(int $nodeId, int $parentId): bool
    {
        $currentId = $parentId;
        $visited = [];
        while ($currentId > 0 && !isset($visited[$currentId])) {
            if ($currentId === $nodeId) {
                return true;
            }

            $visited[$currentId] = true;
            $node = $this->findNodeById($currentId);
            if (!is_array($node)) {
                return false;
            }

            $currentId = $this->intValue($node['parent_id'] ?? 0);
        }

        return false;
    }

    /**
     * HR: Vraća ID-eve čvora i svih aktivnih potomaka.
     * EN: Returns IDs of a node and all active descendants.
     *
     * @return list<int>
     */
    private function descendantIds(int $workspaceId, int $nodeId): array
    {
        $nodes = $this->nodesForWorkspace($workspaceId);
        $children = [];
        foreach ($nodes as $node) {
            $parentId = $this->intValue($node['parent_id'] ?? 0);
            $children[$parentId][] = $this->intValue($node['id'] ?? 0);
        }

        $result = [];
        $pending = [$nodeId];
        while ($pending !== []) {
            $current = array_shift($pending);
            if (!is_int($current)) {
                continue;
            }

            if ($current <= 0) {
                continue;
            }

            if (in_array($current, $result, true)) {
                continue;
            }

            $result[] = $current;
            foreach ($children[$current] ?? [] as $childId) {
                $pending[] = $childId;
            }
        }

        return $result;
    }

    /**
     * HR: Provjerava postoji li auth subjekt odabran u ACL formi.
     * EN: Checks whether an auth subject selected in the ACL form exists.
     */
    private function subjectExists(string $type, int $id): bool
    {
        if (
            in_array($type, [self::SUBJECT_PUBLIC, self::SUBJECT_AUTHENTICATED], true)
            && $id === self::BUILT_IN_SUBJECT_ID
        ) {
            return true;
        }

        return $type === self::SUBJECT_USER
        ? $this->userExists($id)
        : ($type === self::SUBJECT_GROUP && $this->groupExists($id));
    }

    /**
     * HR: Provjerava postoji li aktivni korisnik.
     * EN: Checks whether an active user exists.
     */
    private function userExists(int $id): bool
    {
        if (!$this->database->schema()->hasTable(self::AUTH_USERS_TABLE)) {
            return false;
        }

        return is_array(
            $this->database->table(self::AUTH_USERS_TABLE)
                ->where('id', '=', $id)
                ->where('is_active', '=', true)
                ->first(),
        );
    }

    /**
     * HR: Provjerava postoji li aktivna grupa.
     * EN: Checks whether an active group exists.
     */
    private function groupExists(int $id): bool
    {
        if (!$this->database->schema()->hasTable(self::AUTH_GROUPS_TABLE)) {
            return false;
        }

        return is_array(
            $this->database->table(self::AUTH_GROUPS_TABLE)
                ->where('id', '=', $id)
                ->where('is_enabled', '=', true)
                ->first(),
        );
    }

    /**
     * HR: Pri kreiranju područja pretvara zadanu vidljivost u isti ugrađeni ACL
     *     model koji kasnije uređuje administrator.
     * EN: Converts default visibility into the same built-in ACL model edited
     *     by administrators when a Workspace is created.
     */
    private function insertBuiltInVisibilityAcl(int $workspaceId, string $visibility, string $now): void
    {
        if (!in_array($visibility, [self::SUBJECT_PUBLIC, self::SUBJECT_AUTHENTICATED], true)) {
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)->insert([
            'workspace_id' => $workspaceId,
            'subject_type' => $visibility,
            'subject_id' => self::BUILT_IN_SUBJECT_ID,
            'can_view' => true,
            'can_add' => false,
            'can_edit' => false,
            'can_publish' => false,
            'can_delete' => false,
            'can_manage' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * HR: Korisniku idempotentno dodjeljuje upravljanje i svih pet uključenih
     *     operativnih prava kroz isti ACL model koji uređuju postavke područja.
     * EN: Idempotently grants a user Manage and all five implied operational
     *     permissions through the same ACL model edited in Workspace settings.
     */
    public function grantWorkspaceManagement(int $workspaceId, int $userId): void
    {
        if (
            $workspaceId <= 0
            || $userId <= 0
            || !is_array($this->findWorkspaceById($workspaceId))
            || !$this->userExists($userId)
        ) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $permissions = $this->permissionValues(['can_manage' => true]);
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)->upsert(
            [[
                'workspace_id' => $workspaceId,
                'subject_type' => self::SUBJECT_USER,
                'subject_id' => $userId,
                ...$permissions,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['workspace_id', 'subject_type', 'subject_id'],
            [...array_keys($permissions), 'updated_at'],
        );
    }

    /**
     * HR: Normalizira polja ovlasti i osigurava da manage uključuje sva niža prava.
     * EN: Normalizes permission fields and ensures manage includes every lower permission.
     *
     * @param array<string, mixed> $permissions
     * @return array<string, bool>
     */
    private function permissionValues(array $permissions): array
    {
        $manage = $this->boolValue($permissions['can_manage'] ?? false);
        $publish = $manage || $this->boolValue($permissions['can_publish'] ?? false);
        $delete = $manage || $this->boolValue($permissions['can_delete'] ?? false);
        $edit = $delete || $this->boolValue($permissions['can_edit'] ?? false);
        $add = $manage || $this->boolValue($permissions['can_add'] ?? false);
        $view = $add || $edit || $publish || $this->boolValue($permissions['can_view'] ?? false);

        return [
            'can_view' => $view,
            'can_add' => $add,
            'can_edit' => $edit,
            'can_publish' => $publish,
            'can_delete' => $delete,
            'can_manage' => $manage,
        ];
    }

    /**
     * HR: Provjerava sadrži li normalizirani red barem jedno pravo.
     * EN: Checks whether a normalized row contains at least one permission.
     *
     * @param array<string, bool> $permissions
     */
    private function hasAnyPermission(array $permissions): bool
    {
        return in_array(true, $permissions, true);
    }

    /**
     * HR: Normalizira naslov i druge kratke tekstualne vrijednosti.
     * EN: Normalizes titles and other short text values.
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Dekodira jezične vrijednosti spremljene kao JSON i normalizira oznake jezika.
     * EN: Decodes language values stored as JSON and normalizes locale codes.
     *
     * @return array<string, string>
     */
    public function translationMap(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) !== '' ? json_decode($value, true) : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $translations = [];
        foreach ($value as $language => $translation) {
            if (!is_scalar($translation)) {
                continue;
            }

            $language = $this->languageCode($language);
            $translation = trim((string)$translation);
            if ($translation !== '') {
                $translations[$language] = $translation;
            }
        }

        return $translations;
    }

    /**
     * HR: Vraća prijevod za aktivni jezik, zatim njegov osnovni jezik i na kraju
     *     primarni jezik sitea. Ne bira proizvoljan prijevod.
     * EN: Returns the active-locale translation, then its base language, and
     *     finally the site's primary language. It never picks an arbitrary value.
     */
    public function localizedValue(mixed $translations, string $language, string $primaryLanguage): string
    {
        $translations = $this->translationMap($translations);
        $language = $this->languageCode($language);
        $primaryLanguage = $this->languageCode($primaryLanguage);
        $baseLanguage = (string)preg_replace('/[-_].*$/', '', $language);

        foreach (array_unique([$language, $baseLanguage, $primaryLanguage]) as $candidate) {
            if (($translations[$candidate] ?? '') !== '') {
                return $translations[$candidate];
            }
        }

        return '';
    }

    /**
     * HR: Lokalizira naziv i opis područja bez dodatnog SQL upita te zadržava
     *     dekodirane mape kako bi ih obrazac mogao uređivati.
     * EN: Localizes a Workspace name and description without another SQL query
     *     and retains decoded maps for editing forms.
     *
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    public function localizeWorkspace(array $workspace, string $language, string $primaryLanguage): array
    {
        $workspace['name_translations_map'] = $this->translationMap($workspace['name_translations'] ?? null);
        $workspace['description_translations_map'] = $this->translationMap(
            $workspace['description_translations'] ?? null,
        );

        // HR: Stari skalarni stupci ostaju siguran izvor za primarni jezik ako
        //     migracija ili prijenos još nisu zapisali JSON mapu.
        // EN: Legacy scalar columns remain a safe primary-locale source when a
        //     migration or transfer has not written the JSON map yet.
        $primaryLanguage = $this->languageCode($primaryLanguage);
        $legacyName = $this->stringValue($workspace['name'] ?? '');
        if (($workspace['name_translations_map'][$primaryLanguage] ?? '') === '' && $legacyName !== '') {
            $workspace['name_translations_map'][$primaryLanguage] = $legacyName;
        }

        $legacyDescription = $this->stringValue($workspace['description'] ?? '');
        if (
            ($workspace['description_translations_map'][$primaryLanguage] ?? '') === ''
            && $legacyDescription !== ''
        ) {
            $workspace['description_translations_map'][$primaryLanguage] = $legacyDescription;
        }

        $workspace['name'] = $this->localizedValue(
            $workspace['name_translations_map'],
            $language,
            $primaryLanguage,
        ) ?: $legacyName;
        $workspace['description'] = $this->localizedValue(
            $workspace['description_translations_map'],
            $language,
            $primaryLanguage,
        ) ?: $legacyDescription;

        return $workspace;
    }

    /**
     * HR: Lokalizira popis područja u memoriji. EN: Localizes a Workspace list in memory.
     *
     * @param list<array<string, mixed>> $workspaces
     * @return list<array<string, mixed>>
     */
    public function localizeWorkspaces(array $workspaces, string $language, string $primaryLanguage): array
    {
        foreach ($workspaces as &$workspace) {
            $workspace = $this->localizeWorkspace($workspace, $language, $primaryLanguage);
        }

        unset($workspace);

        return $workspaces;
    }

    /**
     * HR: Lokalizira naslov jednoga čvora i zadržava mapu za obrazac.
     * EN: Localizes a single node title and retains its map for the form.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function localizeNode(array $node, string $language, string $primaryLanguage): array
    {
        $node['title_translations_map'] = $this->translationMap($node['title_translations'] ?? null);
        $primaryLanguage = $this->languageCode($primaryLanguage);
        $legacyTitle = $this->stringValue($node['title'] ?? '');
        if (($node['title_translations_map'][$primaryLanguage] ?? '') === '' && $legacyTitle !== '') {
            $node['title_translations_map'][$primaryLanguage] = $legacyTitle;
        }

        $node['title'] = $this->localizedValue(
            $node['title_translations_map'],
            $language,
            $primaryLanguage,
        ) ?: $legacyTitle;

        return $node;
    }

    /**
     * HR: Lokalizira ravan popis čvorova. EN: Localizes a flat node list.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public function localizeNodes(array $nodes, string $language, string $primaryLanguage): array
    {
        foreach ($nodes as &$node) {
            $node = $this->localizeNode($node, $language, $primaryLanguage);
        }

        unset($node);

        return $nodes;
    }

    /**
     * HR: Rekurzivno lokalizira cijelo stablo bez novih upita prema bazi.
     * EN: Recursively localizes the whole tree without additional database queries.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public function localizeTree(array $nodes, string $language, string $primaryLanguage): array
    {
        foreach ($nodes as &$node) {
            $node = $this->localizeNode($node, $language, $primaryLanguage);
            $rawChildren = is_array($node['children'] ?? null) ? $node['children'] : [];
            $children = $this->treeNodeList($rawChildren);
            $node['children'] = $this->localizeTree($children, $language, $primaryLanguage);
        }

        unset($node);

        return $nodes;
    }

    /**
     * HR: Normalizira proizvoljan niz djece u popis čvorova sa znakovnim ključevima.
     * EN: Normalizes an arbitrary children array into a list of string-keyed nodes.
     *
     * @param array<mixed> $nodes
     * @return list<array<string,mixed>>
     */
    private function treeNodeList(array $nodes): array
    {
        $normalizedNodes = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $normalizedNode = [];
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $normalizedNode[$key] = $value;
                }
            }

            $normalizedNodes[] = $normalizedNode;
        }

        return $normalizedNodes;
    }

    /** HR: Normalizira proizvoljnu oznaku jezika. EN: Normalizes an arbitrary locale code. */
    private function languageCode(mixed $language): string
    {
        return $this->language(is_scalar($language) ? (string)$language : '');
    }

    /**
     * HR: Normalizira jedinstveni popis podržanih jezika.
     * EN: Normalizes a unique list of supported locales.
     *
     * @return list<string>
     */
    private function languageCodes(mixed $languages): array
    {
        $languages = is_array($languages) ? $languages : [$languages];
        $normalized = [];
        foreach ($languages as $language) {
            $code = $this->languageCode($language);
            if (!in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return $normalized !== [] ? $normalized : ['hr'];
    }

    /**
     * HR: Prihvaća samo podržane prijevode; sirovu vrijednost koristi za primarni
     *     jezik samo kada pozivatelj nije poslao mapu prijevoda.
     * EN: Accepts supported translations only; the raw value seeds the primary
     *     locale only when the caller did not submit a translation map.
     *
     * @param list<string> $supportedLanguages
     * @return array<string, string>
     */
    private function normalizedTranslations(
        mixed $translations,
        array $supportedLanguages,
        string $primaryLanguage,
        mixed $rawValue,
    ): array {
        $translationsWereProvided = is_array($translations)
        || (is_string($translations) && trim($translations) !== '');
        $translations = $this->translationMap($translations);
        if (!$translationsWereProvided) {
            $rawValue = $this->stringValue($rawValue);
            if ($rawValue !== '') {
                $translations[$primaryLanguage] = $rawValue;
            }
        }

        $normalized = [];
        foreach ($supportedLanguages as $language) {
            $language = $this->languageCode($language);
            if (($translations[$language] ?? '') !== '') {
                $normalized[$language] = $translations[$language];
            }
        }

        return $normalized;
    }

    /**
     * HR: Kodira prijevode u stabilan Unicode JSON zapis.
     * EN: Encodes translations as stable Unicode JSON.
     *
     * @param array<string, string> $translations
     */
    private function encodeTranslations(array $translations): string
    {
        $encoded = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException(__('Prijevode nije moguće spremiti.'));
        }

        return $encoded;
    }

    /**
     * HR: Čita cijeli broj iz forme ili baze.
     * EN: Reads an integer from form or database input.
     */
    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * HR: Vraća pozitivan ID ili null za korijenski čvor.
     * EN: Returns a positive ID or null for a root node.
     */
    private function nullablePositiveInt(mixed $value): ?int
    {
        $id = $this->intValue($value);

        return $id > 0 ? $id : null;
    }

    /**
     * HR: Razlikuje izričitu null vrijednost od polja koje prijelaz nije
     *     mijenjao, što omogućuje sigurno čišćenje starih workflow oznaka.
     * EN: Distinguishes an explicit null from a field omitted by a transition,
     *     allowing stale workflow markers to be cleared safely.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $existing
     */
    private function workflowValue(array $values, ?array $existing, string $key): mixed
    {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return is_array($existing) && array_key_exists($key, $existing)
        ? $existing[$key]
        : null;
    }

    /**
     * HR: Normalizira kratku oznaku jezika za jedinstveni workflow ključ.
     * EN: Normalizes a short locale code for the unique workflow key.
     */
    private function language(string $language): string
    {
        $language = strtolower(trim($language));
        $language = (string)preg_replace('/[^a-z0-9_-]+/', '', $language);

        return $language !== '' ? substr($language, 0, 16) : 'hr';
    }

    /**
     * HR: Pretvara skalarne checkbox vrijednosti u boolean.
     * EN: Converts scalar checkbox values into a boolean.
     */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * HR: Gradi siguran ASCII slug.
     * EN: Builds a safe ASCII slug.
     */
    private function slug(mixed $value, string $fallback): string
    {
        $value = $this->stringValue($value);
        $value = strtr($value, [
            'č' => 'c',
            'ć' => 'c',
            'đ' => 'd',
            'š' => 's',
            'ž' => 'z',
            'Č' => 'c',
            'Ć' => 'c',
            'Đ' => 'd',
            'Š' => 's',
            'Ž' => 'z',
        ]);
        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $slug !== '' ? $slug : $fallback;
    }

    /** HR: Normalizira poslovnu oznaku stranice u stabilan prenosivi ključ. EN: Normalizes a page label into a stable portable key. */
    private function nodeLabel(mixed $value): string
    {
        $label = strtolower($this->stringValue($value));
        $label = preg_replace('/[^a-z0-9._-]+/u', '-', $label) ?? '';

        return trim($label, '-.');
    }

    /** HR: Normalizira ključ svojstva stranice. EN: Normalizes a page-property key. */
    private function nodePropertyKey(mixed $value): string
    {
        $key = mb_strtolower($this->stringValue($value));
        $key = preg_replace('/[^\pL\pN._-]+/u', '-', $key) ?? '';

        return mb_substr(trim($key, '-.'), 0, 128);
    }

    /** HR: Ograničava svojstvo na podržanu semantičku vrstu. EN: Limits a property to a supported semantic type. */
    private function nodePropertyType(mixed $value): string
    {
        $type = strtolower($this->stringValue($value));

        return in_array($type, ['text', 'status', 'number', 'date', 'user', 'link'], true)
        ? $type
        : 'text';
    }

    /**
     * HR: Provjerava apsolutni HTTP(S) URL vanjskog linka.
     * EN: Validates an absolute HTTP(S) URL for an external link.
     */
    private function validExternalUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && trim((string)($parts['host'] ?? '')) !== '';
    }

    /**
     * HR: Dopušta samo lokalnu apsolutnu putanju bez protokola, drugog hosta ili obrnutih kosa crta.
     * EN: Allows only a local absolute path without a scheme, another host, or backslashes.
     */
    private function validInternalPath(string $path): bool
    {
        return str_starts_with($path, '/')
        && !str_starts_with($path, '//')
        && !str_contains($path, '\\')
        && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    /**
     * HR: Sigurno šalje obavijest izvedenim modulima. Neuspjeh opcionalnog
     *     indeksa ne smije poništiti uspješno spremanje izvornog sadržaja.
     * EN: Safely notifies derived modules. Failure of an optional index must
     *     not undo a successful write of the source content.
     */
    private function contentChanged(
        int $workspaceId,
        string $reason,
        ?int $nodeId = null,
        ?string $language = null,
        ?int $actorUserId = null,
    ): void {
        if ($workspaceId <= 0 || !$this->events instanceof EventDispatcherInterface) {
            return;
        }

        $event = new WorkspaceContentChanged(
            $workspaceId,
            $reason,
            $nodeId,
            $language,
            $actorUserId,
        );
        if ($this->contentChangeBatch instanceof WorkspaceContentChangeBatch) {
            $this->contentChangeBatch->publish($event);

            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (\Throwable $throwable) {
            /*
             * HR: Ručni reindeks i periodična provjera popravljaju eventualni
             *     kvar izvedenog indeksa bez gubitka izvornog sadržaja.
             * EN: Manual reindexing and periodic refresh repair a derived-index
             *     failure without losing source content.
             */
            $this->logger?->error('Workspace content-change listeners failed for workspace {workspace_id}.', [
                'module' => 'workspace',
                'workspace_id' => $workspaceId,
                'node_id' => $nodeId,
                'reason' => $reason,
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Generira prenosivi UUID v4 bez dodatne biblioteke.
     * EN: Generates a portable UUID v4 without an additional library.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
