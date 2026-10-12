<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Command;

use HeartPhrame\Config\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

final readonly class HpWorkspaceCommand
{
    private const DEFAULT_MIGRATIONS_PATH = 'database/migrations';

    private const TEMPLATE_FILE = 'resources/migrations/initial_workspace_schema.php';

    /**
     * EN: Receives host-application configuration for resolving the migration target.
     *
     * HR: Prima konfiguraciju host aplikacije za određivanje cilja migracije.
     */
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * EN: Handles `workspace install` and helper CLI subcommands.
     *
     * HR: Obrađuje `workspace install` i pomoćne CLI podnaredbe.
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
            'help', '--help', '-h' => $this->help(),
            default => $this->unknownSubcommand($subcommand),
        };
    }

    /**
     * EN: Copies the single initial Workspace migration into the host application.
     *
     * HR: Kopira jedinu početnu Workspace migraciju u host aplikaciju.
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
     * EN: Prints brief CLI helper usage.
     *
     * HR: Ispisuje kratke upute za CLI helper.
     */
    public function help(): int
    {
        $this->write('hph workspace <install|help>');
        $this->write('  vendor/bin/hph workspace:install-migration');

        return 0;
    }

    /**
     * EN: Returns an error for an unknown subcommand.
     *
     * HR: Vraća grešku za nepoznatu podnaredbu.
     */
    private function unknownSubcommand(string $subcommand): int
    {
        $this->write(sprintf(__('Nepoznata Workspace podnaredba: %s'), $subcommand));

        return 1;
    }

    /**
     * EN: Resolves the target directory from an option or the application root.
     *
     * HR: Razrješava ciljni direktorij iz opcije ili app roota.
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
     * EN: Normalizes the generated migration name.
     *
     * HR: Normalizira naziv generirane migracije.
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
     * EN: Reads the first non-empty scalar CLI option.
     *
     * HR: Čita prvu nepraznu skalarnu CLI opciju.
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
     * EN: Prints one CLI message.
     *
     * HR: Ispisuje jednu CLI poruku.
     */
    private function write(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
