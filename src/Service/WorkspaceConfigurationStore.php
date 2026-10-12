<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Config\ConfigArrayMerger;
use HeartPhrame\Config\ConfigPathResolver;
use JsonException;
use RuntimeException;

/**
 * EN: Non-executable Workspace configuration shared by the GUI and manual settings.
 *
 * HR: Neizvršiva Workspace konfiguracija koju dijele GUI i ručne postavke.
 */
final readonly class WorkspaceConfigurationStore
{
    /** @var non-empty-list<non-empty-string> */
    private array $directories;

    /**
     * EN: The framework resolver determines every layer.
     *
     * HR: Resolver frameworka određuje sve slojeve.
     *
     * @param array<string,mixed> $defaults
     */
    public function __construct(string $appRoot, private array $defaults)
    {
        $this->directories = ConfigPathResolver::fromEnvironment($appRoot);
    }

    /**
     * EN: The last declared layer is the sole write target.
     *
     * HR: Zadnji deklarirani sloj je jedini cilj pisanja.
     */
    public function path(): string
    {
        return $this->paths()[count($this->directories) - 1];
    }

    /**
     * EN: Paths from base to highest layer.
     *
     * HR: Putanje od osnovnog do najvišeg sloja.
     *
     * @return non-empty-list<string>
     */
    public function paths(): array
    {
        return array_map(static fn(string $directory): string => rtrim($directory, DIRECTORY_SEPARATOR)
            . '/workspace/workspace.json', $this->directories);
    }

    /**
     * EN: Missing files use defaults; invalid files are never silently ignored.
     *
     * HR: Nedostajuća datoteka koristi zadane vrijednosti; nevaljana se ne ignorira.
     *
     * @return array<string,mixed>
     */
    public function read(): array
    {
        $values = $this->defaults;
        foreach ($this->paths() as $index => $path) {
            $this->assertDirectory($this->directories[$index]);
            $this->assertFileParents($path);
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Workspace configuration is not a readable regular file: ' . $path);
            }

            try {
                $source = file_get_contents($path);
                $object = json_decode($source === false ? '' : $source, false, 64, JSON_THROW_ON_ERROR);
                if (!$object instanceof \stdClass) {
                    throw new RuntimeException('Workspace configuration must be a JSON object: ' . $path);
                }

                $layer = json_decode($source === false ? '' : $source, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($layer)) {
                    throw new RuntimeException('Workspace configuration must be an object.');
                }

                $values = $this->map(ConfigArrayMerger::merge($values, $layer));
            } catch (JsonException $error) {
                throw new RuntimeException('Invalid Workspace JSON configuration: ' . $path, 0, $error);
            }
        }

        $this->validate($values);
        return $values;
    }

    /**
     * EN: Checking creates neither directories nor locks.
     *
     * HR: Provjera ne stvara direktorije ni lockove.
     */
    public function isWritable(): bool
    {
        try {
            $this->assertWritable();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * EN: GUI/API mutation rereads settings under the same lock used by backup.
     *
     * HR: GUI/API mutacija ponovno čita postavke pod istim lockom kao backup.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $operation
     */
    public function mutate(callable $operation): void
    {
        $this->assertWritable();
        $directory = dirname($this->path());
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the Workspace configuration directory.');
        }

        $lockPath = $this->path() . '.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Workspace configuration lock must be a regular file, not a symbolic link.');
        }

        $oldMask = umask(0007);
        try {
            $lock = fopen($lockPath, 'c+b');
        } finally {
            umask($oldMask);
        }

        if ($lock === false) {
            throw new RuntimeException('Unable to open the Workspace configuration lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock Workspace configuration.');
            }

            $values = $operation($this->read());
            $this->validate($values);
            $this->assertWritable();
            $path = $this->path();
            $metadata = @stat($path);
            $temporary = tempnam($directory, '.workspace-');
            if ($temporary === false) {
                throw new RuntimeException('Unable to stage Workspace configuration.');
            }

            try {
                $source = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                if (
                    file_put_contents($temporary, $source) !== strlen($source)
                    || !chmod($temporary, $metadata === false ? 0660 : $metadata['mode'] & 0777)
                ) {
                    throw new RuntimeException('Unable to write Workspace configuration.');
                }

                if (
                    PHP_OS_FAMILY !== 'Windows' && $metadata !== false
                    && ((fileowner($temporary) !== $metadata['uid'] && !@chown($temporary, $metadata['uid']))
                        || (filegroup($temporary) !== $metadata['gid'] && !@chgrp($temporary, $metadata['gid'])))
                ) {
                    throw new RuntimeException('Unable to preserve Workspace configuration ownership.');
                }

                if (!rename($temporary, $path)) {
                    throw new RuntimeException('Unable to publish Workspace configuration.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: Checks known setting types and semantics without discarding extensions.
     *
     * HR: Provjerava tipove i semantiku poznatih postavki bez gubitka proširenja.
     *
     * @param array<string,mixed> $values
     */
    public function validate(array $values): void
    {
        foreach (['routing', 'defaults', 'creation', 'shorts', 'backlinks', 'menu', 'homepage'] as $key) {
            if (!is_array($values[$key] ?? null)) {
                throw new RuntimeException('Invalid Workspace configuration section: ' . $key);
            }
        }

        $types = [
            'routing.root_path' => 'string', 'defaults.visibility' => 'string',
            'defaults.tree_visible' => 'bool', 'defaults.contents_visible' => 'bool',
            'shorts.limit' => 'int', 'shorts.order' => 'string',
            'shorts.display_options_visible' => 'bool', 'backlinks.refresh_seconds' => 'int',
            'menu.auto_register_top' => 'bool', 'menu.auto_register_settings' => 'bool',
            'homepage.allow_user_selection' => 'bool',
        ];
        foreach ($types as $key => $type) {
            [$section, $field] = explode('.', $key);
            $sectionValues = $values[$section];
            $value = is_array($sectionValues) ? ($sectionValues[$field] ?? null) : null;
            if (
                ($type === 'string' && !is_string($value))
                || ($type === 'bool' && !is_bool($value)) || ($type === 'int' && !is_int($value))
            ) {
                throw new RuntimeException('Invalid Workspace configuration value: ' . $key);
            }
        }

        $routing = $values['routing'];
        $defaults = $values['defaults'];
        $shorts = $values['shorts'];
        $backlinks = $values['backlinks'];
        if (
            !is_array($routing) || !is_string($routing['root_path'])
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $routing['root_path']) !== 1
            || !is_array($defaults)
            || !in_array($defaults['visibility'], ['restricted', 'authenticated', 'public'], true)
            || !is_array($shorts) || !in_array($shorts['depth'] ?? null, [1, 2, 3, 'all'], true)
            || !in_array($shorts['limit'], [5, 10, 25, 50], true)
            || !in_array($shorts['order'], ['hierarchy', 'newest', 'oldest', 'title_asc', 'title_desc'], true)
            || !is_array($backlinks) || !is_int($backlinks['refresh_seconds'])
            || $backlinks['refresh_seconds'] < 60 || $backlinks['refresh_seconds'] > 86400
        ) {
            throw new RuntimeException('Invalid Workspace routing, display, or refresh policy.');
        }

        $creation = $values['creation'];
        foreach (['users', 'groups'] as $key) {
            $ids = is_array($creation) ? ($creation[$key] ?? null) : null;
            if (!is_array($ids) || !array_is_list($ids)) {
                throw new RuntimeException('Workspace creation subjects must be lists: ' . $key);
            }

            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new RuntimeException('Workspace creation subject IDs must be positive integers.');
                }
            }
        }

        $homepage = $values['homepage'];
        foreach (['public_target', 'authenticated_target'] as $key) {
            $target = is_array($homepage) ? ($homepage[$key] ?? null) : null;
            if (
                !is_array($target) || !in_array($target['type'] ?? null, ['default', 'page', 'shorts'], true)
                || !is_int($target['node_id'] ?? null) || !is_int($target['workspace_id'] ?? null)
                || !is_bool($target['show_tree'] ?? null) || !is_bool($target['show_display_options'] ?? null)
                || $target['node_id'] < 0 || $target['workspace_id'] < 0
                || ($target['type'] === 'default' && ($target['node_id'] !== 0 || $target['workspace_id'] !== 0))
                || ($target['type'] === 'page' && ($target['node_id'] <= 0 || $target['workspace_id'] !== 0))
                || ($target['type'] === 'shorts' && ($target['workspace_id'] <= 0 || $target['node_id'] !== 0))
            ) {
                throw new RuntimeException('Invalid Workspace homepage target: ' . $key);
            }
        }
    }

    /**
     * EN: Rejects weaker-layer writes and unsafe targets.
     *
     * HR: Odbija pisanje u slabiji sloj i nesigurne ciljeve.
     */
    private function assertWritable(): void
    {
        $base = $this->directories[count($this->directories) - 1];
        $this->assertDirectory($base);
        $path = $this->path();
        $this->assertFileParents($path);
        if (is_link($path) || (file_exists($path) && (!is_file($path) || !is_readable($path) || !is_writable($path)))) {
            throw new RuntimeException('Workspace configuration is read-only: ' . $path);
        }

        $parent = is_dir(dirname($path)) ? dirname($path) : $base;
        if (!is_writable($parent) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($parent))) {
            throw new RuntimeException('Workspace configuration directory is read-only: ' . $parent);
        }
    }

    /**
     * EN: A declared directory may be a link; it must exist and be readable.
     *
     * HR: Deklarirani direktorij smije biti poveznica; mora postojati i biti čitljiv.
     */
    private function assertDirectory(string $path): void
    {
        if (!is_dir($path) || !is_readable($path) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($path))) {
            throw new RuntimeException('Workspace configuration layer is not accessible: ' . $path);
        }
    }

    /**
     * EN: The module subdirectory must not redirect writes outside the declared layer.
     *
     * HR: Poddirektorij modula ne smije preusmjeriti zapis izvan deklariranog sloja.
     */
    private function assertFileParents(string $path): void
    {
        if (is_link(dirname($path)) || (file_exists(dirname($path)) && !is_dir(dirname($path)))) {
            throw new RuntimeException('Invalid Workspace configuration parent: ' . dirname($path));
        }
    }

    /**
     * EN: Keeps only the object's string keys.
     *
     * HR: Zadržava samo string ključeve objekta.
     *
     * @param array<array-key,mixed> $values
     * @return array<string,mixed>
     */
    private function map(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Workspace configuration must have named object properties.');
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
