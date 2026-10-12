<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupLinkRelocator;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

/**
 * EN: Registers imported page routes before any provider rewrites HTML.
 * Database IDs are resolved locally; an unrelated same-slug page is never a fallback.
 *
 * HR: Registrira rute uvezenih stranica prije prepisivanja HTML-a bilo kojeg providera.
 * ID-evi se razrješavaju lokalno; nepovezana stranica istog sluga nije rezervni cilj.
 */
final readonly class WorkspaceBackupLinks
{
    /**
     * EN: Receives the local database and target Workspace route root.
     *
     * HR: Prima lokalnu bazu i ciljni korijen Workspace ruta.
     */
    public function __construct(private Database $database, private string $targetRoot = 'workspace')
    {
    }

    /**
     * EN: Supports site, workspace and single-page archives with their existing identity maps.
     *
     * HR: Podržava arhive sitea, područja i pojedinačne stranice uz postojeća mapiranja.
     */
    public function register(BackupImportContext $context, BackupArchiveReader $reader, string $provider): void
    {
        $links = new BackupLinkRelocator($context);
        if (!$links->isCrossSite()) {
            return;
        }

        $sourceRoot = BackupLinkRelocator::sourceRouteRoot($reader, 'workspace', '/workspace');
        $targetRoot = '/' . trim($this->targetRoot, '/');
        $workspaces = [];
        $dataset = $provider === 'workspace' ? 'workspaces' : 'workspace';
        if ($provider !== 'page-transfer') {
            foreach ($reader->records($provider, $dataset) as $source) {
                $sourceId = BackupValue::integer($source['id'] ?? $source['source_id'] ?? null, 'workspace.id');
                $workspaces[(string)$sourceId] = BackupValue::string($source['slug'] ?? null, 'workspace.slug');
                $namespace = $provider === 'workspace' ? 'workspace.workspace' : 'workspace.id';
                $targetId = $context->state->require($namespace, $sourceId);
                $target = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->where('id', '=', $targetId)
                    ->first();
                if (is_array($target)) {
                    $links->register(
                        $sourceRoot . '/' . rawurlencode($workspaces[(string)$sourceId]),
                        $targetRoot . '/' . rawurlencode(BackupValue::string(
                            $target['slug'] ?? null,
                            'target.workspace.slug',
                        )),
                    );
                }
            }
        }

        foreach ($reader->records($provider, $provider === 'page-transfer' ? 'page' : 'nodes') as $source) {
            $namespace = $provider === 'workspace' ? 'workspace.node' : 'workspace.node-id';
            $sourceId = $provider === 'workspace' ? BackupValue::integer($source['id'] ?? null, 'node.id')
            : BackupValue::string($source['uuid'] ?? null, 'node.uuid');
            $targetId = $context->state->require($namespace, $sourceId);
            $target = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->where('id', '=', $targetId)
                ->first();
            $workspace = is_array($target) ? $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                ->where('id', '=', $target['workspace_id'])->first() : null;
            if (!is_array($target) || !is_array($workspace)) {
                continue;
            }

            $sourceSlug = $source['source_workspace'] ?? ($provider === 'workspace'
                ? ($workspaces[(string)BackupValue::integer(
                    $source['workspace_id'] ?? null,
                    'node.workspace_id',
                )] ?? '')
                : reset($workspaces));
            $path = $sourceRoot . '/' . rawurlencode(BackupValue::string($sourceSlug, 'node.source_workspace'))
            . '/' . rawurlencode(BackupValue::string($source['slug'] ?? null, 'node.slug'));
            $links->register(
                $path,
                $targetRoot . '/' . rawurlencode(BackupValue::string(
                    $workspace['slug'] ?? null,
                    'target.workspace.slug',
                ))
                . '/' . rawurlencode(BackupValue::string($target['slug'] ?? null, 'target.node.slug')),
                BackupValue::nullableString($source['document_key'] ?? null, 'node.document_key'),
            );
            $context->state->append('workspace.backup-link-nodes', ['id' => $targetId, 'source_path' => $path]);
        }
    }

    /**
     * EN: Link-type nodes follow the same policy after all target mappings are known.
     *
     * HR: Link čvorovi slijede istu politiku nakon što su poznata sva ciljna mapiranja.
     */
    public function rewriteTargets(BackupImportContext $context): void
    {
        $links = new BackupLinkRelocator($context);
        $items = $context->state->get('workspace.backup-link-nodes', []);
        if (!is_array($items)) {
            throw new BackupException('Invalid pending Workspace link state.');
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new BackupException('Invalid pending Workspace link record.');
            }

            $id = BackupValue::integer($item['id'] ?? null, 'target.node.id');
            $sourcePath = BackupValue::string($item['source_path'] ?? null, 'node.source_path');
            $node = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->where('id', '=', $id)->first();
            if (!is_array($node) || !is_string($node['target_url'] ?? null) || $node['target_url'] === '') {
                continue;
            }

            $url = $links->rewrite($node['target_url'], $sourcePath);
            if ($url !== $node['target_url']) {
                $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->where('id', '=', $id)
                    ->update(['target_url' => $url]);
            }
        }

        $context->state->forget('workspace.backup-link-nodes');
    }
}
