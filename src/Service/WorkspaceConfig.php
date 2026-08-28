<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use HeartPhrame\Config\ConfigInterface;

use function array_replace_recursive;
use function array_values;
use function in_array;
use function is_array;
use function is_file;
use function is_scalar;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strtolower;
use function trim;

final readonly class WorkspaceConfig
{
    /**
     * @var array<string, mixed>
     */
    private array $workspaceConfig;

    /**
     * HR: Spaja zadane postavke modula i aplikacijski `config/workspace.php`.
     * EN: Merges module defaults and the application `config/workspace.php`.
     */
    public function __construct(
        private ConfigInterface $config,
        private string $moduleRoot,
    ) {
        $this->workspaceConfig = $this->loadWorkspaceConfig();
    }

    /**
     * HR: Vraća korijenski URL segment unutar kojeg područja određuju vlastite slugove.
     * EN: Returns the root URL segment under which workspaces define their own slugs.
     */
    public function rootPath(): string
    {
        $routing = $this->section('routing');
        $path = is_scalar($routing['root_path'] ?? null) ? strtolower(trim((string)$routing['root_path'])) : '';
        $path = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $path), '-');

        return $path !== '' ? $path : 'workspace';
    }

    /**
     * HR: Vraća zadanu vidljivost novog područja.
     * EN: Returns the default visibility for a new workspace.
     */
    public function defaultVisibility(): string
    {
        $defaults = $this->section('defaults');
        $visibility = is_scalar($defaults['visibility'] ?? null)
        ? strtolower(trim((string)$defaults['visibility']))
        : '';

        return in_array($visibility, ['restricted', 'authenticated', 'public'], true)
        ? $visibility
        : 'restricted';
    }

    /**
     * HR: Određuje je li stablo stranica početno otvoreno.
     * EN: Determines whether the page tree is initially expanded.
     */
    public function treeVisibleByDefault(): bool
    {
        $defaults = $this->section('defaults');

        return (bool)($defaults['tree_visible'] ?? true);
    }

    /**
     * HR: Određuje je li sadržaj stranice početno prikazan kada područje i
     *     stranica ne zadaju vlastitu vrijednost.
     * EN: Determines whether the page outline is initially visible when neither
     *     the Workspace nor the page supplies an override.
     */
    public function contentsVisibleByDefault(): bool
    {
        $defaults = $this->section('defaults');

        return (bool)($defaults['contents_visible'] ?? false);
    }

    /**
     * HR: Razrješava početni prikaz stabla iz postavke područja i sistemskog fallbacka.
     * EN: Resolves initial page-tree visibility from the Workspace setting and system fallback.
     *
     * @param array<string, mixed> $workspace
     */
    public function treeVisibleForWorkspace(array $workspace): bool
    {
        return $this->resolveDisplayPolicy(
            $workspace['tree_visibility'] ?? 'inherit',
            $this->treeVisibleByDefault(),
        );
    }

    /**
     * HR: Razrješava sadržaj redom stranica, područje pa sistemska postavka.
     * EN: Resolves outline visibility in page, Workspace, then system order.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $node
     */
    public function contentsVisibleForPage(array $workspace, ?array $node): bool
    {
        $workspaceVisible = $this->resolveDisplayPolicy(
            $workspace['contents_visibility'] ?? 'inherit',
            $this->contentsVisibleByDefault(),
        );

        return $this->resolveDisplayPolicy(
            is_array($node) ? ($node['contents_visibility'] ?? 'inherit') : 'inherit',
            $workspaceVisible,
        );
    }

    /**
     * HR: Pretvara nasljednu, prikazanu ili skrivenu vrijednost u efektivni boolean.
     * EN: Converts an inherited, shown, or hidden policy into an effective boolean.
     */
    private function resolveDisplayPolicy(mixed $value, bool $fallback): bool
    {
        $policy = is_scalar($value) ? strtolower(trim((string)$value)) : 'inherit';

        return match ($policy) {
            'shown' => true,
            'hidden' => false,
            default => $fallback,
        };
    }

    /**
     * HR: Vraća aktivne Auth korisnike koji smiju kreirati područja.
     * EN: Returns active Auth users allowed to create Workspaces.
     *
     * @return list<int>
     */
    public function creatorUserIds(): array
    {
        return $this->creationSubjectIds('users');
    }

    /**
     * HR: Vraća Auth grupe čiji članovi smiju kreirati područja.
     * EN: Returns Auth groups whose members may create Workspaces.
     *
     * @return list<int>
     */
    public function creatorGroupIds(): array
    {
        return $this->creationSubjectIds('groups');
    }

    /**
     * HR: Normalizira trajno spremljene Auth identifikatore ovlaštenih kreatora.
     * EN: Normalizes persisted Auth identifiers for authorized creators.
     *
     * @return list<int>
     */
    private function creationSubjectIds(string $key): array
    {
        $creation = $this->section('creation');
        $values = is_array($creation[$key] ?? null) ? $creation[$key] : [];
        $ids = [];
        foreach ($values as $value) {
            $id = WorkspaceValue::int($value);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * HR: Vraća zadanu najveću dubinu stabla na stranici Sažetaka.
     * EN: Returns the default maximum tree depth on the Shorts page.
     */
    public function shortsDefaultDepth(): int
    {
        $shorts = $this->section('shorts');
        $depth = WorkspaceValue::int($shorts['depth'] ?? 2);

        return in_array($depth, [1, 2, 3], true) ? $depth : 2;
    }

    /**
     * HR: Vraća zadani broj članaka na stranici Sažetaka.
     * EN: Returns the default article count on the Shorts page.
     */
    public function shortsDefaultLimit(): int
    {
        $shorts = $this->section('shorts');
        $limit = WorkspaceValue::int($shorts['limit'] ?? 10);

        return in_array($limit, [5, 10, 25, 50], true) ? $limit : 10;
    }

    /**
     * HR: Vraća zadani redoslijed članaka na stranici Sažetaka.
     * EN: Returns the default article order on the Shorts page.
     */
    public function shortsDefaultOrder(): string
    {
        $shorts = $this->section('shorts');
        $order = is_scalar($shorts['order'] ?? null)
        ? strtolower(trim((string)$shorts['order']))
        : '';

        return in_array($order, ['hierarchy', 'newest', 'oldest'], true)
        ? $order
        : 'newest';
    }

    /**
     * HR: Određuje jesu li opcije prikaza Sažetaka početno otvorene.
     * EN: Determines whether the Shorts display options are initially expanded.
     */
    public function shortsDisplayOptionsVisibleByDefault(): bool
    {
        $shorts = $this->section('shorts');

        return (bool)($shorts['display_options_visible'] ?? false);
    }

    /**
     * HR: Vraća zadani jezik sadržaja sitea za siguran fallback objavljenih stranica.
     * EN: Returns the site's default content locale for safe published-page fallback.
     */
    public function siteDefaultLanguage(): string
    {
        $language = strtolower(trim(
            $this->config->getAsString('localization.locale')
                ?? $this->config->getAsString('app.localization.locale', 'hr')
                ?? 'hr',
        ));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1 ? $language : 'hr';
    }

    /**
     * HR: Vraća podržane jezike metapodataka, s primarnim jezikom na početku.
     * EN: Returns supported metadata locales with the primary locale first.
     *
     * @return list<string>
     */
    public function supportedLanguages(): array
    {
        $primary = $this->siteDefaultLanguage();
        $configured = $this->config->getAsArrayWithValuesAsNonEmptyStrings(
            'localization.supported_locales',
        ) ?? $this->config->getAsArrayWithValuesAsNonEmptyStrings(
            'app.localization.supported_locales',
        ) ?? [];
        $languages = [$primary];
        foreach ($configured as $language) {
            $language = strtolower(trim($language));
            if (
                preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1
                && !in_array($language, $languages, true)
            ) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * HR: Vraća vremensku zonu aplikacije za lokalizirani prikaz vremena.
     * EN: Returns the application timezone for localized time rendering.
     */
    public function timezone(): string
    {
        $timezone = trim($this->config->getAsString('app.timezone', 'Europe/Zagreb') ?? '');

        return $timezone !== '' ? $timezone : 'Europe/Zagreb';
    }

    /**
     * HR: Vraća najdulje razdoblje između sigurnosnih provjera izvedenog
     *     indeksa povratnih poveznica.
     * EN: Returns the maximum interval between safety checks of the derived
     *     backlink index.
     */
    public function backlinkRefreshSeconds(): int
    {
        $backlinks = $this->section('backlinks');
        $seconds = WorkspaceValue::int($backlinks['refresh_seconds'] ?? 3600);

        return max(60, min(86400, $seconds));
    }

    /**
     * HR: Vraća treba li modul automatski dodati glavnu menu stavku.
     * EN: Returns whether the module should automatically add its main menu item.
     */
    public function shouldAutoRegisterTopMenu(): bool
    {
        $menu = $this->section('menu');

        return (bool)($menu['auto_register_top'] ?? true);
    }

    /**
     * HR: Vraća treba li modul automatski dodati administratorske postavke.
     * EN: Returns whether the module should automatically add administration settings.
     */
    public function shouldAutoRegisterSettingsMenu(): bool
    {
        $menu = $this->section('menu');

        return (bool)($menu['auto_register_settings'] ?? true);
    }

    /**
     * HR: Provjerava je li drugi modul uključen u host aplikaciji.
     * EN: Checks whether another module is enabled in the host application.
     */
    public function isAppModuleEnabled(string $packageName): bool
    {
        $enabledModules = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return in_array($packageName, $enabledModules, true);
    }

    /**
     * HR: Vraća apsolutnu putanju aplikacijske datoteke postavki.
     * EN: Returns the absolute path of the application settings file.
     */
    public function settingsFilePath(): string
    {
        return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'config'
        . DIRECTORY_SEPARATOR
        . 'workspace.php';
    }

    /**
     * HR: Vraća root direktorij modula.
     * EN: Returns the module root directory.
     */
    public function moduleRoot(): string
    {
        return $this->moduleRoot;
    }

    /**
     * HR: Vraća trajni direktorij privatnih tema područja unutar aplikacijskog `data` stabla.
     * EN: Returns the persistent private-workspace-theme directory inside the application's `data` tree.
     */
    public function workspaceThemesPath(): string
    {
        $path = rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'workspaces'
        . DIRECTORY_SEPARATOR . 'themes';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Workspace theme directory cannot be created.');
        }

        return $path;
    }

    /**
     * HR: Vraća siguran direktorij teme jednog područja.
     * EN: Returns the safe theme directory for one workspace.
     */
    public function workspaceThemePath(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace ID is invalid.');
        }

        return $this->workspaceThemesPath() . DIRECTORY_SEPARATOR . $workspaceId;
    }

    /**
     * HR: Vraća i po potrebi kreira direktorij slikovnih asseta privatne teme.
     * EN: Returns and creates, when necessary, the private theme image-asset directory.
     */
    public function workspaceThemeAssetsPath(int $workspaceId): string
    {
        $path = $this->workspaceThemePath($workspaceId) . DIRECTORY_SEPARATOR . 'assets';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Workspace theme asset directory cannot be created.');
        }

        return $path;
    }

    /**
     * HR: Čita jednu konfiguracijsku sekciju kao string-key polje.
     * EN: Reads one configuration section as a string-key array.
     *
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->workspaceConfig[$key] ?? [];

        return WorkspaceValue::stringKeyArray($section);
    }

    /**
     * HR: Učitava zadane postavke i opcionalni override host aplikacije.
     * EN: Loads defaults and the optional host-application override.
     *
     * @return array<string, mixed>
     */
    private function loadWorkspaceConfig(): array
    {
        $defaults = WorkspaceValue::stringKeyArray(
            require $this->moduleRoot . '/config/workspace.php',
        );

        $appConfigPath = $this->settingsFilePath();
        if (!is_file($appConfigPath)) {
            return $defaults;
        }

        $override = WorkspaceValue::stringKeyArray(require $appConfigPath);

        return WorkspaceValue::stringKeyArray(array_replace_recursive($defaults, $override));
    }
}
