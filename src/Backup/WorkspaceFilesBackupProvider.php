<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupCommitAwareProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupPreflightResult;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;

/**
 * EN: Transfers private theme files beside their destination Workspace identity.
 * Archive paths retain schema 1 compatibility, but never select destination IDs directly.
 *
 * HR: Prenosi privatne datoteke tema uz identitet ciljnog područja.
 * Putanje arhive zadržavaju kompatibilnost sheme 1, ali ne biraju izravno ciljne ID-eve.
 */
final class WorkspaceFilesBackupProvider implements BackupProviderInterface, BackupCommitAwareProviderInterface
{
    /** @var list<array{stage:string,workspace:int,path:string}> */
    private array $pending = [];

    /** @var list<array{target:string,rollback:string|null}> */
    private array $published = [];

    /**
     * EN: Shares the domain database, current paths and safe filesystem operations.
     *
     * HR: Dijeli domensku bazu, aktualne putanje i sigurne operacije datoteka.
     */
    public function __construct(
        private readonly Database $database,
        private readonly WorkspaceConfig $config,
        private readonly BackupFilesystem $filesystem,
    ) {
    }

    /**
     * EN: Declares private themes as a required component of workspace/site restore.
     *
     * HR: Deklarira privatne teme kao obavezan dio povrata područja/sitea.
     */
    public function metadata(): BackupProviderMetadata
    {
        return new BackupProviderMetadata(
            'workspace-files',
            ModuleWorkspace::PACKAGE_NAME,
            1,
            ['en' => 'Private workspace themes', 'hr' => 'Privatne teme područja'],
            ['workspace'],
            requiredForFullRestore: true,
            componentGroups: ['workspaces'],
        );
    }

    /**
     * EN: Exports theme bytes with source workspace identities, never deployment paths.
     *
     * HR: Izvozi bajtove teme uz izvorne identitete područja, nikada putanje instalacije.
     */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        foreach ($this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->get() as $workspace) {
            if (!is_array($workspace)) {
                continue;
            }

            $id = BackupValue::integer($workspace['id'], 'workspace.id');
            $directory = $this->config->workspaceThemePath($id);
            foreach ($this->filesystem->files($directory) as $relative) {
                $path = $directory . '/' . $relative;
                if (is_link($path)) {
                    throw new BackupException('Workspace theme backup rejects symbolic links.');
                }

                $writer->writeRecord('workspace-files', 'files', [
                    'root' => 'workspace-themes', 'path' => $id . '/' . $relative,
                    'blob' => $writer->addBlobFile($path, 'application/octet-stream', basename($path)),
                ]);
            }
        }
    }

    /**
     * EN: Checks archive identities and file targets without changing runtime data.
     *
     * HR: Provjerava identitete arhive i ciljne datoteke bez promjene podataka aplikacije.
     */
    public function preflight(BackupImportContext $context, BackupArchiveReader $reader): BackupPreflightResult
    {
        $errors = [];
        $count = 0;
        foreach ($reader->records('workspace-files', 'files') as $record) {
            try {
                $this->identity($record);
                $blob = is_array($record['blob'] ?? null) ? $record['blob'] : [];
                $reader->blobPath(BackupValue::string($blob['sha256'] ?? null, 'blob.sha256'));
                ++$count;
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }

        return new BackupPreflightResult($errors, [], ['files' => $count]);
    }

    /**
     * EN: Starts a separate restore session with empty stage and rollback lists.
     *
     * HR: Započinje odvojenu obnovu s praznim popisima pripreme i rollbacka.
     */
    public function prepareImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $this->pending = [];
        $this->published = [];
    }

    /**
     * EN: Stages verified archive blobs before target IDs are mapped.
     *
     * HR: Priprema provjerene blobove arhive prije mapiranja ciljnih ID-eva.
     */
    public function import(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($reader->records('workspace-files', 'files') as $record) {
            [$workspace, $relative] = $this->identity($record);
            $blob = is_array($record['blob'] ?? null) ? $record['blob'] : [];
            $directory = $this->config->workspaceThemesPath();
            $this->directory($directory);
            $stage = tempnam($directory, '.theme-restore-');
            if (!is_string($stage)) {
                throw new BackupException('Unable to stage private Workspace theme.');
            }

            $this->pending[] = ['stage' => $stage, 'workspace' => $workspace, 'path' => $relative];
            $this->filesystem->copy(
                $reader->blobPath(BackupValue::string($blob['sha256'] ?? null, 'blob.sha256')),
                $stage,
            );
        }
    }

    /**
     * EN: Publishes theme files under mapped target owners, retaining rollback bytes and permissions.
     *
     * HR: Objavljuje teme pod mapiranim ciljnim vlasnicima uz čuvanje rollback bajtova i dozvola.
     */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        foreach ($this->pending as $file) {
            $id = BackupValue::integer(
                $context->state->require('workspace.workspace', $file['workspace']),
                'target.workspace',
            );
            $target = $this->safeTarget($id, $file['path']);
            $this->directory(dirname($target));
            $metadata = is_file($target) ? stat($target) : stat(dirname($target));
            if (
                !is_array($metadata) || !chmod($file['stage'], is_file($target) ? $metadata['mode'] & 0777 : 0660)
                || (filegroup($file['stage']) !== $metadata['gid'] && !chgrp($file['stage'], $metadata['gid']))
                || (function_exists('posix_geteuid') && posix_geteuid() === 0
                    && !chown($file['stage'], $metadata['uid']))
            ) {
                throw new BackupException('Unable to preserve Workspace theme permissions.');
            }

            $rollback = is_file($target) ? $target . '.rollback-' . bin2hex(random_bytes(8)) : null;
            if ($rollback !== null && !rename($target, $rollback)) {
                throw new BackupException('Unable to preserve Workspace theme restore target.');
            }

            $this->published[] = ['target' => $target, 'rollback' => $rollback];
            if (!rename($file['stage'], $target)) {
                throw new BackupException('Unable to publish private Workspace theme.');
            }
        }
    }

    /**
     * EN: Restores exact prior files when another provider or the transaction fails.
     *
     * HR: Vraća točne prethodne datoteke kada drugi provider ili transakcija ne uspije.
     */
    public function abortImport(BackupImportContext $context): void
    {
        foreach (array_reverse($this->published) as $file) {
            if (is_file($file['target'])) {
                unlink($file['target']);
            }

            if ($file['rollback'] !== null && is_file($file['rollback'])) {
                rename($file['rollback'], $file['target']);
            }
        }

        $this->cleanup();
    }

    /**
     * EN: Discards rollback files only after a successful commit.
     *
     * HR: Uklanja rollback datoteke tek nakon uspješnog commita.
     */
    public function completeImport(BackupImportContext $context): void
    {
        foreach ($this->published as $file) {
            if ($file['rollback'] !== null && is_file($file['rollback'])) {
                unlink($file['rollback']);
            }
        }

        $this->cleanup();
    }

    /**
     * EN: Removes only this restore session's temporary files.
     *
     * HR: Uklanja samo privremene datoteke ove obnove.
     */
    private function cleanup(): void
    {
        foreach ($this->pending as $file) {
            if (is_file($file['stage'])) {
                unlink($file['stage']);
            }
        }

        $this->pending = [];
        $this->published = [];
    }

    /**
     * EN: Validates a schema 1 source identity and bounded relative filename.
     *
     * HR: Provjerava izvorni identitet sheme 1 i ograničeno relativno ime datoteke.
     *
     * @param array<string,mixed> $record
     * @return array{int,string}
     */
    private function identity(array $record): array
    {
        $path = BackupValue::string($record['path'] ?? null, 'theme.path');
        if (
            ($record['root'] ?? null) !== 'workspace-themes'
            || preg_match('#^([1-9][0-9]*)/(.+)$#D', $path, $parts) !== 1
        ) {
            throw new BackupException('Invalid Workspace theme archive identity.');
        }

        // EN: Source IDs do not exist on the target yet; resolve directories only after ID mapping.
        //
        // HR: Izvorni ID-evi još ne postoje na cilju; direktorije razriješi tek nakon mapiranja ID-eva.
        if (
            str_contains($parts[2], '\\') || str_contains($parts[2], ':')
            || preg_match('/[\x00-\x1f]/', $parts[2]) === 1
        ) {
            throw new BackupException('Unsafe Workspace theme filename.');
        }

        foreach (explode('/', $parts[2]) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                throw new BackupException('Unsafe Workspace theme archive path.');
            }
        }

        return [(int)$parts[1], $parts[2]];
    }

    /**
     * EN: Resolves a contained target and rejects links, traversal and non-file conflicts.
     *
     * HR: Razrješava ograničeni cilj i odbija linkove, izlazak iz direktorija i sukob s nedatotekama.
     */
    private function safeTarget(int $workspace, string $relative): string
    {
        if (
            str_contains($relative, '\\') || str_contains($relative, ':')
            || preg_match('/[\x00-\x1f]/', $relative) === 1
        ) {
            throw new BackupException('Unsafe Workspace theme filename.');
        }

        $target = $this->config->workspaceThemePath($workspace);
        for (
            $check = $target;
            strlen($check) >= strlen($this->config->workspaceThemesPath());
            $check = dirname($check)
        ) {
            if (is_link($check)) {
                throw new BackupException('Workspace theme parent contains a symbolic link.');
            }
        }

        foreach (explode('/', $relative) as $part) {
            if (in_array($part, ['', '.', '..'], true) || is_link($target)) {
                throw new BackupException('Unsafe Workspace theme path.');
            }

            $target .= '/' . $part;
        }

        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new BackupException('Workspace theme restore target is not a regular file.');
        }

        return $target;
    }

    /**
     * EN: Inherits writable data-directory ownership during root CLI restores as well as GUI restores.
     *
     * HR: Nasljeđuje vlasništvo zapisivih direktorija podataka i pri root CLI i pri GUI obnovi.
     */
    private function directory(string $path): void
    {
        if (is_link($path)) {
            throw new BackupException('Workspace theme directory is a symbolic link.');
        }

        if (is_dir($path)) {
            return;
        }

        $parent = dirname($path);
        $this->directory($parent);
        $metadata = stat($parent);
        if (
            !is_array($metadata) || !mkdir($path, $metadata['mode'] & 0777)
            || !chmod($path, $metadata['mode'] & 0777)
            || (filegroup($path) !== $metadata['gid'] && !chgrp($path, $metadata['gid']))
            || (function_exists('posix_geteuid') && posix_geteuid() === 0 && !chown($path, $metadata['uid']))
        ) {
            throw new BackupException('Unable to preserve Workspace theme directory permissions.');
        }

        clearstatcache(true, $path);
    }
}
