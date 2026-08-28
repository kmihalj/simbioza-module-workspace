<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Command;

use HeartPhrame\Config\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

final readonly class HpWorkspaceCommand
{
    private const DEFAULT_MIGRATIONS_PATH = 'database/migrations';

    private const TEMPLATE_FILE = 'resources/migrations/initial_workspace_schema.php';

    private const HOMEPAGE_TEMPLATE_FILE = 'resources/migrations/add_workspace_homepage_preferences.php';

    private const HOMEPAGE_VIEW_OPTIONS_TEMPLATE_FILE =
    'resources/migrations/add_workspace_homepage_view_options.php';

    private const THEMES_TEMPLATE_FILE = 'resources/migrations/add_workspace_themes.php';

    private const BACKLINKS_TEMPLATE_FILE = 'resources/migrations/add_workspace_backlinks.php';

    private const NODE_LABELS_TEMPLATE_FILE = 'resources/migrations/20260821183000_add_workspace_node_labels.php';

    private const NODE_PROPERTIES_TEMPLATE_FILE =
    'resources/migrations/20260821220000_add_workspace_node_properties.php';

    private const NODE_DIRECT_PERMISSIONS_TEMPLATE_FILE =
    'resources/migrations/20260826130000_add_workspace_node_direct_permissions.php';

    private const REMOVE_OWNER_TEMPLATE_FILE =
    'resources/migrations/20260826183000_remove_workspace_owner.php';

    private const METADATA_TRANSLATIONS_TEMPLATE_FILE =
    'resources/migrations/20260828100000_add_workspace_metadata_translations.php';

    /**
     * HR: Prima konfiguraciju host aplikacije za određivanje cilja migracije.
     * EN: Receives host-application configuration for resolving the migration target.
     */
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * HR: Obrađuje `workspace install` i pomoćne CLI podnaredbe.
     * EN: Handles `workspace install` and helper CLI subcommands.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function run(array $arguments = [], array $options = []): int
    {
        $subcommand = strtolower(trim((string)($arguments[0] ?? 'help')));
        $subArguments = array_values(array_slice($arguments, 1));

        return match ($subcommand) {
            'install', 'migration:install', 'install-migration', 'scaffold' =>
            $this->installMigration($subArguments, $options),
            'homepage', 'homepage:install', 'install-homepage-migration' =>
            $this->installHomepageMigration($subArguments, $options),
            'homepage-options', 'homepage-options:install', 'install-homepage-view-options-migration' =>
            $this->installHomepageViewOptionsMigration($subArguments, $options),
            'themes', 'themes:install', 'install-themes-migration' =>
            $this->installThemesMigration($subArguments, $options),
            'backlinks', 'backlinks:install', 'install-backlinks-migration' =>
            $this->installBacklinksMigration($subArguments, $options),
            'node-labels', 'node-labels:install', 'install-node-labels-migration' =>
            $this->installNodeLabelsMigration($subArguments, $options),
            'node-properties', 'node-properties:install', 'install-node-properties-migration' =>
            $this->installNodePropertiesMigration($subArguments, $options),
            'node-direct-permissions',
            'node-direct-permissions:install',
            'install-node-direct-permissions-migration' =>
            $this->installNodeDirectPermissionsMigration($subArguments, $options),
            'remove-owner', 'remove-owner:install', 'install-remove-owner-migration' =>
            $this->installRemoveOwnerMigration($subArguments, $options),
            'metadata-translations',
            'metadata-translations:install',
            'install-metadata-translations-migration' =>
            $this->installMetadataTranslationsMigration($subArguments, $options),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknownSubcommand($subcommand),
        };
    }

    /**
     * HR: Kopira jedinu početnu Workspace migraciju u host aplikaciju.
     * EN: Copies the single initial Workspace migration into the host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak Workspace migracije nije pronađen.'));
        }

        $suffix = $this->migrationSuffix($arguments, $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je početna Workspace migracija: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju naslovnice u postojeću host aplikaciju.
     * EN: Copies the homepage upgrade into an existing host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installHomepageMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::HOMEPAGE_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije naslovnice nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_homepage_preferences';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je Workspace migracija naslovnice: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju strukturiranih Shorts naslovnica u postojeću aplikaciju.
     * EN: Copies the structured Shorts-homepage upgrade into an existing application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installHomepageViewOptionsMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::HOMEPAGE_VIEW_OPTIONS_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije opcija naslovnice nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_homepage_view_options';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija opcija Workspace naslovnice: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju privatnih tema područja u postojeću host aplikaciju.
     * EN: Copies the private Workspace-theme upgrade into an existing host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installThemesMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::THEMES_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije tema područja nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_themes';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija tema područja: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju izvedenog backlink indeksa u postojeću host aplikaciju.
     * EN: Copies the derived-backlink-index upgrade into an existing host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installBacklinksMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::BACKLINKS_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije backlinkova nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_backlinks';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija Workspace backlinkova: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju oznaka stranica u postojeću host aplikaciju.
     * EN: Copies the page-label upgrade into an existing host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installNodeLabelsMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::NODE_LABELS_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije oznaka stranica nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_node_labels';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija oznaka Workspace stranica: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju strukturiranih svojstava stranica u postojeću aplikaciju.
     * EN: Copies the structured-page-properties upgrade into an existing application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installNodePropertiesMigration(array $arguments = [], array $options = []): int
    {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::NODE_PROPERTIES_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije svojstava stranica nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_node_properties';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija svojstava Workspace stranica: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju izravnih prava stranica u postojeću aplikaciju.
     * EN: Copies the direct-page-permission upgrade into an existing application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installNodeDirectPermissionsMigration(
        array $arguments = [],
        array $options = [],
    ): int {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::NODE_DIRECT_PERMISSIONS_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije izravnih prava stranica nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_node_direct_permissions';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija izravnih prava Workspace stranica: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju koja uklanja zastarjeli stupac vlasnika područja.
     * EN: Copies the upgrade that removes the obsolete Workspace-owner column.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installRemoveOwnerMigration(
        array $arguments = [],
        array $options = [],
    ): int {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::REMOVE_OWNER_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije uklanjanja vlasnika područja nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'remove_workspace_owner';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija uklanjanja vlasnika područja: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Kopira nadogradnju za višejezične nazive područja, opise i naslove stranica.
     * EN: Copies the upgrade for multilingual workspace names, descriptions, and page titles.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installMetadataTranslationsMigration(
        array $arguments = [],
        array $options = [],
    ): int {
        $targetDirectory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::METADATA_TRANSLATIONS_TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije višejezičnih podataka područja nije pronađen.'));
        }

        $options['name'] = $this->option($options, ['name'])
        ?? trim((string)($arguments[0] ?? ''))
        ?: 'add_workspace_metadata_translations';
        $suffix = $this->migrationSuffix([], $options);
        $target = rtrim($targetDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . date('YmdHis')
        . '_'
        . $suffix
        . '.php';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati Workspace migraciju.'));
        }

        $this->write(__('Kreirana je migracija višejezičnih podataka područja: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate:up`.'));

        return 0;
    }

    /**
     * HR: Ispisuje kratke upute za CLI helper.
     * EN: Prints brief CLI helper usage.
     */
    public function help(): int
    {
        $this->write('hph workspace <install|help>');
        $this->write('  vendor/bin/hph workspace:install-migration');
        $this->write('  vendor/bin/hph workspace:install-homepage-migration');
        $this->write('  vendor/bin/hph workspace:install-homepage-view-options-migration');
        $this->write('  vendor/bin/hph workspace:install-themes-migration');
        $this->write('  vendor/bin/hph workspace:install-backlinks-migration');
        $this->write('  vendor/bin/hph workspace:install-node-labels-migration');
        $this->write('  vendor/bin/hph workspace:install-node-properties-migration');
        $this->write('  vendor/bin/hph workspace:install-node-direct-permissions-migration');
        $this->write('  vendor/bin/hph workspace:install-remove-owner-migration');
        $this->write('  vendor/bin/hph workspace:install-metadata-translations-migration');

        return 0;
    }

    /**
     * HR: Vraća grešku za nepoznatu podnaredbu.
     * EN: Returns an error for an unknown subcommand.
     */
    private function unknownSubcommand(string $subcommand): int
    {
        $this->write(sprintf(__('Nepoznata Workspace podnaredba: %s'), $subcommand));

        return 1;
    }

    /**
     * HR: Razrješava ciljni direktorij iz opcije ili app roota.
     * EN: Resolves the target directory from an option or the application root.
     *
     * @param array<string, mixed> $options
     */
    private function targetDirectory(array $options): string
    {
        $path = $this->option($options, ['path', 'p']);
        if ($path === null) {
            return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . self::DEFAULT_MIGRATIONS_PATH;
        }

        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * HR: Normalizira naziv generirane migracije.
     * EN: Normalizes the generated migration name.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    private function migrationSuffix(array $arguments, array $options): string
    {
        $name = $this->option($options, ['name']) ?? trim((string)($arguments[0] ?? ''));
        $name = $name !== '' ? $name : 'install_workspace_module_schema';
        $name = trim((string)preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)), '_');
        if ($name === '') {
            throw new InvalidArgumentException(__('Naziv migracije ne smije biti prazan.'));
        }

        return $name;
    }

    /**
     * HR: Čita prvu nepraznu skalarnu CLI opciju.
     * EN: Reads the first non-empty scalar CLI option.
     *
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private function option(array $options, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return null;
    }

    /**
     * HR: Ispisuje jednu CLI poruku.
     * EN: Prints one CLI message.
     */
    private function write(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
