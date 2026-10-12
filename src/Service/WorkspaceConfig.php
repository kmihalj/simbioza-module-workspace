<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use HeartPhrame\Config\ConfigInterface;

use function array_values;
use function in_array;
use function is_array;
use function is_scalar;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strtolower;
use function trim;

final class WorkspaceConfig
{
    /**
     * @var array<string, mixed>
     */
    private array $workspaceConfig;

    private readonly WorkspaceConfigurationStore $configurationStore;

    /**
     * EN: Merges module defaults and framework-resolved JSON layers.
     *
     * HR: Spaja zadane postavke i JSON slojeve koje razrješava framework.
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly string $moduleRoot,
        private readonly ?Database $database = null,
    ) {
        $defaults = WorkspaceValue::stringKeyArray(require $moduleRoot . '/config/workspace.php');
        $this->configurationStore = new WorkspaceConfigurationStore($config->getAppRootDir(), $defaults);
        $this->refresh();
    }

    /**
     * EN: Returns the root URL segment under which workspaces define their own slugs.
     *
     * HR: Vraća korijenski URL segment unutar kojeg područja određuju vlastite slugove.
     */
    public function rootPath(): string
    {
        $routing = $this->section('routing');
        $path = is_scalar($routing['root_path'] ?? null) ? strtolower(trim((string)$routing['root_path'])) : '';
        $path = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $path), '-');

        return $path !== '' ? $path : 'workspace';
    }

    /**
     * EN: Returns the default visibility for a new workspace.
     *
     * HR: Vraća zadanu vidljivost novog područja.
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
     * EN: Determines whether the page tree is initially expanded.
     *
     * HR: Određuje je li stablo stranica početno otvoreno.
     */
    public function treeVisibleByDefault(): bool
    {
        $defaults = $this->section('defaults');

        return (bool)($defaults['tree_visible'] ?? true);
    }

    /**
     * EN: Determines whether the page outline is initially visible when neither
     *     the Workspace nor the page supplies an override.
     *
     * HR: Određuje je li sadržaj stranice početno prikazan kada područje i
     *     stranica ne zadaju vlastitu vrijednost.
     */
    public function contentsVisibleByDefault(): bool
    {
        $defaults = $this->section('defaults');

        return (bool)($defaults['contents_visible'] ?? false);
    }

    /**
     * EN: Resolves initial page-tree visibility from the Workspace setting and system fallback.
     *
     * HR: Razrješava početni prikaz stabla iz postavke područja i sistemskog fallbacka.
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
     * EN: Resolves outline visibility in page, Workspace, then system order.
     *
     * HR: Razrješava sadržaj redom stranica, područje pa sistemska postavka.
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
     * EN: Converts an inherited, shown, or hidden policy into an effective boolean.
     *
     * HR: Pretvara nasljednu, prikazanu ili skrivenu vrijednost u efektivni boolean.
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
     * EN: Returns active Auth users allowed to create Workspaces.
     *
     * HR: Vraća aktivne Auth korisnike koji smiju kreirati područja.
     *
     * @return list<int>
     */
    public function creatorUserIds(): array
    {
        return $this->creationSubjectIds('users');
    }

    /**
     * EN: Returns Auth groups whose members may create Workspaces.
     *
     * HR: Vraća Auth grupe čiji članovi smiju kreirati područja.
     *
     * @return list<int>
     */
    public function creatorGroupIds(): array
    {
        return $this->creationSubjectIds('groups');
    }

    /**
     * EN: Normalizes persisted Auth identifiers for authorized creators.
     *
     * HR: Normalizira trajno spremljene Auth identifikatore ovlaštenih kreatora.
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
     * EN: Returns the default maximum tree depth on the Shorts page.
     *
     * HR: Vraća zadanu najveću dubinu stabla na stranici Sažetaka.
     */
    public function shortsDefaultDepth(): int|string
    {
        $shorts = $this->section('shorts');
        $value = is_scalar($shorts['depth'] ?? null)
        ? strtolower(trim((string)$shorts['depth']))
        : '';
        if ($value === 'all') {
            return 'all';
        }

        $depth = WorkspaceValue::int($value !== '' ? $value : 2);

        return in_array($depth, [1, 2, 3], true) ? $depth : 2;
    }

    /**
     * EN: Returns the default article count on the Shorts page.
     *
     * HR: Vraća zadani broj članaka na stranici Sažetaka.
     */
    public function shortsDefaultLimit(): int
    {
        $shorts = $this->section('shorts');
        $limit = WorkspaceValue::int($shorts['limit'] ?? 10);

        return in_array($limit, [5, 10, 25, 50], true) ? $limit : 10;
    }

    /**
     * EN: Returns the default article order on the Shorts page.
     *
     * HR: Vraća zadani redoslijed članaka na stranici Sažetaka.
     */
    public function shortsDefaultOrder(): string
    {
        $shorts = $this->section('shorts');
        $order = is_scalar($shorts['order'] ?? null)
        ? strtolower(trim((string)$shorts['order']))
        : '';

        return in_array($order, ['hierarchy', 'newest', 'oldest', 'title_asc', 'title_desc'], true)
        ? $order
        : 'newest';
    }

    /**
     * EN: Determines whether the Shorts display options are initially expanded.
     *
     * HR: Određuje jesu li opcije prikaza Sažetaka početno otvorene.
     */
    public function shortsDisplayOptionsVisibleByDefault(): bool
    {
        $shorts = $this->section('shorts');

        return (bool)($shorts['display_options_visible'] ?? false);
    }

    /**
     * EN: Returns the site's default content locale for safe published-page fallback.
     *
     * HR: Vraća zadani jezik sadržaja sitea za siguran fallback objavljenih stranica.
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
     * EN: Returns supported metadata locales with the primary locale first.
     *
     * HR: Vraća podržane jezike metapodataka, s primarnim jezikom na početku.
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
     * EN: Builds the public-content locale order: requested locale, its base
     *     form, the application default, English, and the remaining supported locales.
     *
     * HR: Gradi redoslijed jezika javnog sadržaja: traženi jezik, njegov
     *     osnovni oblik, zadani jezik aplikacije, engleski i ostali podržani jezici.
     *
     * @return list<string>
     */
    public function contentLanguagePriority(
        string $requestedLanguage,
        ?string $defaultLanguage = null,
    ): array {
        $defaultLanguage ??= $this->siteDefaultLanguage();
        $candidates = [
            $requestedLanguage,
            (string)preg_replace('/[-_].*$/', '', $requestedLanguage),
            $defaultLanguage,
            (string)preg_replace('/[-_].*$/', '', $defaultLanguage),
            'en',
            ...$this->supportedLanguages(),
        ];
        $languages = [];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if (
                preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $candidate) === 1
                && !in_array($candidate, $languages, true)
            ) {
                $languages[] = $candidate;
            }
        }

        return $languages;
    }

    /**
     * EN: Returns the application timezone for localized time rendering.
     *
     * HR: Vraća vremensku zonu aplikacije za lokalizirani prikaz vremena.
     */
    public function timezone(): string
    {
        $timezone = trim($this->config->getAsString('app.timezone', 'Europe/Zagreb') ?? '');

        return $timezone !== '' ? $timezone : 'Europe/Zagreb';
    }

    /**
     * EN: Returns the maximum interval between safety checks of the derived
     *     backlink index.
     *
     * HR: Vraća najdulje razdoblje između sigurnosnih provjera izvedenog
     *     indeksa povratnih poveznica.
     */
    public function backlinkRefreshSeconds(): int
    {
        $backlinks = $this->section('backlinks');
        $seconds = WorkspaceValue::int($backlinks['refresh_seconds'] ?? 3600);

        return max(60, min(86400, $seconds));
    }

    /**
     * EN: Returns whether the module should automatically add its main menu item.
     *
     * HR: Vraća treba li modul automatski dodati glavnu menu stavku.
     */
    public function shouldAutoRegisterTopMenu(): bool
    {
        $menu = $this->section('menu');

        return (bool)($menu['auto_register_top'] ?? true);
    }

    /**
     * EN: Returns whether the module should automatically add administration settings.
     *
     * HR: Vraća treba li modul automatski dodati administratorske postavke.
     */
    public function shouldAutoRegisterSettingsMenu(): bool
    {
        $menu = $this->section('menu');

        return (bool)($menu['auto_register_settings'] ?? true);
    }

    /**
     * EN: Returns the sole global homepage policy from configuration.
     *
     * HR: Vraća jedinu globalnu politiku naslovnice iz konfiguracije.
     *
     * @return array<string,mixed>
     */
    public function homepageSettings(): array
    {
        $homepage = $this->section('homepage');
        $public = WorkspaceValue::stringKeyArray($homepage['public_target'] ?? []);
        $authenticated = WorkspaceValue::stringKeyArray($homepage['authenticated_target'] ?? []);
        return [
            'public_node_id' => $public['node_id'] ?? 0,
            'authenticated_node_id' => $authenticated['node_id'] ?? 0,
            'public_target' => $public,
            'authenticated_target' => $authenticated,
            'allow_user_selection' => $homepage['allow_user_selection'] ?? true,
        ];
    }

    /**
     * EN: Exposes the same safe store for the GUI, API, and optional backup.
     *
     * HR: Izlaže isti sigurni spremnik za GUI, API i opcionalni backup.
     */
    public function configurationStore(): WorkspaceConfigurationStore
    {
        return $this->configurationStore;
    }

    /**
     * EN: Reloads settings after mutation without writing to the Editor.
     *
     * HR: Ponovno učitava postavke nakon mutacije; bez pisanja u Editor.
     */
    public function refresh(): void
    {
        $this->workspaceConfig = $this->configurationStore->read();
    }

    /**
     * EN: Checks whether another module is enabled in the host application.
     *
     * HR: Provjerava je li drugi modul uključen u host aplikaciji.
     */
    public function isAppModuleEnabled(string $packageName): bool
    {
        $enabledModules = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return in_array($packageName, $enabledModules, true);
    }

    /**
     * EN: Returns the absolute path of the application settings file.
     *
     * HR: Vraća apsolutnu putanju aplikacijske datoteke postavki.
     */
    public function settingsFilePath(): string
    {
        return $this->configurationStore->path();
    }

    /**
     * EN: Returns the module root directory.
     *
     * HR: Vraća root direktorij modula.
     */
    public function moduleRoot(): string
    {
        return $this->moduleRoot;
    }

    /**
     * EN: Returns the persistent private-workspace-theme directory inside the application's `data` tree.
     *
     * HR: Vraća trajni direktorij privatnih tema područja unutar aplikacijskog `data` stabla.
     */
    public function workspaceThemesPath(): string
    {
        return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'workspaces';
    }

    /**
     * EN: Returns the safe theme directory for one workspace.
     *
     * HR: Vraća siguran direktorij teme jednog područja.
     */
    public function workspaceThemePath(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace ID is invalid.');
        }

        return $this->workspaceThemesPath() . DIRECTORY_SEPARATOR . $this->workspaceDirectoryName($workspaceId)
        . DIRECTORY_SEPARATOR . 'theme';
    }

    /**
     * EN: Shares the readable owner directory with HTML, attachments and menus.
     *
     * HR: Dijeli čitljivi vlasnički direktorij s HTML-om, privitcima i menijima.
     */
    public function workspaceDirectoryName(int $workspaceId): string
    {
        return $this->database instanceof \AaiEduHr\HeartPhrameModuleOrm\Database\Database
        ? (new WorkspaceDataDirectory($this->database))->workspace($workspaceId)
        : (string)$workspaceId;
    }

    /**
     * EN: Returns and creates, when necessary, the private theme image-asset directory.
     *
     * HR: Vraća i po potrebi kreira direktorij slikovnih asseta privatne teme.
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
     * EN: Reads one configuration section as a string-key array.
     *
     * HR: Čita jednu konfiguracijsku sekciju kao string-key polje.
     *
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->workspaceConfig[$key] ?? [];

        return WorkspaceValue::stringKeyArray($section);
    }
}
