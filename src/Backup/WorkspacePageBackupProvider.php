<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupPreflightResult;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Prenosi jedan čvor stranice i njegove Workspace metapodatke. Sadržaj,
 *     verzije i privitke prenosi ovisni Editor provider.
 * EN: Transfers one page node and its Workspace metadata. The dependent Editor
 *     provider transfers content, versions, and attachments.
 */
final readonly class WorkspacePageBackupProvider implements BackupProviderInterface
{
    private const ID = 'page-transfer';

    /** HR: Prima bazu i javne servise. EN: Receives the database and public services. */
    public function __construct(
        private Database $database,
        private AuthBackupIdentityResolver $identities,
        private WorkspaceRepository $repository,
    ) {
    }

    /** HR: Opisuje podržani PAGE scope. EN: Describes the supported PAGE scope. */
    public function metadata(): BackupProviderMetadata
    {
        return new BackupProviderMetadata(
            self::ID,
            ModuleWorkspace::PACKAGE_NAME,
            1,
            ['hr' => 'Stranica, jezici, povijest i privitci', 'en' => 'Page, languages, history, and attachments'],
            ['editor-html-workspace'],
            [BackupScope::PAGE],
            true,
            true,
            [ModuleWorkspace::PACKAGE_NAME],
        );
    }

    /** HR: Izvozi metapodatke stranice. EN: Exports page metadata. */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        $node = $this->sourceNode($context->scope->identifier);
        $nodeId = BackupValue::integer($node['id'], 'page.id');
        $nodeUuid = BackupValue::string($node['uuid'], 'page.uuid');
        $workspaceId = BackupValue::integer($node['workspace_id'], 'page.workspace_id');
        $workspace = $this->repository->findWorkspaceById($workspaceId);
        if (!is_array($workspace)) {
            throw new BackupException('Source Workspace does not exist.');
        }

        $writer->writeRecord(self::ID, 'page', [
            'source_id' => $nodeId,
            'uuid' => $nodeUuid,
            'source_workspace' => BackupValue::string($workspace['slug'], 'workspace.slug'),
            'node_type' => BackupValue::string($node['node_type'], 'page.node_type'),
            'slug' => BackupValue::string($node['slug'], 'page.slug'),
            'title' => BackupValue::string($node['title'], 'page.title'),
            'title_translations' => $node['title_translations'] ?? null,
            'document_key' => BackupValue::string($node['document_key'], 'page.document_key'),
            'permissions_included' => $this->includePermissions($context),
            'history_included' => $this->includeHistory($context),
            'is_tree_hidden' => BackupValue::booleanInteger($node['is_tree_hidden'] ?? false, 'page.is_tree_hidden'),
            'is_enabled' => BackupValue::booleanInteger($node['is_enabled'], 'page.is_enabled'),
            'contents_visibility' => BackupValue::string($node['contents_visibility'], 'page.contents_visibility'),
            'created_by_user' => $this->identities->userKeyForId($node['created_by_user_id'] ?? null),
            'updated_by_user' => $this->identities->userKeyForId($node['updated_by_user_id'] ?? null),
            'created_at' => $node['created_at'] ?? null,
            'updated_at' => $node['updated_at'] ?? null,
        ]);

        foreach ($this->rows(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS, $nodeId, 'label') as $row) {
            $writer->writeRecord(self::ID, 'labels', ['label' => BackupValue::string($row['label'], 'label.label')]);
        }

        foreach ($this->rows(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES, $nodeId, 'sort_order') as $row) {
            $writer->writeRecord(self::ID, 'properties', [
                'key' => BackupValue::string($row['property_key'], 'property.key'),
                'label' => BackupValue::string($row['property_label'], 'property.label'),
                'type' => BackupValue::string($row['property_type'], 'property.type'),
                'value' => BackupValue::nullableString($row['property_value'] ?? null, 'property.value'),
                'sort_order' => BackupValue::integer($row['sort_order'], 'property.sort_order'),
            ]);
        }

        foreach ($this->rows(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS, $nodeId, 'language_code') as $row) {
            $includeHistory = $this->includeHistory($context);
            // HR: Najnovija objavljena verzija ostaje objavljena i bez povijesti;
            //     noviji nacrt nikada ne objavljujemo samo zbog kopiranja.
            // EN: Keep a latest published version published without history;
            //     never publish a newer draft merely because it was copied.
            $latestIsPublished = ($row['status'] ?? '') === 'published'
            && ($row['published_version_number'] ?? null) !== null
            && ($row['current_version_number'] ?? null) === $row['published_version_number'];
            $writer->writeRecord(self::ID, 'workflows', [
                'language_code' => BackupValue::string($row['language_code'], 'workflow.language_code'),
                'status' => $includeHistory
                    ? BackupValue::string($row['status'], 'workflow.status')
                    : ($latestIsPublished ? 'published' : 'draft'),
                'current_version_number' => $row['current_version_number'] ?? null,
                'published_version_number' => $includeHistory || $latestIsPublished
                    ? ($row['published_version_number'] ?? null)
                    : null,
                'submitted_by_user' => $includeHistory
                    ? $this->identities->userKeyForId($row['submitted_by_user_id'] ?? null)
                    : null,
                'submitted_at' => $includeHistory ? ($row['submitted_at'] ?? null) : null,
                'published_by_user' => $includeHistory
                    ? $this->identities->userKeyForId($row['published_by_user_id'] ?? null)
                    : null,
                'published_at' => $includeHistory ? ($row['published_at'] ?? null) : null,
                'archived_by_user' => $includeHistory
                    ? $this->identities->userKeyForId($row['archived_by_user_id'] ?? null)
                    : null,
                'archived_at' => $includeHistory ? ($row['archived_at'] ?? null) : null,
                'updated_by_user' => $this->identities->userKeyForId($row['updated_by_user_id'] ?? null),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }

        if ($this->includePermissions($context)) {
            $this->exportPermissions($nodeId, $writer);
        }
    }

    /** HR: Provjerava cilj i opcionalna prava. EN: Checks the target and optional permissions. */
    public function preflight(BackupImportContext $context, BackupArchiveReader $reader): BackupPreflightResult
    {
        $page = $this->pageRecord($reader);
        if ($page === null) {
            return new BackupPreflightResult(['Page backup does not contain a page record.']);
        }

        $errors = [];
        $warnings = [];
        if ($this->importPermissions($context) && !($page['permissions_included'] ?? false)) {
            $errors[] = 'This page archive does not contain permissions or restrictions.';
        }

        if (
            !in_array(
                $context->conflictMode,
                [BackupImportContext::CONFLICT_COPY, BackupImportContext::CONFLICT_REPLACE],
                true,
            )
        ) {
            $errors[] = 'A single page must be imported as a new page or replace an explicitly selected page.';
        }

        if ($context->conflictMode === BackupImportContext::CONFLICT_REPLACE) {
            if ($this->targetPage($context) === null) {
                $errors[] = 'Select the existing Workspace page to replace.';
            } else {
                $warnings[] = 'The selected existing page will be replaced while its URL '
                . 'and tree position stay unchanged.';
            }
        } else {
            $workspace = $this->targetWorkspace($context);
            if (!is_array($workspace)) {
                $errors[] = 'Target Workspace does not exist.';
            } elseif (($parentId = $this->targetParentId($context)) !== null) {
                $parent = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                    ->where('id', '=', $parentId)
                    ->where('workspace_id', '=', BackupValue::integer($workspace['id'], 'workspace.id'))
                    ->first();
                if (!is_array($parent)) {
                    $errors[] = 'Target parent page does not belong to the selected Workspace.';
                }
            }
        }

        if ($this->importPermissions($context)) {
            foreach ($reader->records(self::ID, 'node-acl') as $row) {
                $type = BackupValue::string($row['subject_type'], 'node-acl.subject_type');
                $key = BackupValue::string($row['subject_key'], 'node-acl.subject_key');
                if ($this->identities->subjectId($type, $key) === null) {
                    $warnings[] = sprintf(
                        'Page restriction will be skipped because its subject is unavailable: %s:%s.',
                        $type,
                        $key,
                    );
                }
            }

            foreach ($reader->records(self::ID, 'direct-permissions') as $row) {
                $key = BackupValue::string($row['user_key'], 'direct-permission.user_key');
                if ($this->identities->userIdForKey($key) === null) {
                    $warnings[] = sprintf(
                        'Direct page permission will be skipped because its user is unavailable: %s.',
                        $key,
                    );
                }
            }
        }

        return new BackupPreflightResult(array_values(array_unique($errors)), array_values(array_unique($warnings)), [
            'page' => BackupValue::string($page['title'], 'page.title'),
        ]);
    }

    /** HR: Nema dodatne pripreme. EN: No additional preparation is required. */
    public function prepareImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
    }

    /** HR: Kopira ili zamjenjuje stranicu. EN: Copies or replaces the page. */
    public function import(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $page = $this->pageRecord($reader);
        $replace = $context->conflictMode === BackupImportContext::CONFLICT_REPLACE;
        $targetPage = $replace ? $this->targetPage($context) : null;
        $workspace = $replace && is_array($targetPage)
        ? $this->repository->findWorkspaceById(BackupValue::integer(
            $targetPage['workspace_id'],
            'target-page.workspace_id',
        ))
        : $this->targetWorkspace($context);
        if ($page === null || !is_array($workspace)) {
            throw new BackupException('Page or target Workspace is unavailable.');
        }

        $workspaceId = BackupValue::integer($workspace['id'], 'workspace.id');
        $sourceKey = BackupValue::string($page['document_key'], 'page.document_key');
        $documentKey = BackupValue::string(
            $context->state->require('editor.document-key', $sourceKey),
            'state.editor.document-key',
        );
        $sourceUuid = BackupValue::string($page['uuid'], 'page.uuid');
        $now = date('Y-m-d H:i:s');
        if ($replace && is_array($targetPage)) {
            $nodeId = BackupValue::integer($targetPage['id'], 'target-page.id');
            $uuid = BackupValue::string($targetPage['uuid'], 'target-page.uuid');
            $slug = BackupValue::string($targetPage['slug'], 'target-page.slug');
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('id', '=', $nodeId)
                ->update([
                    'title' => BackupValue::string($page['title'], 'page.title'),
                    'title_translations' => $page['title_translations'] ?? null,
                    'document_key' => $documentKey,
                    'is_tree_hidden' => BackupValue::booleanInteger(
                        $page['is_tree_hidden'] ?? false,
                        'page.is_tree_hidden',
                    ),
                    'is_enabled' => BackupValue::booleanInteger($page['is_enabled'], 'page.is_enabled'),
                    'contents_visibility' => BackupValue::string(
                        $page['contents_visibility'],
                        'page.contents_visibility',
                    ),
                    'updated_by_user_id' => $context->actorUserId,
                    'updated_at' => $now,
                ]);
            $this->clearPageMetadata($nodeId, $this->importPermissions($context));
        } else {
            $slug = $this->uniqueSlug($workspaceId, BackupValue::string($page['slug'], 'page.slug'));
            $uuid = $this->uuid();
            $sortOrder = $this->nextSortOrder($workspaceId, $this->targetParentId($context));
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
                'uuid' => $uuid,
                'workspace_id' => $workspaceId,
                'parent_id' => $this->targetParentId($context),
                'node_type' => 'document',
                'slug' => $slug,
                'title' => BackupValue::string($page['title'], 'page.title'),
                'title_translations' => $page['title_translations'] ?? null,
                'document_key' => $documentKey,
                'route_name' => null,
                'target_url' => null,
                'sort_order' => $sortOrder,
                'is_homepage' => 0,
                'is_tree_hidden' => BackupValue::booleanInteger(
                    $page['is_tree_hidden'] ?? false,
                    'page.is_tree_hidden',
                ),
                'is_enabled' => BackupValue::booleanInteger($page['is_enabled'], 'page.is_enabled'),
                'contents_visibility' => BackupValue::string($page['contents_visibility'], 'page.contents_visibility'),
                'created_by_user_id' => $this->user($page['created_by_user'] ?? null, $context),
                'updated_by_user_id' => $this->user($page['updated_by_user'] ?? null, $context),
                'created_at' => $page['created_at'] ?? $now,
                'updated_at' => $page['updated_at'] ?? $now,
            ]);
            $created = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)
                ->where('slug', '=', $slug)
                ->first();
            if (!is_array($created)) {
                throw new BackupException('Imported page could not be resolved.');
            }

            $nodeId = BackupValue::integer($created['id'], 'page.id');
        }

        $context->state->map('workspace.node-id', $sourceUuid, $nodeId);
        $context->state->map('workspace.node-uuid', $sourceUuid, $uuid);
        $context->state->map('page-transfer.target-node-id', $sourceUuid, $nodeId);
        $context->state->map('page-transfer.target-slug', $sourceUuid, $slug);

        $this->importLabels($nodeId, $reader);
        $this->importProperties($nodeId, $reader);
        $this->importWorkflows($nodeId, $context, $reader);
        if ($this->importPermissions($context)) {
            $this->importPermissionsForNode($nodeId, $reader);
        }
    }

    /** HR: Završetak vodi Backup manager. EN: The Backup manager handles finalization. */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
    }

    /** HR: Povrat vodi transakcija managera. EN: The manager transaction handles rollback. */
    public function abortImport(BackupImportContext $context): void
    {
    }

    /** HR: Izvozi prava uz prenosive identitete. EN: Exports permissions with portable identities. */
    private function exportPermissions(int $nodeId, BackupArchiveWriter $writer): void
    {
        foreach ($this->rows(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL, $nodeId, 'id') as $row) {
            $subject = $this->identities->subjectReference($row['subject_type'] ?? null, $row['subject_id'] ?? null);
            if ($subject === null) {
                continue;
            }

            $writer->writeRecord(self::ID, 'node-acl', [
                'subject_type' => $subject['type'], 'subject_key' => $subject['key'],
                'can_view' => BackupValue::booleanInteger($row['can_view'], 'acl.can_view'),
                'can_add' => BackupValue::booleanInteger($row['can_add'], 'acl.can_add'),
                'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'acl.can_edit'),
                'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'acl.can_publish'),
                'can_delete' => BackupValue::booleanInteger($row['can_delete'], 'acl.can_delete'),
                'can_manage' => BackupValue::booleanInteger($row['can_manage'], 'acl.can_manage'),
            ]);
        }

        foreach ($this->rows(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS, $nodeId, 'user_id') as $row) {
            $key = $this->identities->userKeyForId($row['user_id'] ?? null);
            if ($key === null) {
                continue;
            }

            $writer->writeRecord(self::ID, 'direct-permissions', [
                'user_key' => $key,
                'can_view' => BackupValue::booleanInteger($row['can_view'], 'direct.can_view'),
                'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'direct.can_edit'),
                'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'direct.can_publish'),
            ]);
        }
    }

    /** HR: Uvozi oznake stranice. EN: Imports page labels. */
    private function importLabels(int $nodeId, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'labels') as $row) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS)->insert([
                'node_id' => $nodeId,
                'label' => BackupValue::string($row['label'], 'label.label'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** HR: Uvozi svojstva stranice. EN: Imports page properties. */
    private function importProperties(int $nodeId, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'properties') as $row) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES)->insert([
                'node_id' => $nodeId,
                'property_key' => BackupValue::string($row['key'], 'property.key'),
                'property_label' => BackupValue::string($row['label'], 'property.label'),
                'property_type' => BackupValue::string($row['type'], 'property.type'),
                'property_value' => BackupValue::nullableString($row['value'] ?? null, 'property.value'),
                'sort_order' => BackupValue::integer($row['sort_order'], 'property.sort_order'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** HR: Vraća status svakog jezika. EN: Restores each language's workflow status. */
    private function importWorkflows(int $nodeId, BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'workflows') as $row) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)->insert([
                'node_id' => $nodeId,
                'language_code' => BackupValue::string($row['language_code'], 'workflow.language_code'),
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
            ]);
        }
    }

    /** HR: Primjenjuje prava samo postojećim identitetima. EN: Applies permissions only to existing identities. */
    private function importPermissionsForNode(int $nodeId, BackupArchiveReader $reader): void
    {
        foreach ($reader->records(self::ID, 'node-acl') as $row) {
            $type = BackupValue::string($row['subject_type'], 'acl.subject_type');
            $key = BackupValue::string($row['subject_key'], 'acl.subject_key');
            $subjectId = $this->identities->subjectId($type, $key);
            if ($subjectId === null) {
                continue;
            }

            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)->insert([
                'node_id' => $nodeId, 'subject_type' => $type, 'subject_id' => $subjectId,
                'can_view' => BackupValue::booleanInteger($row['can_view'], 'acl.can_view'),
                'can_add' => BackupValue::booleanInteger($row['can_add'], 'acl.can_add'),
                'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'acl.can_edit'),
                'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'acl.can_publish'),
                'can_delete' => BackupValue::booleanInteger($row['can_delete'], 'acl.can_delete'),
                'can_manage' => BackupValue::booleanInteger($row['can_manage'], 'acl.can_manage'),
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        foreach ($reader->records(self::ID, 'direct-permissions') as $row) {
            $userId = $this->identities->userIdForKey(BackupValue::string($row['user_key'], 'direct.user_key'));
            if ($userId === null) {
                continue;
            }

            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)->insert([
                'node_id' => $nodeId, 'user_id' => $userId,
                'can_view' => BackupValue::booleanInteger($row['can_view'], 'direct.can_view'),
                'can_edit' => BackupValue::booleanInteger($row['can_edit'], 'direct.can_edit'),
                'can_publish' => BackupValue::booleanInteger($row['can_publish'], 'direct.can_publish'),
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** HR: Pronalazi izvornu stranicu. EN: Finds the source page.
     * @return array<string,mixed> */
    private function sourceNode(?string $identifier): array
    {
        $identifier = trim((string)$identifier);
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES);
        $node = ctype_digit($identifier)
        ? $query->where('id', '=', (int)$identifier)->first()
        : $query->where('uuid', '=', $identifier)->first();
        if (!is_array($node) || ($node['node_type'] ?? null) !== 'document') {
            throw new BackupException('Workspace page does not exist: ' . $identifier);
        }

        return $node;
    }

    /** HR: Čita zapis stranice iz arhive. EN: Reads the page record from the archive.
     * @return array<string,mixed>|null */
    private function pageRecord(BackupArchiveReader $reader): ?array
    {
        foreach ($reader->records(self::ID, 'page') as $row) {
            return $row;
        }

        return null;
    }

    /** HR: Pronalazi ciljno područje. EN: Finds the target Workspace.
     * @return array<string,mixed>|null */
    private function targetWorkspace(BackupImportContext $context): ?array
    {
        $configured = $context->optionsFor(self::ID)['target_workspace'] ?? $context->scope->identifier;
        $identifier = is_scalar($configured) ? trim((string)$configured) : '';
        if ($identifier === '') {
            return null;
        }

        $workspace = is_numeric($identifier) && (int)$identifier > 0
        ? $this->repository->findWorkspaceById((int)$identifier)
        : $this->repository->findWorkspaceBySlug($identifier);
        $workspace = WorkspaceValue::stringKeyArray($workspace);
        return $workspace !== [] ? $workspace : null;
    }

    /** HR: Pronalazi stranicu za zamjenu. EN: Finds the page to replace.
     * @return array<string,mixed>|null */
    private function targetPage(BackupImportContext $context): ?array
    {
        $configured = $context->optionsFor(self::ID)['target_page_id'] ?? null;
        $pageId = is_numeric($configured) ? (int)$configured : 0;
        if ($pageId <= 0) {
            return null;
        }

        $page = WorkspaceValue::stringKeyArray(
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('id', '=', $pageId)
                ->where('node_type', '=', 'document')
                ->first(),
        );
        return $page !== [] ? $page : null;
    }

    /** HR: Čita odabrani nadređeni čvor. EN: Reads the selected parent node. */
    private function targetParentId(BackupImportContext $context): ?int
    {
        $value = $context->optionsFor(self::ID)['target_parent_id'] ?? null;
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    /** HR: Čita izbor izvoza prava. EN: Reads the ACL export choice. */
    private function includePermissions(BackupExportContext $context): bool
    {
        return (bool)($context->optionsFor(self::ID)['include_permissions'] ?? false);
    }

    /** HR: Čita izbor izvoza povijesti. EN: Reads the history export choice. */
    private function includeHistory(BackupExportContext $context): bool
    {
        return (bool)($context->optionsFor(self::ID)['include_history'] ?? true);
    }

    /** HR: Čita izbor uvoza prava. EN: Reads the ACL import choice. */
    private function importPermissions(BackupImportContext $context): bool
    {
        return (bool)($context->optionsFor(self::ID)['import_permissions'] ?? false);
    }

    /** HR: Uklanja zamijenjene metapodatke. EN: Removes metadata being replaced. */
    private function clearPageMetadata(int $nodeId, bool $permissions): void
    {
        foreach (
            [
            ModuleWorkspace::TABLE_WORKSPACE_NODE_LABELS,
            ModuleWorkspace::TABLE_WORKSPACE_NODE_PROPERTIES,
            ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS,
            ] as $table
        ) {
            $this->database->table($table)->where('node_id', '=', $nodeId)->delete();
        }

        if (!$permissions) {
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL)
            ->where('node_id', '=', $nodeId)
            ->delete();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS)
            ->where('node_id', '=', $nodeId)
            ->delete();
    }

    /** HR: Čita uređene retke čvora. EN: Reads ordered node rows.
     * @return list<array<string,mixed>> */
    private function rows(string $table, int $nodeId, string $order): array
    {
        return array_values(array_filter(
            $this->database->table($table)->where('node_id', '=', $nodeId)->orderBy($order)->get(),
            is_array(...),
        ));
    }

    /** HR: Mapira prenosivi identitet korisnika. EN: Maps a portable user identity. */
    private function user(mixed $key, BackupImportContext $context): ?int
    {
        return $this->identities->userIdForKey(is_scalar($key) ? (string)$key : null, $context->actorUserId, true);
    }

    /** HR: Odabire slobodan URL slug kopije. EN: Chooses an unused URL slug for the copy. */
    private function uniqueSlug(int $workspaceId, string $source): string
    {
        $base = trim($source) !== '' ? substr(trim($source), 0, 180) : 'uvezena-stranica';
        $candidate = $base;
        $index = 2;
        while (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('workspace_id', '=', $workspaceId)->where('slug', '=', $candidate)->first() !== null
        ) {
            $candidate = substr($base, 0, 170) . '-' . $index++;
        }

        return $candidate;
    }

    /** HR: Dodaje kopiju na kraj grane. EN: Appends the copy to its branch. */
    private function nextSortOrder(int $workspaceId, ?int $parentId): int
    {
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId);
        $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', '=', $parentId);
        $rows = $query->orderBy('sort_order', 'DESC')->get();
        $first = is_array($rows[0] ?? null) ? $rows[0] : [];
        return (is_numeric($first['sort_order'] ?? null) ? (int)$first['sort_order'] : 0) + 10;
    }

    /** HR: Stvara identitet nove kopije. EN: Creates the new copy identity. */
    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
        . '-' . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3)
        . '-' . substr($hex, 20, 12);
    }
}
