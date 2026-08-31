<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * HR: Uvozi i izvozi privatnu temu područja u formatu kompatibilnom sa sistemskom bibliotekom tema.
 * EN: Imports and exports a private workspace theme in the system theme-library compatible format.
 */
final readonly class WorkspaceThemeArchiveService
{
    private const FORMAT = 'heartphrame-theme-export';

    private const VERSION = 3;

    private const MAX_ARCHIVE_BYTES = 536_870_912;

    private const MAX_FILES = 2_000;

    private const THEME_REPOSITORY = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeConfigRepository';

    /**
     * HR: Prima opcionalni Theme repozitorij, privatnu pohranu i direktorije potrebne za prijenos teme.
     * EN: Receives the optional Theme repository, private storage, and directories needed for theme transfer.
     */
    public function __construct(
        private ContainerInterface $container,
        private WorkspaceThemeRepository $repository,
        private WorkspaceThemeAssetLibrary $assetLibrary,
        private WorkspaceConfig $config,
    ) {
    }

    /**
     * HR: Izvozi samo privatnu temu i cijelu njezinu biblioteku; pozivatelj provjerava administratorsko pravo.
     * EN: Exports only a private theme and its complete library; the caller checks administrator permission.
     *
     * @param array<string,mixed> $workspace
     */
    public function export(array $workspace): string
    {
        $this->requireZip();
        $workspaceId = $this->workspaceId($workspace);
        $state = $this->repository->forWorkspace($workspaceId);
        $theme = is_array($state['theme'] ?? null)
        ? WorkspaceValue::stringKeyArray($state['theme'])
        : null;
        if ($state['selection_type'] !== WorkspaceThemeRepository::SELECTION_CUSTOM || $theme === null) {
            throw new RuntimeException('Only a private workspace theme can be exported.');
        }

        $exportId = $this->exportThemeId($theme, $workspaceId);
        $theme = WorkspaceValue::stringKeyArray($this->replaceRuntimeReferences($theme, $exportId));
        $theme['id'] = $exportId;
        $theme['system'] = false;
        $temporary = $this->temporaryPath('hph-workspace-theme-export-');
        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temporary);
            throw new RuntimeException('Unable to create the workspace theme export.');
        }

        try {
            $files = [];
            $root = $this->config->workspaceThemePath($workspaceId);
            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $entry) {
                    if (!($entry instanceof \SplFileInfo)) {
                        continue;
                    }

                    if (!$entry->isFile()) {
                        continue;
                    }

                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
                    if (!$this->safeRelativePath($relative)) {
                        continue;
                    }

                    $archivePath = 'theme-files/' . $relative;
                    $checksum = hash_file('sha256', $entry->getPathname());
                    if (!is_string($checksum) || !$zip->addFile($entry->getPathname(), $archivePath)) {
                        throw new RuntimeException('Unable to add a workspace theme file to the export.');
                    }

                    $files[] = ['path' => $relative, 'archive_path' => $archivePath, 'sha256' => $checksum];
                }
            }

            $zip->addFromString('theme.json', $this->json($theme));
            $zip->addFromString('manifest.json', $this->json([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'theme_id' => $exportId,
                'files' => $files,
            ]));
        } finally {
            $zip->close();
        }

        $contents = file_get_contents($temporary);
        @unlink($temporary);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the workspace theme export.');
        }

        return $contents;
    }

    /**
     * HR: Validira sistemski ili privatni theme ZIP, atomarno zamjenjuje privatne datoteke i sprema temu u bazu.
     * EN: Validates a system or private theme ZIP, atomically replaces private files, and stores the theme in DB.
     *
     * @param array<string,mixed> $workspace
     */
    public function import(
        array $workspace,
        UploadedFileInterface $file,
        string $modePolicy,
        int $actorUserId,
    ): void {
        $this->requireZip();
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Theme export upload failed.');
        }

        $archive = $this->temporaryPath('hph-workspace-theme-import-');
        $this->storeUpload($file, $archive);
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            @unlink($archive);
            throw new RuntimeException('Theme export archive cannot be opened.');
        }

        $workspaceId = $this->workspaceId($workspace);
        $temporaryRoot = $this->config->workspaceThemesPath()
        . DIRECTORY_SEPARATOR . '.import-' . $workspaceId . '-' . bin2hex(random_bytes(6));
        try {
            $this->validateEntries($zip);
            $manifest = $this->archiveJson($zip, 'manifest.json');
            $theme = $this->archiveJson($zip, 'theme.json');
            if (
                WorkspaceValue::string($manifest['format'] ?? '') !== self::FORMAT
                || WorkspaceValue::int($manifest['version'] ?? 0) !== self::VERSION
            ) {
                throw new RuntimeException('Unsupported theme export format.');
            }

            $sourceId = trim(WorkspaceValue::string($manifest['theme_id'] ?? ''));
            if ($sourceId === '' || WorkspaceValue::string($theme['id'] ?? '') !== $sourceId) {
                throw new RuntimeException('Theme export identity does not match.');
            }

            $this->extractFiles($zip, $manifest, $temporaryRoot);
            $theme = WorkspaceValue::stringKeyArray($this->replaceImportedReferences($theme, $sourceId));
            $theme['id'] = 'workspace-' . $workspaceId;
            $theme['system'] = false;
            $themeRepository = $this->themeRepository();
            $normalized = $this->invoke($themeRepository, 'normalizePrivateTheme', [$theme, []]);
            $theme = WorkspaceValue::stringKeyArray($normalized);
            if ($theme === []) {
                throw new RuntimeException('Theme module returned an invalid imported theme.');
            }

            $targetRoot = $this->config->workspaceThemePath($workspaceId);
            $backupRoot = is_dir($targetRoot) ? $targetRoot . '.backup-' . bin2hex(random_bytes(5)) : '';
            if ($backupRoot !== '' && !rename($targetRoot, $backupRoot)) {
                throw new RuntimeException('Existing workspace theme files cannot be prepared for import.');
            }

            try {
                if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
                    throw new RuntimeException('Imported workspace theme directory cannot be created.');
                }

                if (!rename($temporaryRoot, $targetRoot)) {
                    throw new RuntimeException('Imported workspace theme files cannot be activated.');
                }

                $this->assetLibrary->readManifest($workspaceId);
                $this->repository->save(
                    $workspaceId,
                    WorkspaceThemeRepository::SELECTION_CUSTOM,
                    $sourceId,
                    $modePolicy,
                    $theme,
                    $actorUserId,
                );
                if ($backupRoot !== '') {
                    $this->deleteDirectory($backupRoot);
                }
            } catch (Throwable $exception) {
                $this->deleteDirectory($targetRoot);
                if ($backupRoot !== '') {
                    @rename($backupRoot, $targetRoot);
                }

                throw $exception;
            }
        } finally {
            $zip->close();
            @unlink($archive);
            $this->deleteDirectory($temporaryRoot);
        }
    }

    /**
     * HR: Sigurno izdvaja samo manifestom navedene datoteke i potvrđuje njihove kontrolne zbrojeve.
     * EN: Safely extracts only manifest-listed files and verifies their checksums.
     *
     * @param array<string, mixed> $manifest
     */
    private function extractFiles(ZipArchive $zip, array $manifest, string $root): void
    {
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('Theme export contains too many files.');
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('Theme export file manifest is invalid.');
            }

            $relative = is_scalar($file['path'] ?? null) ? (string)$file['path'] : '';
            $archivePath = is_scalar($file['archive_path'] ?? null) ? (string)$file['archive_path'] : '';
            $expected = is_scalar($file['sha256'] ?? null) ? strtolower((string)$file['sha256']) : '';
            if (!$this->safeRelativePath($relative) || $archivePath !== 'theme-files/' . $relative) {
                throw new RuntimeException('Theme export contains an unsafe file path.');
            }

            $contents = $zip->getFromName($archivePath);
            if (!is_string($contents) || !hash_equals($expected, hash('sha256', $contents))) {
                throw new RuntimeException('Theme export file integrity check failed.');
            }

            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Imported theme directory cannot be created.');
            }

            if (file_put_contents($target, $contents) === false) {
                throw new RuntimeException('Imported theme file cannot be stored.');
            }
        }
    }

    /**
     * HR: Odbacuje arhive s previše zapisa ili putanjama koje mogu izaći iz ciljnog direktorija.
     * EN: Rejects archives with too many entries or paths that could escape the target directory.
     */
    private function validateEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_FILES + 2) {
            throw new RuntimeException('Theme export contains too many entries.');
        }

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !$this->safeRelativePath(rtrim($name, '/'))) {
                throw new RuntimeException('Theme export contains an unsafe entry.');
            }
        }
    }

    /**
     * HR: Čita obavezni JSON zapis iz arhive i zahtijeva objekt kao korijensku vrijednost.
     * EN: Reads a required JSON archive entry and requires an object as its root value.
     *
     * @return array<string,mixed>
     */
    private function archiveJson(ZipArchive $zip, string $name): array
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf(__('Theme export is missing %s.'), $name));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Theme export contains invalid JSON.', 0, $jsonException);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Theme export JSON root must be an object.');
        }

        return WorkspaceValue::stringKeyArray($decoded);
    }

    /**
     * HR: Tokom sprema ZIP u privremenu datoteku uz ograničenje ukupne veličine.
     * EN: Streams the ZIP into a temporary file under a total-size limit.
     */
    private function storeUpload(UploadedFileInterface $file, string $path): void
    {
        $target = fopen($path, 'wb');
        if ($target === false) {
            throw new RuntimeException('Theme import temporary file cannot be created.');
        }

        $stream = $file->getStream();
        $written = 0;
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (!$stream->eof()) {
                $chunk = $stream->read(1_048_576);
                $written += strlen($chunk);
                if ($written > self::MAX_ARCHIVE_BYTES) {
                    throw new RuntimeException('Theme export archive is too large.');
                }

                if ($chunk !== '' && fwrite($target, $chunk) === false) {
                    throw new RuntimeException('Theme export upload cannot be stored.');
                }
            }
        } finally {
            fclose($target);
        }
    }

    /**
     * HR: Rekurzivno pretvara privatne runtime reference u prijenosni format sistemske teme.
     * EN: Recursively converts private runtime references into the portable system-theme format.
     */
    private function replaceRuntimeReferences(mixed $value, string $themeId): mixed
    {
        if (is_string($value) && preg_match('#^@runtime-theme-assets/(.+)$#', $value, $matches) === 1) {
            return '@theme-assets/' . $themeId . '/' . $matches[1];
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->replaceRuntimeReferences($item, $themeId);
        }

        return $value;
    }

    /**
     * HR: Rekurzivno pretvara reference uvezenog paketa u privatne runtime reference područja.
     * EN: Recursively converts imported package references into private Workspace runtime references.
     */
    private function replaceImportedReferences(mixed $value, string $themeId): mixed
    {
        if (is_string($value)) {
            $prefix = '@theme-assets/' . $themeId . '/';
            return str_starts_with($value, $prefix)
            ? '@runtime-theme-assets/' . substr($value, strlen($prefix))
            : $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->replaceImportedReferences($item, $themeId);
        }

        return $value;
    }

    /**
     * HR: Iz lokaliziranog naziva izrađuje prijenosni ID teme s rezervnim ID-em područja.
     * EN: Builds a portable theme ID from its localized label with a Workspace-ID fallback.
     *
     * @param array<string, mixed> $theme
     */
    private function exportThemeId(array $theme, int $workspaceId): string
    {
        $labels = WorkspaceValue::stringMap($theme['label'] ?? null);
        $label = $labels !== [] ? reset($labels) : '';
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $label);
        $id = strtolower(trim(is_string($slug) ? $slug : '', '-'));
        return $id !== '' ? substr($id, 0, 150) : 'workspace-theme-' . $workspaceId;
    }

    /**
     * HR: Dopušta samo relativnu, vidljivu putanju bez povratka u nadređene direktorije.
     * EN: Allows only a relative visible path without parent-directory traversal.
     */
    private function safeRelativePath(string $path): bool
    {
        return $path !== ''
        && !str_starts_with($path, '/')
        && !str_contains($path, '\\')
        && !in_array('..', explode('/', $path), true)
        && array_filter(explode('/', $path), static fn(string $segment): bool => str_starts_with($segment, '.')) === [];
    }

    /**
     * HR: Čita valjani pozitivni ID područja ili prekida prijenos teme.
     * EN: Reads a valid positive Workspace ID or stops the theme transfer.
     *
     * @param array<string, mixed> $workspace
     */
    private function workspaceId(array $workspace): int
    {
        $id = is_numeric($workspace['id'] ?? null) ? (int)$workspace['id'] : 0;
        if ($id <= 0) {
            throw new RuntimeException('Workspace ID is invalid.');
        }

        return $id;
    }

    /**
     * HR: Dohvaća kompatibilni Theme repozitorij tek kada je modul stvarno dostupan.
     * EN: Resolves a compatible Theme repository only when the module is actually available.
     */
    private function themeRepository(): object
    {
        if (!$this->container->has(self::THEME_REPOSITORY)) {
            throw new RuntimeException('Theme module repository is unavailable.');
        }

        $service = $this->container->get(self::THEME_REPOSITORY);
        if (!is_object($service) || !method_exists($service, 'normalizePrivateTheme')) {
            throw new RuntimeException('Theme module does not support private workspace themes.');
        }

        return $service;
    }

    /**
     * HR: Poziva opcionalni Theme servis bez čvrste Composer ovisnosti modula područja.
     * EN: Calls an optional Theme service without a hard Workspace-module Composer dependency.
     *
     * @param list<mixed> $arguments
     */
    private function invoke(object $service, string $method, array $arguments = []): mixed
    {
        if (!method_exists($service, $method)) {
            throw new RuntimeException(sprintf(__('Theme module service does not support: %s'), $method));
        }

        return $service->{$method}(...$arguments);
    }

    /**
     * HR: Serijalizira objekt u čitljiv UTF-8 JSON sadržaj arhive.
     * EN: Serializes an object into readable UTF-8 JSON archive content.
     *
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * HR: Izrađuje jedinstvenu privremenu putanju ili prekida operaciju prije zapisivanja.
     * EN: Creates a unique temporary path or stops the operation before writing.
     */
    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if (!is_string($path)) {
            throw new RuntimeException('Temporary theme archive cannot be created.');
        }

        return $path;
    }

    /**
     * HR: Zaustavlja uvoz ili izvoz kada PHP ZIP podrška nije instalirana.
     * EN: Stops import or export when PHP ZIP support is not installed.
     */
    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP extension is required for theme transfer.');
        }
    }

    /**
     * HR: Rekurzivno uklanja isključivo poznati privremeni ili zamjenski direktorij teme.
     * EN: Recursively removes only the known temporary or replacement theme directory.
     */
    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!($entry instanceof \SplFileInfo)) {
                continue;
            }

            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
