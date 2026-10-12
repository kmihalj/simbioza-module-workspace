<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupLinkAwareProviderInterface as LinkAwareProvider;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory;

/**
 * EN: Restores current domain records; installation settings belong to the configuration provider.
 *
 * HR: Vraća aktualne domenske zapise; instalacijske postavke pripadaju konfiguracijskom provideru.
 */
final class WorkspaceDatabaseBackupProvider extends DatabaseTableBackupProvider implements LinkAwareProvider
{
    /**
     * EN: Receives the current database definitions and routing policy.
     *
     * HR: Prima aktualne definicije baze i politiku ruta.
     *
     * @param list<array<string,mixed>> $definitions
     */
    public function __construct(
        Database $database,
        BackupProviderMetadata $metadata,
        array $definitions,
        private readonly WorkspaceConfig $config,
    ) {
        parent::__construct($database, $metadata, $definitions);
    }

    /**
     * EN: Records the source route root without exporting installation paths.
     *
     * HR: Zapisuje izvorni korijen ruta bez izvoza instalacijskih putanja.
     */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        $writer->setLinkRouteRoot('workspace', $this->config->rootPath());
        parent::export($context, $writer);
    }

    /**
     * EN: Validates current storage labels while keeping target IDs local.
     *
     * HR: Provjerava aktualne oznake pohrane uz lokalne ciljne ID-eve.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    protected function transformImportRecord(array $definition, array $record, BackupImportContext $context): array
    {
        $values = parent::transformImportRecord($definition, $record, $context);
        if ($values !== [] && ($definition['table'] ?? '') === 'workspaces') {
            $values['storage_slug'] = WorkspaceDataDirectory::label(
                BackupValue::string($values['storage_slug'] ?? null, 'workspace.storage_slug'),
                'workspace',
            );
        }

        return $values;
    }

    /**
     * EN: Registers actual targets after configuration has published its final route policy.
     *
     * HR: Registrira stvarne ciljeve nakon objave konačne politike ruta iz konfiguracije.
     */
    public function registerImportLinks(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $this->config->refresh();
        (new WorkspaceBackupLinks($this->database, $this->config->rootPath()))->register($context, $reader, $this
            ->metadata()->id);
    }

    /**
     * EN: Resolves link nodes only after every imported target has been registered.
     *
     * HR: Razrješava link čvorove tek nakon registracije svih uvezenih ciljeva.
     */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        parent::finalizeImport($context, $reader);
        (new WorkspaceBackupLinks($this->database, $this->config->rootPath()))->rewriteTargets($context);
    }
}
