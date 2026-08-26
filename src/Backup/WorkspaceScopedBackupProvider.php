<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupCommitAwareProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupScopeOptionsProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupPreflightResult;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;

/**
 * HR: Prenosivi backup jednog područja, njegova stabla, workflowa, ACL-a i
 *     privatne teme. Brojčane veze nikada ne napuštaju izvornu instalaciju.
 *
 * EN: Portable backup of one workspace, its tree, workflow, ACL, and private
 *     theme. Numeric relations never leave the source installation.
 */
// phpcs:ignore Generic.Files.LineLength.TooLong -- PSR-2 multi-line and project indentation sniffs conflict here.
final class WorkspaceScopedBackupProvider implements BackupProviderInterface, BackupCommitAwareProviderInterface, BackupScopeOptionsProviderInterface
{
    private const ID = 'workspace-scope';

    private ?string $stagedTheme = null;

    private ?string $publishedTheme = null;

    private ?string $themeRollback = null;

    private bool $publishedThemeHadOriginal = false;

    /** HR: Prima bazu, identitete i direktorij tema područja. EN: Receives the database, identities, and Workspace theme directory. */
    public function __construct(
        private readonly Database $database,
        private readonly AuthBackupIdentityResolver $identities,
        private readonly WorkspaceConfig $config,
        private readonly BackupFilesystem $filesystem,
    ) {
    }

    /** HR: Opisuje jezgreni Workspace backup skup. EN: Describes the core Workspace backup dataset. */
    public function metadata(): BackupProviderMetadata
    {
        return new BackupProviderMetadata(
            self::ID,
            ModuleWorkspace::PACKAGE_NAME,
            1,
            ['hr' => 'Područje, prava i privatna tema', 'en' => 'Workspace, permissions and private theme'],
            ['editor-html-workspace'],
            [BackupScope::WORKSPACE],
            true,
            true,
            [ModuleWorkspace::PACKAGE_NAME],
        );
    }

    /** HR: Ponuđene stavke pripadaju opsegu područja. EN: Offered options belong to Workspace scope. */
    public function scopeType(): string
    {
        return BackupScope::WORKSPACE;
    }

    /**
     * HR: Vraća sva neobrisana područja za administratorski Backup GUI.
     * EN: Returns all non-deleted workspaces for the administrator Backup GUI.
     *
     * @return list<array{identifier:string,label:string}>
     */
    public function scopeOptions(): array
    {
        if (!$this->database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACES)) {
            return [];
        }

        $rows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('is_deleted', '=', false)
            ->orderBy('name', 'ASC')
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $slug = BackupValue::string($row['slug'], 'workspace.slug');
            $name = BackupValue::string($row['name'], 'workspace.name');
            $options[] = [
                'identifier' => $slug,
                'label' => $name . ' — ' . $slug,
            ];
        }

        return $options;
    }

    /** HR: Izvozi područje, stablo, ACL, tijekove i privatnu temu. EN: Exports the Workspace, tree, ACL, workflows, and private theme. */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        $workspace = $this->workspace($context->scope->identifier);
        if ($workspace === null) {
            throw new BackupException('Workspace does not exist.');
        }

        $workspaceId = BackupValue::integer($workspace['id'], 'workspace.id');
        $writer->writeRecord(self::ID, 'workspace', $this->portableWorkspace($workspace));

        $nodes = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)
            ->orderBy('sort_order')
            ->get();
        /** @var array<int,string> $nodeUuidById */
        $nodeUuidById = [];
        foreach ($nodes as $node) {
            $nodeUuidById[BackupValue::integer($node['id'], 'node.id')] = BackupValue::string(
                $node['uuid'],
                'node.uuid',
            );
        }

        foreach ($nodes as $node) {
            $writer->writeRecord(self::ID, 'nodes', [
                'source_id' => BackupValue::integer($node['id'], 'node.id'),
                'uuid' => BackupValue::string($node['uuid'], 'node.uuid'),
                'parent_uuid' => is_numeric($node['parent_id'] ?? null)
                    ? ($nodeUuidById[BackupValue::integer($node['parent_id'], 'node.parent_id')] ?? null)
                    : null,
                'node_type' => BackupValue::string($node['node_type'], 'node.node_type'),
                'slug' => BackupValue::string($node['slug'], 'node.slug'),
                'title' => BackupValue::string($node['title'], 'node.title'),
                'document_key' => $node['document_key'] ?? null,
                'route_name' => $node['route_name'] ?? null,
                'target_url' => $node['target_url'] ?? null,
                'sort_order' => BackupValue::integer($node['sort_order'], 'node.sort_order'),
                'is_homepage' => BackupValue::booleanInteger($node['is_homepage'], 'node.is_homepage'),
                'is_enabled' => BackupValue::booleanInteger($node['is_enabled'], 'node.is_enabled'),
                'contents_visibility' => BackupValue::string(
                    $node['contents_visibility'],
                    'node.contents_visibility',
                ),
                'created_by_user' => $this->identities->userKeyForId($node['created_by_user_id'] ?? null),
                'updated_by_user' => $this->identities->userKeyForId($node['updated_by_user_id'] ?? null),
                'created_at' => $node['created_at'] ?? null,
                'updated_at' => $node['updated_at'] ?? null,
            ]);
        }

        $this->exportAcl(
            ModuleWorkspace::TABLE_WORKSPACE_ACL,
            'workspace_id',
            $workspaceId,
            null,
            $writer,
            'workspace-acl',
        );
        foreach ($nodeUuidById as $nodeId => $nodeUuid) {
            foreach (
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                ->where('node_id', '=', $nodeId)
                ->orderBy('label')
                ->get() as $label
            ) {
                $writer->writeRecord(self::ID, 'node-labels', [
                    'node_uuid' => $nodeUuid,
                    'label' => BackupValue::string($label['label'], 'node-label.label'),
                ]);
            }

            foreach (
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                ->where('node_id', '=', $nodeId)
                ->orderBy('sort_order')
                ->orderBy('property_label')
                ->get() as $property
            ) {
                $writer->writeRecord(self::ID, 'node-properties', [
                    'node_uuid' => $nodeUuid,
                    'key' => BackupValue::string($property['property_key'], 'node-property.key'),
                    'label' => BackupValue::string($property['property_label'], 'node-property.label'),
                    'type' => BackupValue::string($property['property_type'], 'node-property.type'),
                    'value' => (string)($property['property_value'] ?? ''),
                    'sort_order' => BackupValue::integer($property['sort_order'], 'node-property.sort_order'),
                ]);
            }

            $this->exportAcl(
                ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL,
                'node_id',
                $nodeId,
                $nodeUuid,
                $writer,
                'node-acl',
                true,
            );
            foreach (
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                ->where('node_id', '=', $nodeId)
                ->orderBy('user_id')
                ->get() as $directPermission
            ) {
                $userKey = $this->identities->userKeyForId($directPermission['user_id'] ?? null);
                if ($userKey === null) {
                    throw new BackupException('Unable to serialize direct page-permission user.');
                }

                $writer->writeRecord(self::ID, 'node-direct-permissions', [
                    'node_uuid' => $nodeUuid,
                    'user_key' => $userKey,
                    'can_view' => BackupValue::booleanInteger(
                        $directPermission['can_view'],
                        'node-direct-permission.can_view',
                    ),
                    'can_edit' => BackupValue::booleanInteger(
                        $directPermission['can_edit'],
                        'node-direct-permission.can_edit',
                    ),
                    'can_publish' => BackupValue::booleanInteger(
                        $directPermission['can_publish'],
                        'node-direct-permission.can_publish',
                    ),
                    'created_at' => $directPermission['created_at'] ?? null,
                    'updated_at' => $directPermission['updated_at'] ?? null,
                ]);
            }

            $workflows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                ->where('node_id', '=', $nodeId)
                ->orderBy('language_code')
                ->get();
            foreach ($workflows as $workflow) {
                $writer->writeRecord(self::ID, 'workflows', [
                    'node_uuid' => $nodeUuid,
                    'language_code' => BackupValue::string($workflow['language_code'], 'workflow.language_code'),
                    'status' => BackupValue::string($workflow['status'], 'workflow.status'),
                    'current_version_number' => $workflow['current_version_number'] ?? null,
                    'published_version_number' => $workflow['published_version_number'] ?? null,
                    'submitted_by_user' => $this->identities->userKeyForId($workflow['submitted_by_user_id'] ?? null),
                    'submitted_at' => $workflow['submitted_at'] ?? null,
                    'published_by_user' => $this->identities->userKeyForId($workflow['published_by_user_id'] ?? null),
                    'published_at' => $workflow['published_at'] ?? null,
                    'archived_by_user' => $this->identities->userKeyForId($workflow['archived_by_user_id'] ?? null),
                    'archived_at' => $workflow['archived_at'] ?? null,
                    'updated_by_user' => $this->identities->userKeyForId($workflow['updated_by_user_id'] ?? null),
                    'created_at' => $workflow['created_at'] ?? null,
                    'updated_at' => $workflow['updated_at'] ?? null,
                ]);
            }
        }

        $theme = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', $workspaceId)
            ->first();
        if (is_array($theme)) {
            unset($theme['id'], $theme['workspace_id']);
            $theme['updated_by_user'] = $this->identities->userKeyForId($theme['updated_by_user_id'] ?? null);
            unset($theme['updated_by_user_id']);
            $writer->writeRecord(self::ID, 'theme', $theme);
        }

        $themeRoot = $this->config->workspaceThemePath($workspaceId);
        foreach ($this->filesystem->files($themeRoot) as $relative) {
            $blob = $writer->addBlobFile($themeRoot . '/' . $relative, null, basename($relative));
            $writer->writeRecord(self::ID, 'theme-files', ['path' => $relative, 'blob' => $blob]);
        }
    }

    /** HR: Provjerava identitet područja i cjelovitost skupa. EN: Validates Workspace identity and dataset integrity. */
    public function preflight(BackupImportContext $context, BackupArchiveReader $reader): BackupPreflightResult
    {
        $workspace = $this->singleRecord($reader, 'workspace');
        if ($workspace === null) {
            return new BackupPreflightResult(['Workspace backup does not contain a workspace record.']);
        }

        $errors = [];
        foreach (['workspace-acl', 'node-acl'] as $dataset) {
            foreach ($reader->records(self::ID, $dataset) as $acl) {
                $subjectType = BackupValue::string($acl['subject_type'], $dataset . '.subject_type');
                if ($dataset === 'node-acl' && $subjectType !== WorkspaceRepository::SUBJECT_USER) {
                    continue;
                }

                $subjectKey = BackupValue::string($acl['subject_key'], $dataset . '.subject_key');
                if ($this->identities->subjectId($subjectType, $subjectKey) === null) {
                    $errors[] = sprintf(
                        'Workspace ACL subject is unavailable: %s:%s.',
                        $subjectType,
                        $subjectKey,
                    );
                }
            }
        }

        foreach ($reader->records(self::ID, 'node-direct-permissions') as $permission) {
            $userKey = BackupValue::string(
                $permission['user_key'],
                'node-direct-permission.user_key',
            );
            if ($this->identities->userIdForKey($userKey) === null) {
                $errors[] = sprintf('Direct page-permission user is unavailable: %s.', $userKey);
            }
        }

        $target = $this->targetIdentifier($context, $workspace);
        $existing = $this->workspace($target, false);
        if ($context->conflictMode === BackupImportContext::CONFLICT_COPY && is_array($existing)) {
            $errors[] = 'Copy target workspace already exists: ' . $target;
        }

        if ($context->conflictMode !== BackupImportContext::CONFLICT_COPY && !is_array($existing)) {
            $errors[] = 'Merge or replace target workspace does not exist: ' . $target;
        }

        return new BackupPreflightResult(
            array_values(array_unique($errors)),
            $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? ['A new workspace, node UUIDs, and portable document references will be created.']
                : [],
            ['workspace' => BackupValue::string($workspace['slug'], 'workspace.slug')],
        );
    }

    /** HR: Priprema cilj i čisti replace zapise. EN: Prepares the target and clears replace records. */
    public function prepareImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        if ($context->conflictMode !== BackupImportContext::CONFLICT_REPLACE) {
            return;
        }

        $record = $this->singleRecord($reader, 'workspace');
        $workspace = $record !== null ? $this->workspace($this->targetIdentifier($context, $record), false) : null;
        if (!is_array($workspace)) {
            return;
        }

        $workspaceId = BackupValue::integer($workspace['id'], 'workspace.id');
        $nodeRows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->select(['id'])->where('workspace_id', '=', $workspaceId)->get();
        $nodeIds = array_map(
            static fn(array $row): int => BackupValue::integer($row['id'], 'node.id'),
            $nodeRows,
        );
        if ($nodeIds !== []) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                ->whereIn('node_id', $nodeIds)
                ->delete();
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                ->whereIn('node_id', $nodeIds)
                ->delete();
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                ->whereIn('node_id', $nodeIds)
                ->delete();
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
                ->whereIn('node_id', $nodeIds)
                ->delete();
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                ->whereIn('node_id', $nodeIds)
                ->delete();
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)
            ->delete();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', $workspaceId)
            ->delete();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', $workspaceId)
            ->delete();
    }

    /** HR: Uvozi područje i sve njegove jezgrene odnose. EN: Imports the Workspace and all core relationships. */
    public function import(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $record = $this->singleRecord($reader, 'workspace');
        if ($record === null) {
            throw new BackupException('Workspace record is missing.');
        }

        $targetSlug = $this->targetIdentifier($context, $record);
        $existing = $this->workspace($targetSlug, false);
        $values = [
            'uuid' => $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : BackupValue::string($record['uuid'], 'workspace.uuid'),
            'slug' => $targetSlug,
            'name' => $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? BackupValue::string(
                    $context->optionsFor(self::ID)['name']
                        ?? (BackupValue::string($record['name'], 'workspace.name') . ' (copy)'),
                    'workspace.name',
                )
                : BackupValue::string($record['name'], 'workspace.name'),
            'description' => $record['description'] ?? null,
            'visibility' => BackupValue::string($record['visibility'], 'workspace.visibility'),
            'tree_visibility' => BackupValue::string($record['tree_visibility'], 'workspace.tree_visibility'),
            'contents_visibility' => BackupValue::string(
                $record['contents_visibility'],
                'workspace.contents_visibility',
            ),
            'is_archived' => BackupValue::booleanInteger($record['is_archived'], 'workspace.is_archived'),
            'is_deleted' => BackupValue::booleanInteger($record['is_deleted'], 'workspace.is_deleted'),
            'created_by_user_id' => $this->user($record['created_by_user'] ?? null, $context),
            'updated_by_user_id' => $this->user($record['updated_by_user'] ?? null, $context),
            'deleted_by_user_id' => $this->user($record['deleted_by_user'] ?? null, $context),
            'deleted_at' => $record['deleted_at'] ?? null,
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? date('Y-m-d H:i:s'),
        ];
        if (is_array($existing)) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('id', '=', BackupValue::integer($existing['id'], 'workspace.id'))
                ->update($values);
            $workspaceId = BackupValue::integer($existing['id'], 'workspace.id');
        } else {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert($values);
            $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->select(['id'])
                ->where('slug', '=', $targetSlug)
                ->first();
            if (!is_array($row)) {
                throw new BackupException('Imported workspace cannot be resolved: ' . $targetSlug);
            }

            $workspaceId = BackupValue::integer($row['id'], 'workspace.id');
        }

        $context->state->map(
            'workspace.id',
            BackupValue::integer($record['source_id'], 'workspace.source_id'),
            $workspaceId,
        );
        $context->state->map('workspace.id-by-slug', $targetSlug, $workspaceId);
        $context->state->map(
            'workspace.slug',
            BackupValue::string($record['slug'], 'workspace.slug'),
            $targetSlug,
        );

        $this->importWorkspaceAcl($workspaceId, $reader);
        $this->importNodes($workspaceId, $context, $reader);
        $this->importNodeLabels($context, $reader);
        $this->importNodeProperties($context, $reader);
        $this->importNodeAcl($context, $reader);
        $this->importNodeDirectPermissions($context, $reader);
        $this->importWorkflows($context, $reader);
        $this->importTheme($workspaceId, $context, $reader);
    }

    /** HR: Objavljuje pripremljenu privatnu temu područja. EN: Publishes the staged private Workspace theme. */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        if ($this->stagedTheme === null || $this->publishedTheme === null) {
            return;
        }

        $rollback = dirname($this->publishedTheme) . '/.backup-rollback-workspace-theme-' . bin2hex(random_bytes(8));
        $hadPublishedTheme = is_dir($this->publishedTheme);

        // HR: Postojeća tema prvo se atomski sklanja. Ako objava staginga ne
        // uspije, odmah se vraća prethodna verzija umjesto da područje ostane
        // bez svojih datoteka.
        //
        // EN: The existing theme is moved aside atomically first. If staging
        // publication fails, the previous version is restored immediately so
        // the workspace is never left without its files.
        if ($hadPublishedTheme && !rename($this->publishedTheme, $rollback)) {
            throw new BackupException('Unable to preserve the current workspace theme before restore.');
        }

        if (!rename($this->stagedTheme, $this->publishedTheme)) {
            if ($hadPublishedTheme && is_dir($rollback)) {
                @rename($rollback, $this->publishedTheme);
            }

            throw new BackupException('Unable to publish imported workspace theme files.');
        }

        $this->themeRollback = $rollback;
        $this->publishedThemeHadOriginal = $hadPublishedTheme;
        $this->stagedTheme = null;
    }

    /** HR: Uklanja pripremljene datoteke teme nakon pogreške. EN: Removes staged theme files after failure. */
    public function abortImport(BackupImportContext $context): void
    {
        if ($this->publishedTheme !== null && $this->themeRollback !== null) {
            if (is_dir($this->publishedTheme)) {
                $this->filesystem->removeDirectory($this->publishedTheme);
            }

            if ($this->publishedThemeHadOriginal && is_dir($this->themeRollback)) {
                @rename($this->themeRollback, $this->publishedTheme);
            }
        }

        if ($this->stagedTheme !== null) {
            $this->filesystem->removeDirectory($this->stagedTheme);
        }

        $this->stagedTheme = null;
        $this->publishedTheme = null;
        $this->themeRollback = null;
        $this->publishedThemeHadOriginal = false;
    }

    /** HR: Nakon DB commita uklanja rollback privatne teme. EN: Removes the private-theme rollback after the DB commit. */
    public function completeImport(BackupImportContext $context): void
    {
        if ($this->themeRollback !== null && is_dir($this->themeRollback)) {
            $this->filesystem->removeDirectory($this->themeRollback);
        }

        $this->stagedTheme = null;
        $this->publishedTheme = null;
        $this->themeRollback = null;
        $this->publishedThemeHadOriginal = false;
    }

    /**
     * HR: Svodi zapis područja na prenosiva polja.
     * EN: Reduces a Workspace record to portable fields.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function portableWorkspace(array $row): array
    {
        return [
            'source_id' => BackupValue::integer($row['id'], 'workspace.id'),
            'uuid' => BackupValue::string($row['uuid'], 'workspace.uuid'),
            'slug' => BackupValue::string($row['slug'], 'workspace.slug'),
            'name' => BackupValue::string($row['name'], 'workspace.name'),
            'description' => $row['description'] ?? null,
            'visibility' => BackupValue::string($row['visibility'], 'workspace.visibility'),
            'tree_visibility' => BackupValue::string($row['tree_visibility'], 'workspace.tree_visibility'),
            'contents_visibility' => BackupValue::string(
                $row['contents_visibility'],
                'workspace.contents_visibility',
            ),
            'is_archived' => BackupValue::booleanInteger($row['is_archived'], 'workspace.is_archived'),
            'is_deleted' => BackupValue::booleanInteger($row['is_deleted'], 'workspace.is_deleted'),
            'created_by_user' => $this->identities->userKeyForId($row['created_by_user_id'] ?? null),
            'updated_by_user' => $this->identities->userKeyForId($row['updated_by_user_id'] ?? null),
            'deleted_by_user' => $this->identities->userKeyForId($row['deleted_by_user_id'] ?? null),
            'deleted_at' => $row['deleted_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** HR: Izvozi prenosivi Workspace ili node ACL. EN: Exports portable Workspace or node ACL. */
    private function exportAcl(
        string $table,
        string $foreignKey,
        int $foreignId,
        ?string $nodeUuid,
        BackupArchiveWriter $writer,
        string $dataset,
        bool $userOnly = false,
    ): void {
        $query = $this->database->table($table)->where($foreignKey, '=', $foreignId);
        if ($userOnly) {
            $query->where('subject_type', '=', WorkspaceRepository::SUBJECT_USER);
        }

        foreach ($query->orderBy('id')->get() as $row) {
            $subject = $this->identities->subjectReference($row['subject_type'] ?? null, $row['subject_id'] ?? null);
            if ($subject === null) {
                throw new BackupException('Unable to serialize workspace ACL subject.');
            }

            $writer->writeRecord(self::ID, $dataset, [
                'node_uuid' => $nodeUuid, 'subject_type' => $subject['type'], 'subject_key' => $subject['key'],
                'can_view' => BackupValue::booleanInteger($row['can_view'], 'acl.can_view'),
                'can_add' => BackupValue::booleanInteger($row['can_add'], 'acl.can_add'),
                'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'acl.can_edit'),
                'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'acl.can_publish'),
                'can_delete' => BackupValue::booleanInteger($row['can_delete'], 'acl.can_delete'),
                'can_manage' => BackupValue::booleanInteger($row['can_manage'], 'acl.can_manage'),
                'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    /** HR: Uvozi ACL područja. EN: Imports Workspace ACL. */
    private function importWorkspaceAcl(int $workspaceId, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'workspace-acl') as $row) {
            $this->upsertAcl(ModuleWorkspace::TABLE_WORKSPACE_ACL, 'workspace_id', $workspaceId, $row);
        }
    }

    /** HR: Uvozi stablo redoslijedom roditelj prije djeteta. EN: Imports the tree in parent-before-child order. */
    private function importNodes(int $workspaceId, BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $pending = iterator_to_array($reader->records(self::ID, 'nodes'), false);
        while ($pending !== []) {
            $remaining = [];
            $progress = false;
            foreach ($pending as $row) {
                $parentUuid = trim(BackupValue::string($row['parent_uuid'] ?? '', 'node.parent_uuid'));
                if ($parentUuid !== '' && $context->state->resolve('workspace.node-id', $parentUuid) === $parentUuid) {
                    $remaining[] = $row;
                    continue;
                }

                $sourceUuid = BackupValue::string($row['uuid'], 'node.uuid');
                $targetUuid = $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : $sourceUuid;
                $sourceDocumentKey = BackupValue::nullableString($row['document_key'] ?? null, 'node.document_key');
                $documentKey = is_string($sourceDocumentKey) && trim($sourceDocumentKey) !== ''
                ? BackupValue::string(
                    $context->state->require('editor.document-key', $sourceDocumentKey),
                    'state.editor.document-key',
                )
                : null;
                $values = [
                    'uuid' => $targetUuid, 'workspace_id' => $workspaceId,
                    'parent_id' => $parentUuid !== ''
                        ? $this->mappedInteger($context, 'workspace.node-id', $parentUuid)
                        : null,
                    'node_type' => BackupValue::string($row['node_type'], 'node.node_type'),
                    'slug' => BackupValue::string($row['slug'], 'node.slug'),
                    'title' => BackupValue::string($row['title'], 'node.title'),
                    'document_key' => $documentKey,
                    'route_name' => $row['route_name'] ?? null,
                    'target_url' => $row['target_url'] ?? null,
                    'sort_order' => BackupValue::integer($row['sort_order'], 'node.sort_order'),
                    'is_homepage' => BackupValue::booleanInteger($row['is_homepage'], 'node.is_homepage'),
                    'is_enabled' => BackupValue::booleanInteger($row['is_enabled'], 'node.is_enabled'),
                    'contents_visibility' => BackupValue::string(
                        $row['contents_visibility'],
                        'node.contents_visibility',
                    ),
                    'created_by_user_id' => $this->user($row['created_by_user'] ?? null, $context),
                    'updated_by_user_id' => $this->user($row['updated_by_user'] ?? null, $context),
                    'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                ];
                $nodeSlug = BackupValue::string($row['slug'], 'node.slug');
                $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('workspace_id', '=', $workspaceId)->where('slug', '=', $nodeSlug)->first();
                if (is_array($existing)) {
                    $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                        ->where('id', '=', BackupValue::integer($existing['id'], 'node.id'))
                        ->update($values);
                    $nodeId = BackupValue::integer($existing['id'], 'node.id');
                } else {
                    $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert($values);
                    $created = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->select(['id'])
                        ->where('workspace_id', '=', $workspaceId)->where('slug', '=', $nodeSlug)->first();
                    $nodeId = is_array($created) ? BackupValue::integer($created['id'], 'node.id') : 0;
                }

                $context->state->map('workspace.node-id', $sourceUuid, $nodeId);
                $context->state->map('workspace.node-uuid', $sourceUuid, $targetUuid);
                $progress = true;
            }

            if (!$progress) {
                throw new BackupException('Workspace tree contains an unresolved parent or a cycle.');
            }

            $pending = $remaining;
        }
    }

    /** HR: Uvozi izravna ograničenja čvorova. EN: Imports direct node restrictions. */
    private function importNodeAcl(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'node-acl') as $row) {
            if (
                BackupValue::string($row['subject_type'], 'node-acl.subject_type')
                    !== WorkspaceRepository::SUBJECT_USER
            ) {
                continue;
            }

            $nodeId = $this->mappedInteger(
                $context,
                'workspace.node-id',
                BackupValue::string($row['node_uuid'], 'node-acl.node_uuid'),
            );
            $this->upsertAcl(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL, 'node_id', $nodeId, $row);
        }
    }

    /** HR: Uvozi izravna korisnička prava stranica. EN: Imports direct page user grants. */
    private function importNodeDirectPermissions(
        BackupImportContext $context,
        BackupArchiveReader $reader,
    ): void {
        foreach ($reader->records(self::ID, 'node-direct-permissions') as $row) {
            $nodeId = $this->mappedInteger(
                $context,
                'workspace.node-id',
                BackupValue::string($row['node_uuid'], 'node-direct-permission.node_uuid'),
            );
            $userKey = BackupValue::string($row['user_key'], 'node-direct-permission.user_key');
            $userId = $this->identities->userIdForKey($userKey);
            if ($userId === null) {
                throw new BackupException('Unable to resolve imported direct page-permission user.');
            }

            $values = [
                'node_id' => $nodeId,
                'user_id' => $userId,
                'can_view' => BackupValue::booleanInteger(
                    $row['can_view'],
                    'node-direct-permission.can_view',
                ),
                'can_edit' => BackupValue::booleanInteger(
                    $row['can_edit'],
                    'node-direct-permission.can_edit',
                ),
                'can_publish' => BackupValue::booleanInteger(
                    $row['can_publish'],
                    'node-direct-permission.can_publish',
                ),
                'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
            $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                ->where('node_id', '=', $nodeId)
                ->where('user_id', '=', $userId)
                ->first();
            if (is_array($existing)) {
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->where('id', '=', BackupValue::integer($existing['id'], 'node-direct-permission.id'))
                    ->update($values);
            } else {
                $values['created_at'] = $row['created_at'] ?? date('Y-m-d H:i:s');
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
                    ->insert($values);
            }
        }
    }

    /** HR: Uvozi oznake stranica prema stabilnom UUID-u. EN: Imports page labels by stable UUID. */
    private function importNodeLabels(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'node-labels') as $row) {
            $nodeId = $this->mappedInteger(
                $context,
                'workspace.node-id',
                BackupValue::string($row['node_uuid'], 'node-label.node_uuid'),
            );
            $label = BackupValue::string($row['label'], 'node-label.label');
            $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)
                ->where('node_id', '=', $nodeId)
                ->where('label', '=', $label)
                ->first();
            if (!is_array($existing)) {
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
                    'node_id' => $nodeId,
                    'label' => $label,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /** HR: Uvozi strukturirana svojstva stranica prema stabilnom UUID-u. EN: Imports structured page properties by stable UUID. */
    private function importNodeProperties(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'node-properties') as $row) {
            $nodeId = $this->mappedInteger(
                $context,
                'workspace.node-id',
                BackupValue::string($row['node_uuid'], 'node-property.node_uuid'),
            );
            $key = BackupValue::string($row['key'], 'node-property.key');
            $values = [
                'node_id' => $nodeId,
                'property_key' => $key,
                'property_label' => BackupValue::string($row['label'], 'node-property.label'),
                'property_type' => BackupValue::string($row['type'], 'node-property.type'),
                'property_value' => BackupValue::string($row['value'] ?? '', 'node-property.value'),
                'sort_order' => BackupValue::integer($row['sort_order'] ?? 100, 'node-property.sort_order'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                ->where('node_id', '=', $nodeId)
                ->where('property_key', '=', $key)
                ->first();
            if (is_array($existing)) {
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)
                    ->where('id', '=', BackupValue::integer($existing['id'], 'node-property.id'))
                    ->update($values);
            } else {
                $values['created_at'] = date('Y-m-d H:i:s');
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)->insert($values);
            }
        }
    }

    /**
     * HR: Upisuje ACL prema stabilnom subjektu.
     * EN: Upserts ACL by stable subject.
     *
     * @param array<string,mixed> $row
     */
    private function upsertAcl(string $table, string $foreignKey, int $foreignId, array $row): void
    {
        $subjectType = BackupValue::string($row['subject_type'], 'acl.subject_type');
        $subjectKey = BackupValue::string($row['subject_key'], 'acl.subject_key');
        $subjectId = $this->identities->subjectId($subjectType, $subjectKey);
        if ($subjectId === null) {
            throw new BackupException('Unable to resolve imported workspace ACL subject.');
        }

        $values = [
            $foreignKey => $foreignId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'can_view' => BackupValue::booleanInteger($row['can_view'], 'acl.can_view'),
            'can_add' => BackupValue::booleanInteger($row['can_add'], 'acl.can_add'),
            'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'acl.can_edit'),
            'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'acl.can_publish'),
            'can_delete' => BackupValue::booleanInteger($row['can_delete'], 'acl.can_delete'),
            'can_manage' => BackupValue::booleanInteger($row['can_manage'], 'acl.can_manage'),
            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
        ];
        $existing = $this->database->table($table)->where($foreignKey, '=', $foreignId)
            ->where('subject_type', '=', $subjectType)->where('subject_id', '=', $subjectId)->first();
        is_array($existing)
        ? $this->database->table($table)
            ->where('id', '=', BackupValue::integer($existing['id'], 'acl.id'))
            ->update($values)
        : $this->database->table($table)->insert($values);
    }

    /** HR: Uvozi jezične tijekove objave stranica. EN: Imports page publication workflows by language. */
    private function importWorkflows(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'workflows') as $row) {
            $nodeId = $this->mappedInteger(
                $context,
                'workspace.node-id',
                BackupValue::string($row['node_uuid'], 'workflow.node_uuid'),
            );
            $languageCode = BackupValue::string($row['language_code'], 'workflow.language_code');
            $values = [
                'node_id' => $nodeId,
                'language_code' => $languageCode,
                'status' => BackupValue::string($row['status'], 'workflow.status'),
                'current_version_number' => $row['current_version_number'] ?? null,
                'published_version_number' => $row['published_version_number'] ?? null,
                'submitted_by_user_id' => $this->user($row['submitted_by_user'] ?? null, $context),
                'submitted_at' => $row['submitted_at'] ?? null,
                'published_by_user_id' => $this->user($row['published_by_user'] ?? null, $context),
                'published_at' => $row['published_at'] ?? null,
                'archived_by_user_id' => $this->user($row['archived_by_user'] ?? null, $context),
                'archived_at' => $row['archived_at'] ?? null,
                'updated_by_user_id' => $this->user($row['updated_by_user'] ?? null, $context),
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
            $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                ->where('node_id', '=', $nodeId)->where('language_code', '=', $languageCode)->first();
            is_array($existing)
            ? $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
                ->where('id', '=', BackupValue::integer($existing['id'], 'workflow.id'))
                ->update($values)
            : $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert($values);
        }
    }

    /** HR: Uvozi privatnu temu i priprema njezine datoteke. EN: Imports a private theme and stages its files. */
    private function importTheme(int $workspaceId, BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $theme = $this->singleRecord($reader, 'theme');
        if ($theme !== null) {
            $values = $theme;
            unset($values['updated_by_user']);
            $values['workspace_id'] = $workspaceId;
            $values['updated_by_user_id'] = $this->user($theme['updated_by_user'] ?? null, $context);
            $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
                ->where('workspace_id', '=', $workspaceId)
                ->first();
            is_array($existing)
            ? $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
                ->where('id', '=', BackupValue::integer($existing['id'], 'theme.id'))
                ->update($values)
            : $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)->insert($values);
        }

        $records = iterator_to_array($reader->records(self::ID, 'theme-files'), false);
        if ($records === []) {
            return;
        }

        $target = $this->config->workspaceThemePath($workspaceId);
        $stage = dirname($target) . '/.restore-' . $workspaceId . '-' . bin2hex(random_bytes(8));
        $this->filesystem->ensureDirectory($stage);
        foreach ($records as $file) {
            $relative = $this->safeRelative(BackupValue::string($file['path'], 'theme-file.path'));
            $blob = is_array($file['blob'] ?? null) ? $file['blob'] : [];
            $hash = is_string($blob['sha256'] ?? null) ? $blob['sha256'] : '';
            $this->filesystem->copy($reader->blobPath($hash), $stage . '/' . $relative);
        }

        $this->stagedTheme = $stage;
        $this->publishedTheme = $target;
    }

    /**
     * HR: Čita najviše jedan zapis skupa.
     * EN: Reads at most one dataset record.
     *
     * @return array<string,mixed>|null
     */
    private function singleRecord(BackupArchiveReader $reader, string $dataset): ?array
    {
        foreach ($reader->records(self::ID, $dataset) as $record) {
            return $record;
        }

        return null;
    }

    /**
     * HR: Dohvaća područje i po izboru zahtijeva postojanje.
     * EN: Fetches a Workspace and optionally requires it to exist.
     *
     * @return array<string,mixed>|null
     */
    private function workspace(?string $identifier, bool $throw = true): ?array
    {
        $identifier = trim((string)$identifier);
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->where('slug', '=', $identifier)->first()
        ?? $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->where('uuid', '=', $identifier)->first();
        if (!is_array($row) && $throw) {
            throw new BackupException('Workspace does not exist: ' . $identifier);
        }

        return is_array($row) ? $row : null;
    }

    /**
     * HR: Određuje i provjerava ciljni slug područja.
     * EN: Determines and validates the target Workspace slug.
     *
     * @param array<string,mixed> $record
     */
    private function targetIdentifier(BackupImportContext $context, array $record): string
    {
        $options = $context->optionsFor(self::ID);
        $target = trim(BackupValue::string(
            $options['target_slug'] ?? $context->scope->identifier ?? $record['slug'] ?? '',
            'workspace.target_slug',
        ));
        if ($target === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $target) !== 1) {
            throw new BackupException('Target workspace slug is invalid.');
        }

        return $target;
    }

    /** HR: Razrješava prenosivi ključ korisnika. EN: Resolves a portable user key. */
    private function user(mixed $key, BackupImportContext $context): ?int
    {
        return $this->identities->userIdForKey(is_scalar($key) ? (string)$key : null, $context->actorUserId, true);
    }

    /**
     * HR: Čita cjelobrojnu vrijednost iz mape identiteta importa.
     * EN: Reads an integer value from the import identity map.
     */
    private function mappedInteger(BackupImportContext $context, string $namespace, int|string $key): int
    {
        return BackupValue::integer(
            $context->state->require($namespace, $key),
            'state.' . $namespace,
        );
    }

    /** HR: Prihvaća samo sigurnu relativnu putanju. EN: Accepts only a safe relative path. */
    private function safeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new BackupException('Unsafe workspace theme path: ' . $path);
        }

        return $path;
    }

    /** HR: Generira UUID v4 za kopirani zapis. EN: Generates a UUID v4 for a copied record. */
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
            substr($hex, 20),
        );
    }
}
