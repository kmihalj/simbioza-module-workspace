<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Routing\UrlGenerator;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Povezuje područje s opcionalnim Theme modulom i čuva svaku prilagodbu izvan sistemskih JSON tema.
 * EN: Connects a workspace to the optional Theme module and keeps every customization outside system JSON themes.
 */
final readonly class WorkspaceThemeService
{
    private const THEME_PACKAGE = 'aaieduhr/heartphrame-module-theme';

    private const THEME_REPOSITORY = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeConfigRepository';

    private const THEME_ASSET_LIBRARY = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeAssetLibrary';

    private const THEME_RENDERER = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeRenderer';

    private const THEME_VIEW_RENDERER = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeModuleViewRenderer';

    /**
     * HR: Prima spremište područja i opcionalne Theme servise bez obavezne ovisnosti o Theme modulu.
     * EN: Receives Workspace storage and optional Theme services without requiring the Theme module.
     */
    public function __construct(
        private ContainerInterface $container,
        private ComposerBridge $composerBridge,
        private WorkspaceThemeRepository $repository,
        private WorkspaceThemeAssetLibrary $assetLibrary,
        private WorkspaceConfig $config,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Vraća može li instalacija koristiti teme područja.
     * EN: Returns whether the installation can use workspace themes.
     */
    public function isAvailable(): bool
    {
        return $this->composerBridge->isInstalled(self::THEME_PACKAGE)
        && $this->config->isAppModuleEnabled(self::THEME_PACKAGE)
        && $this->service(self::THEME_REPOSITORY) !== null;
    }

    /**
     * HR: Vraća postavku područja zajedno s trenutno razriješenom temom.
     * EN: Returns workspace settings together with the currently resolved theme.
     *
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    public function state(array $workspace): array
    {
        $workspaceId = $this->workspaceId($workspace);
        $stored = $this->repository->forWorkspace($workspaceId);
        $themeRepository = $this->requireThemeRepository();
        $selection = WorkspaceValue::string($stored['selection_type'] ?? '');
        $sourceId = WorkspaceValue::string($stored['source_theme_id'] ?? '');
        $theme = is_array($stored['theme'] ?? null)
        ? WorkspaceValue::stringKeyArray($stored['theme'])
        : null;

        if ($selection === WorkspaceThemeRepository::SELECTION_CUSTOM && is_array($theme)) {
            $resolved = $theme;
        } elseif ($selection === WorkspaceThemeRepository::SELECTION_SYSTEM && $sourceId !== '') {
            $resolved = $this->repositoryThemeById($themeRepository, $sourceId);
        } else {
            $selection = WorkspaceThemeRepository::SELECTION_DEFAULT;
            $sourceId = '';
            $resolved = $this->repositoryActiveTheme($themeRepository);
        }

        return [
            ...$stored,
            'selection_type' => $selection,
            'source_theme_id' => $sourceId,
            'theme' => $theme,
            'resolved_theme' => $resolved,
        ];
    }

    /**
     * HR: Aktivira odabranu sistemsku ili privatnu temu samo za renderiranje trenutačnog područja.
     * EN: Activates the selected system or private theme only while rendering the current workspace.
     *
     * @param array<string, mixed> $workspace
     */
    public function activate(array $workspace): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $state = $this->state($workspace);
        $repository = $this->requireThemeRepository();
        if ($state['selection_type'] === WorkspaceThemeRepository::SELECTION_DEFAULT) {
            /*
             * HR: Servis je singleton unutar zahtjeva pa povratak na zadanu temu mora
             *     ukloniti ranije postavljen override, primjerice u integracijskom testu.
             * EN: The service is request-scoped singleton, so returning to the default
             *     theme must clear a previously set override, for example in an integration test.
             */
            $this->invoke($repository, 'clearRuntimeTheme');
            return;
        }

        $workspaceId = $this->workspaceId($workspace);
        $workspaceSlug = is_scalar($workspace['slug'] ?? null) ? (string)$workspace['slug'] : '';
        $mode = WorkspaceValue::string($state['mode_policy'] ?? 'auto');
        $assetBaseUrl = rtrim($this->urlGenerator->getBasePath(), '/')
        . '/workspaces/theme/assets/' . rawurlencode($workspaceSlug);
        $version = substr(sha1(
            json_encode($state['resolved_theme']) . '|'
            . WorkspaceValue::string($state['updated_at'] ?? '') . '|'
            . WorkspaceValue::int(@filemtime(
                $this->config->workspaceThemePath($workspaceId) . '/theme-assets.json',
            )),
        ), 0, 12);
        $this->invoke($repository, 'activateRuntimeTheme', [
            $state['resolved_theme'],
            $mode,
            $assetBaseUrl,
            $this->config->workspaceThemeAssetsPath($workspaceId),
            $version,
        ]);
    }

    /**
     * HR: Sprema nasljeđivanje ili izričiti izbor sistemske teme bez kopiranja njezine konfiguracije.
     * EN: Saves inheritance or an explicit system-theme selection without copying its configuration.
     *
     * @param array<string, mixed> $workspace
     */
    public function saveSelection(array $workspace, string $themeId, string $modePolicy, int $actorUserId): void
    {
        $workspaceId = $this->workspaceId($workspace);
        if ($themeId === '__default__' || $themeId === '') {
            $this->repository->delete($workspaceId);
            return;
        }

        $state = $this->repository->forWorkspace($workspaceId);
        $custom = is_array($state['theme'] ?? null)
        ? WorkspaceValue::stringKeyArray($state['theme'])
        : null;
        if ($custom !== null && WorkspaceValue::string($custom['id'] ?? '') === $themeId) {
            $this->repository->save(
                $workspaceId,
                WorkspaceThemeRepository::SELECTION_CUSTOM,
                WorkspaceValue::string($state['source_theme_id'] ?? ''),
                $modePolicy,
                $custom,
                $actorUserId,
            );
            return;
        }

        $this->exactSystemTheme($themeId);
        $this->repository->save(
            $workspaceId,
            WorkspaceThemeRepository::SELECTION_SYSTEM,
            $themeId,
            $modePolicy,
            null,
            $actorUserId,
        );
    }

    /**
     * HR: Normalizira GUI podatke i sprema privatnu kopiju; izvorna sistemska tema ostaje nepromijenjena.
     * EN: Normalizes GUI data and stores a private copy; the source system theme remains unchanged.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $themeData
     */
    public function saveTheme(array $workspace, array $themeData, string $modePolicy, int $actorUserId): void
    {
        $workspaceId = $this->workspaceId($workspace);
        $state = $this->state($workspace);
        $isExistingCustom = $state['selection_type'] === WorkspaceThemeRepository::SELECTION_CUSTOM
        && is_array($state['theme']);
        $source = WorkspaceValue::stringKeyArray(
            $isExistingCustom ? $state['theme'] : $state['resolved_theme'],
        );
        $sourceThemeId = $isExistingCustom
        ? WorkspaceValue::string($state['source_theme_id'] ?? '')
        : WorkspaceValue::string($source['id'] ?? '');

        $themeData['id'] = 'workspace-' . $workspaceId;
        if (!$isExistingCustom) {
            $themeData['label'] = $this->privateLabels(
                WorkspaceValue::stringMap($themeData['label'] ?? null),
                WorkspaceValue::stringMap($source['label'] ?? null),
                is_scalar($workspace['name'] ?? null) ? (string)$workspace['name'] : 'Workspace',
            );
        }

        $themeRepository = $this->requireThemeRepository();
        $theme = $this->repositoryNormalizePrivateTheme($themeRepository, $themeData, $source);
        if (!$isExistingCustom) {
            $theme = $this->copyReferencedSystemAssets($workspaceId, $theme, $themeRepository);
        }

        $this->repository->save(
            $workspaceId,
            WorkspaceThemeRepository::SELECTION_CUSTOM,
            $sourceThemeId,
            $modePolicy,
            $theme,
            $actorUserId,
        );
    }

    /**
     * HR: Uploadom asseta po potrebi prvo stvara privatnu kopiju aktivne teme područja.
     * EN: Uploading an asset creates a private copy of the active workspace theme when needed.
     *
     * @param array<string, mixed> $workspace
     */
    public function uploadAsset(
        array $workspace,
        UploadedFileInterface $file,
        string $role,
        int $actorUserId,
    ): void {
        $state = $this->ensureCustomTheme($workspace, $actorUserId);
        $this->assetLibrary->upload($this->workspaceId($workspace), $file, $role);
        $this->repository->save(
            $this->workspaceId($workspace),
            WorkspaceThemeRepository::SELECTION_CUSTOM,
            WorkspaceValue::string($state['source_theme_id'] ?? ''),
            WorkspaceValue::string($state['mode_policy'] ?? 'auto'),
            WorkspaceValue::stringKeyArray($state['theme'] ?? null),
            $actorUserId,
        );
    }

    /**
     * HR: Briše nekorišteni asset samo iz privatne biblioteke područja.
     * EN: Deletes an unused asset only from the workspace's private library.
     *
     * @param array<string, mixed> $workspace
     */
    public function deleteAsset(array $workspace, string $file, int $actorUserId): void
    {
        $state = $this->state($workspace);
        if ($state['selection_type'] !== WorkspaceThemeRepository::SELECTION_CUSTOM || !is_array($state['theme'])) {
            throw new InvalidArgumentException('System theme files are read only in workspace settings.');
        }

        $theme = WorkspaceValue::stringKeyArray($state['theme']);
        $this->assetLibrary->delete($this->workspaceId($workspace), $file, $theme);
        $this->repository->save(
            $this->workspaceId($workspace),
            WorkspaceThemeRepository::SELECTION_CUSTOM,
            WorkspaceValue::string($state['source_theme_id'] ?? ''),
            WorkspaceValue::string($state['mode_policy'] ?? 'auto'),
            $theme,
            $actorUserId,
        );
    }

    /**
     * HR: Gradi podatke za isti editor koji koristi globalna administracija tema.
     * EN: Builds data for the same editor used by global theme administration.
     *
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    public function editorData(array $workspace, bool $canExport, string $openSection = ''): array
    {
        $themeRepository = $this->requireThemeRepository();
        $state = $this->state($workspace);
        $themes = $this->repositoryThemes($themeRepository);
        $selected = WorkspaceValue::stringKeyArray($state['resolved_theme'] ?? null);
        $activeThemeId = $state['selection_type'] === WorkspaceThemeRepository::SELECTION_DEFAULT
        ? '__default__'
        : WorkspaceValue::string($selected['id'] ?? '');
        if ($state['selection_type'] === WorkspaceThemeRepository::SELECTION_CUSTOM) {
            $themes[] = $selected;
        }

        $workspaceId = $this->workspaceId($workspace);
        $workspaceSlug = is_scalar($workspace['slug'] ?? null) ? (string)$workspace['slug'] : '';
        $savePath = $this->urlGenerator->getPathFor('workspace.theme.save');
        $settingsPath = $this->urlGenerator->getPathFor('workspace.theme', [], ['workspace' => $workspaceSlug]);
        $isCustom = $state['selection_type'] === WorkspaceThemeRepository::SELECTION_CUSTOM;
        $assets = $isCustom
        ? $this->assetLibrary->assets($workspaceId, $selected)
        : $this->systemAssets(WorkspaceValue::string($selected['id'] ?? ''));

        return [
            'title' => 'Workspace theme',
            'themes' => $themes,
            'settings' => [
                'enabled' => true,
                'active_theme' => $activeThemeId,
                'mode_policy' => $state['selection_type'] === WorkspaceThemeRepository::SELECTION_DEFAULT
                    ? $this->repositoryModePolicy($themeRepository)
                    : WorkspaceValue::string($state['mode_policy'] ?? 'auto'),
            ],
            'componentSettings' => is_array($selected['components'] ?? null) ? $selected['components'] : [],
            'themeAssets' => $assets,
            'gradientPresets' => $this->repositoryRows($themeRepository, 'gradientPresets'),
            'selectedTheme' => $selected,
            'colorFields' => $this->repositoryRows($themeRepository, 'colorFields'),
            'colorFieldGroups' => $this->repositoryRows($themeRepository, 'colorFieldGroups'),
            'fontOptions' => $this->repositoryRows($themeRepository, 'fontOptions'),
            'menuFontSizeOptions' => $this->repositoryRows($themeRepository, 'menuFontSizeOptions'),
            'menuFontWeightOptions' => $this->repositoryRows($themeRepository, 'menuFontWeightOptions'),
            'menuFontStyleOptions' => $this->repositoryRows($themeRepository, 'menuFontStyleOptions'),
            'supportedLocales' => $this->repositorySupportedLocales($themeRepository),
            'localeFlagPaths' => $this->localeFlagPaths(),
            'themeRenderer' => $this->requireService(self::THEME_RENDERER),
            'themesJsonPath' => '',
            'settingsJsonPath' => '',
            'configurationError' => null,
            'openSection' => $openSection,
            'themeEditorContext' => 'workspace',
            'themeEditorShowSidebar' => false,
            'themeEditorSavePath' => $savePath,
            'themeEditorSettingsPath' => $settingsPath,
            'themeEditorHiddenFields' => ['workspace' => $workspaceSlug],
            'themeEditorAllowExport' => $canExport && $isCustom,
            'themeEditorExportPath' => $canExport && $isCustom
                ? $this->urlGenerator->getPathFor('workspace.theme.export', [], ['workspace' => $workspaceSlug])
                : '',
            'themeEditorAssetsReadOnly' => !$isCustom,
            'themeEditorPreviewAssetResolver' => function (string $reference) use ($workspaceSlug): string {
                if (preg_match('#^@runtime-theme-assets/([a-z0-9][a-z0-9._-]*)$#i', $reference, $matches) === 1) {
                    return rtrim($this->urlGenerator->getBasePath(), '/')
                    . '/workspaces/theme/assets/' . rawurlencode($workspaceSlug) . '/' . rawurlencode($matches[1]);
                }

                return $reference;
            },
        ];
    }

    /**
     * HR: Renderira zajedničku Theme formu s kontekstom i putanjama privatne teme područja.
     * EN: Renders the shared Theme form with the Workspace private-theme context and routes.
     *
     * @param array<string, mixed> $data
     */
    public function renderEditor(array $data): ResponseInterface
    {
        $renderer = $this->requireService(self::THEME_VIEW_RENDERER);
        $response = $this->invoke($renderer, 'render', ['settings/index', $data]);
        if (!($response instanceof ResponseInterface)) {
            throw new RuntimeException('Theme editor did not return an HTTP response.');
        }

        return $response;
    }

    /**
     * HR: Po potrebi stvara privatnu kopiju razriješene teme prije izmjene datoteka.
     * EN: Creates a private copy of the resolved theme when needed before changing files.
     *
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    private function ensureCustomTheme(array $workspace, int $actorUserId): array
    {
        $state = $this->state($workspace);
        if ($state['selection_type'] === WorkspaceThemeRepository::SELECTION_CUSTOM && is_array($state['theme'])) {
            return $state;
        }

        $theme = WorkspaceValue::stringKeyArray($state['resolved_theme'] ?? null);
        $sourceThemeId = WorkspaceValue::string($theme['id'] ?? '');
        $labels = WorkspaceValue::stringMap($theme['label'] ?? null);
        $theme['label'] = $this->privateLabels(
            $labels,
            $labels,
            is_scalar($workspace['name'] ?? null) ? (string)$workspace['name'] : 'Workspace',
        );
        $theme['id'] = 'workspace-' . $this->workspaceId($workspace);
        $theme['system'] = false;
        $themeRepository = $this->requireThemeRepository();
        $theme = $this->copyReferencedSystemAssets($this->workspaceId($workspace), $theme, $themeRepository);
        $modePolicy = $state['selection_type'] === WorkspaceThemeRepository::SELECTION_DEFAULT
        ? $this->repositoryModePolicy($themeRepository)
        : WorkspaceValue::string($state['mode_policy'] ?? 'auto');
        $this->repository->save(
            $this->workspaceId($workspace),
            WorkspaceThemeRepository::SELECTION_CUSTOM,
            WorkspaceValue::string($state['source_theme_id'] ?: $sourceThemeId),
            $modePolicy,
            $theme,
            $actorUserId,
        );

        return $this->state($workspace);
    }

    /**
     * HR: Kopira sve sistemske slike koje privatna tema referencira i prepisuje njihove reference.
     * EN: Copies every system image referenced by the private theme and rewrites its references.
     *
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    private function copyReferencedSystemAssets(int $workspaceId, array $theme, object $themeRepository): array
    {
        $copied = [];
        $sourceTheme = $theme;
        $copy = function (mixed $value) use (
            &$copy,
            &$copied,
            $workspaceId,
            $themeRepository,
            $sourceTheme,
        ): mixed {
            if (
                is_string($value) && preg_match(
                    '#^@theme-assets/[a-z0-9][a-z0-9._-]*/([a-z0-9][a-z0-9._-]*)$#i',
                    $value,
                    $matches,
                ) === 1
            ) {
                if (isset($copied[$value])) {
                    return $copied[$value];
                }

                $path = $this->invoke($themeRepository, 'themeAssetPath', [$value]);
                if (!is_string($path) || !is_file($path)) {
                    return '';
                }

                $copied[$value] = $this->assetLibrary->copyFile(
                    $workspaceId,
                    $path,
                    $this->roleForReference($sourceTheme, $value),
                    pathinfo($matches[1], PATHINFO_FILENAME),
                );
                return $copied[$value];
            }

            if (!is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $item) {
                $value[$key] = $copy($item);
            }

            return $value;
        };

        $copiedTheme = $copy($theme);
        return is_array($copiedTheme) ? WorkspaceValue::stringKeyArray($copiedTheme) : $theme;
    }

    /**
     * HR: Određuje pripada li referenca logotipu, hero vizualu ili ostalim datotekama.
     * EN: Determines whether a reference belongs to a logo, hero visual, or another asset.
     *
     * @param array<string, mixed> $theme
     */
    private function roleForReference(array $theme, string $reference): string
    {
        $components = is_array($theme['components'] ?? null) ? $theme['components'] : [];
        $header = is_array($components['header'] ?? null) ? $components['header'] : [];
        if (($header['logo_src_light'] ?? null) === $reference || ($header['logo_src_dark'] ?? null) === $reference) {
            return 'logo';
        }

        $hero = is_array($components['hero'] ?? null) ? $components['hero'] : [];
        if (($hero['visual_src_light'] ?? null) === $reference || ($hero['visual_src_dark'] ?? null) === $reference) {
            return 'hero';
        }

        return 'other';
    }

    /**
     * HR: Čuva korisnički promijenjen naziv ili zadanom nazivu dodaje naziv područja.
     * EN: Keeps a user-changed label or appends the Workspace name to the default label.
     *
     * @param array<string, string> $posted
     * @param array<string, string> $source
     * @return array<string, string>
     */
    private function privateLabels(array $posted, array $source, string $workspaceName): array
    {
        $labels = [];
        $changed = false;
        foreach ($posted as $locale => $label) {
            $labels[$locale] = trim($label);
            if ($labels[$locale] !== trim($source[$locale] ?? '')) {
                $changed = true;
            }
        }

        if ($changed) {
            return $labels;
        }

        foreach ($source as $locale => $label) {
            if (trim($label) !== '') {
                $labels[$locale] = trim($label) . ' – ' . $workspaceName;
            }
        }

        return $labels !== [] ? $labels : ['hr' => 'Tema – ' . $workspaceName, 'en' => 'Theme – ' . $workspaceName];
    }

    /**
     * HR: Čita datoteke odabrane sistemske teme kao read-only biblioteku editora.
     * EN: Reads the selected system theme files as a read-only editor library.
     *
     * @return list<array<string,mixed>>
     */
    private function systemAssets(string $themeId): array
    {
        $library = $this->service(self::THEME_ASSET_LIBRARY);
        if ($library === null || !method_exists($library, 'assets')) {
            return [];
        }

        try {
            return WorkspaceValue::rows($this->invoke($library, 'assets', [$themeId]));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * HR: Gradi URL-ove zastava za sve jezike koje podržava Theme modul.
     * EN: Builds flag URLs for every locale supported by the Theme module.
     *
     * @return array<string,string>
     */
    private function localeFlagPaths(): array
    {
        $paths = [];
        foreach ($this->repositorySupportedLocales($this->requireThemeRepository()) as $locale) {
            $language = strtolower(strtok($locale, '-_') ?: $locale);
            $paths[$locale] = $this->urlGenerator->namedRouteExists('theme.assets.flag')
            ? $this->urlGenerator->getPathFor('theme.assets.flag', ['file' => $language . '.svg'])
            : '';
        }

        return $paths;
    }

    /**
     * HR: Zahtijeva točan postojeći ID sistemske teme bez tihog fallbacka.
     * EN: Requires an exact existing system-theme ID without a silent fallback.
     *
     * @return array<string,mixed>
     */
    private function exactSystemTheme(string $themeId): array
    {
        foreach ($this->repositoryThemes($this->requireThemeRepository()) as $theme) {
            if (WorkspaceValue::string($theme['id'] ?? '') === $themeId) {
                return $theme;
            }
        }

        throw new InvalidArgumentException('Selected system theme does not exist.');
    }

    /**
     * HR: Čita valjani pozitivni ID iz retka područja.
     * EN: Reads a valid positive ID from the Workspace row.
     *
     * @param array<string, mixed> $workspace
     */
    private function workspaceId(array $workspace): int
    {
        $id = is_numeric($workspace['id'] ?? null) ? (int)$workspace['id'] : 0;
        if ($id <= 0) {
            throw new InvalidArgumentException('Workspace ID is invalid.');
        }

        return $id;
    }

    /**
     * HR: Zahtijeva glavni repozitorij opcionalnog Theme modula.
     * EN: Requires the optional Theme module's main repository.
     */
    private function requireThemeRepository(): object
    {
        return $this->requireService(self::THEME_REPOSITORY);
    }

    /**
     * HR: Dohvaća opcionalni servis ili vraća jasnu grešku integracije.
     * EN: Resolves an optional service or raises a clear integration error.
     */
    private function requireService(string $id): object
    {
        $service = $this->service($id);
        if ($service === null) {
            throw new RuntimeException(sprintf(__('Theme module service is unavailable: %s'), $id));
        }

        return $service;
    }

    /**
     * HR: Sigurno pokušava dohvatiti objekt iz spremnika bez rušenja kada Theme nije instaliran.
     * EN: Safely attempts to resolve a container object without failing when Theme is absent.
     */
    private function service(string $id): ?object
    {
        try {
            if (!$this->container->has($id)) {
                return null;
            }

            $service = $this->container->get($id);
            return is_object($service) ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * HR: Poziva opcionalni Theme servis bez uvođenja čvrste Composer ovisnosti.
     * EN: Calls an optional Theme service without introducing a hard Composer dependency.
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
     * HR: Vraća normaliziranu temu po ID-u kroz dinamički Theme repozitorij.
     * EN: Returns a normalized theme by ID through the dynamic Theme repository.
     *
     * @return array<string,mixed>
     */
    private function repositoryThemeById(object $repository, string $themeId): array
    {
        return WorkspaceValue::stringKeyArray($this->invoke($repository, 'themeById', [$themeId]));
    }

    /**
     * HR: Vraća trenutačno aktivnu sistemsku temu kroz dinamički Theme repozitorij.
     * EN: Returns the currently active system theme through the dynamic Theme repository.
     *
     * @return array<string,mixed>
     */
    private function repositoryActiveTheme(object $repository): array
    {
        return WorkspaceValue::stringKeyArray($this->invoke($repository, 'activeTheme'));
    }

    /**
     * HR: Vraća sve sistemske teme vidljive u izboru područja.
     * EN: Returns every system theme visible in the Workspace selector.
     *
     * @return list<array<string,mixed>>
     */
    private function repositoryThemes(object $repository): array
    {
        return WorkspaceValue::rows($this->invoke($repository, 'themes'));
    }

    /**
     * HR: Normalizira tablični rezultat pomoćne metode Theme repozitorija.
     * EN: Normalizes a row-list result from a Theme repository helper method.
     *
     * @return list<array<string,mixed>>
     */
    private function repositoryRows(object $repository, string $method): array
    {
        return WorkspaceValue::rows($this->invoke($repository, $method));
    }

    /**
     * HR: Vraća jedinstveni popis valjanih jezika koje Theme repozitorij podržava.
     * EN: Returns the unique list of valid locales supported by the Theme repository.
     *
     * @return list<string>
     */
    private function repositorySupportedLocales(object $repository): array
    {
        $locales = [];
        $value = $this->invoke($repository, 'getSupportedLocales');
        if (!is_array($value)) {
            return $locales;
        }

        foreach ($value as $locale) {
            if (is_scalar($locale) && trim((string)$locale) !== '') {
                $locales[] = (string)$locale;
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * HR: Čita i ograničava sistemsku politiku prikaza na tri podržane vrijednosti.
     * EN: Reads and restricts the system display policy to the three supported values.
     */
    private function repositoryModePolicy(object $repository): string
    {
        $mode = WorkspaceValue::string($this->invoke($repository, 'modePolicy'));
        return in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';
    }

    /**
     * HR: Delegira potpunu normalizaciju privatne teme izvornom Theme modulu.
     * EN: Delegates complete private-theme normalization to the source Theme module.
     *
     * @param array<string,mixed> $theme
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private function repositoryNormalizePrivateTheme(object $repository, array $theme, array $existing): array
    {
        $normalized = WorkspaceValue::stringKeyArray(
            $this->invoke($repository, 'normalizePrivateTheme', [$theme, $existing]),
        );
        if ($normalized === []) {
            throw new RuntimeException('Theme module returned an invalid private theme.');
        }

        return $normalized;
    }
}
