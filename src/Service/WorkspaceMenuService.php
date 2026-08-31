<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_object;
use function is_scalar;
use function method_exists;
use function rawurlencode;
use function rtrim;
use function strtolower;
use function trim;

/**
 * HR: Opcionalno povezuje postavke jednog područja s editorom posebnih menija.
 *     Workspace ostaje upotrebljiv bez Menu modula, a opseg spremanja uvijek određuje server.
 * EN: Optionally connects one Workspace's settings to the special-menu editor.
 *     Workspace remains usable without Menu, while the server always defines the saved scope.
 */
final readonly class WorkspaceMenuService
{
    private const MENU_PACKAGE = 'aaieduhr/heartphrame-module-menu';

    private const REPOSITORY = 'AaiEduHr\\HeartPhrameModuleMenu\\Service\\MenuConfigRepository';

    private const NAVIGATION_CATALOG = 'AaiEduHr\\HeartPhrameModuleMenu\\Service\\MenuNavigationCatalog';

    private const VIEW_RENDERER = 'AaiEduHr\\HeartPhrameModuleMenu\\Service\\MenuModuleViewRenderer';

    private const MODE_TOP = 'contexts_top';

    private const MODE_LEFT = 'contexts_left';

    /**
     * HR: Prima samo framework servise kako Menu modul ne bi postao obavezna Composer ovisnost.
     * EN: Receives only framework services so Menu does not become a required Composer dependency.
     */
    public function __construct(
        private ContainerInterface $container,
        private ComposerBridge $composerBridge,
        private WorkspaceConfig $config,
        private WorkspaceRepository $workspaceRepository,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Provjerava je li opcionalni Menu modul instaliran, uključen i dostupan u containeru.
     * EN: Checks whether the optional Menu module is installed, enabled, and available in the container.
     */
    public function isAvailable(): bool
    {
        return $this->config->isAppModuleEnabled(self::MENU_PACKAGE)
        && $this->service(self::REPOSITORY) !== null
        && $this->service(self::NAVIGATION_CATALOG) !== null
        && $this->service(self::VIEW_RENDERER) !== null;
    }

    /**
     * HR: Renderira potpuno odvojeni editor posebnog gornjeg ili lijevog menija za jedno područje.
     * EN: Renders a fully separated special top- or left-menu editor for one Workspace.
     *
     * @param array<string, mixed> $workspace
     */
    public function renderEditor(array $workspace, string $mode, string $savePath): string
    {
        $mode = $this->mode($mode);
        $repository = $this->requiredService(self::REPOSITORY);
        $catalog = $this->requiredService(self::NAVIGATION_CATALOG);
        $renderer = $this->requiredService(self::VIEW_RENDERER);
        $context = $this->contextForWorkspace($repository, $workspace, $mode);
        $locales = method_exists($repository, 'getSupportedLocales')
        ? $repository->getSupportedLocales()
        : ['hr', 'en'];
        $locales = is_array($locales) ? $locales : ['hr', 'en'];

        if (!method_exists($renderer, 'renderPartial')) {
            throw new RuntimeException('Menu module view renderer is incompatible.');
        }

        return WorkspaceValue::string($renderer->renderPartial('settings/contexts', [
            'title' => $mode === self::MODE_TOP ? 'Special top menus' : 'Special left menus',
            'activeSection' => $mode,
            'contextMode' => $mode,
            'editableSections' => method_exists($repository, 'editableSections')
                ? $repository->editableSections()
                : [],
            'contexts' => [$context],
            'blankContext' => $context,
            'selectedContextId' => WorkspaceValue::string($context['id'] ?? ''),
            'supportedLocales' => $locales,
            'localeFlagPaths' => $this->localeFlagPaths($locales),
            'navigationTargets' => method_exists($catalog, 'targets') ? $catalog->targets() : [],
            'contextTargets' => [],
            'scopeTargets' => [],
            'jsonPath' => '',
            'configurationError' => null,
            'embedded' => true,
            'lockedScope' => true,
            'savePathOverride' => $savePath,
            'workspaceSlug' => WorkspaceValue::string($workspace['slug'] ?? ''),
        ]));
    }

    /**
     * HR: Sprema samo odabranu vrstu menija i prisilno vraća context na zadano područje.
     * EN: Saves only the selected menu type and forcibly scopes the context to the supplied Workspace.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $postedContext
     */
    public function save(array $workspace, string $mode, array $postedContext): void
    {
        $mode = $this->mode($mode);
        $repository = $this->requiredService(self::REPOSITORY);
        $catalog = $this->requiredService(self::NAVIGATION_CATALOG);
        $existing = $this->contextForWorkspace($repository, $workspace, $mode);
        $locales = method_exists($repository, 'getSupportedLocales')
        ? $repository->getSupportedLocales()
        : ['hr', 'en'];
        $labels = $this->workspaceLabels($workspace, is_array($locales) ? $locales : ['hr', 'en']);
        $paths = $this->scopePaths($workspace);

        // HR: Klijent ne smije promijeniti ID, labelu ni patterne izvan područja kojim upravlja.
        // EN: The client must not change the ID, label, or patterns outside the managed Workspace.
        $postedContext = $this->lockedContext($workspace, $postedContext, $existing, $labels);

        if (!method_exists($repository, 'saveContext')) {
            throw new RuntimeException('Menu module repository is incompatible.');
        }

        $routeNames = $this->stringList(method_exists($catalog, 'routeNames') ? $catalog->routeNames() : []);
        $validationPaths = $this->stringList(
            method_exists($catalog, 'validationPaths') ? $catalog->validationPaths() : $paths,
        );
        $repository->saveContext(
            $postedContext,
            $routeNames,
            array_values(array_unique(array_merge($validationPaths, $paths))),
            $mode,
        );
    }

    /**
     * HR: Uklanja obje privatne definicije menija područja, a ostale contexte ne mijenja.
     * EN: Removes both private Workspace menu definitions without changing other contexts.
     *
     * @param array<string, mixed> $workspace
     */
    public function deleteForWorkspace(array $workspace): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $repository = $this->requiredService(self::REPOSITORY);
        $catalog = $this->requiredService(self::NAVIGATION_CATALOG);
        foreach ([self::MODE_TOP, self::MODE_LEFT] as $mode) {
            $context = $this->contextForWorkspace($repository, $workspace, $mode);
            $context['delete'] = '1';
            $context['original_id'] = WorkspaceValue::string($context['id'] ?? '');
            if ($context['original_id'] === '') {
                continue;
            }

            if (!method_exists($repository, 'saveContext')) {
                continue;
            }

            $repository->saveContext(
                $context,
                $this->stringList(method_exists($catalog, 'routeNames') ? $catalog->routeNames() : []),
                $this->stringList(
                    method_exists($catalog, 'validationPaths') ? $catalog->validationPaths() : [],
                ),
                $mode,
            );
        }
    }

    /**
     * HR: Gradi zaključani context prije zapisa; odvojeno je radi sigurnosnog regresijskog testa.
     * EN: Builds the locked context before persistence; separated for a security regression test.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $postedContext
     * @param array<string, mixed> $existing
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function lockedContext(
        array $workspace,
        array $postedContext,
        array $existing,
        array $labels,
    ): array {
        $paths = $this->scopePaths($workspace);

        $postedContext['id'] = WorkspaceValue::string($existing['id'] ?? '');
        $postedContext['original_id'] = WorkspaceValue::string($existing['id'] ?? '');
        $postedContext['label'] = $labels;
        $postedContext['route_patterns'] = '';
        $postedContext['path_patterns'] = implode("\n", $paths);

        return $postedContext;
    }

    /**
     * HR: Vraća podržanu internu oznaku zasebnog editora.
     * EN: Returns a supported internal separated-editor identifier.
     */
    public function mode(string $mode): string
    {
        if (!in_array($mode, [self::MODE_TOP, self::MODE_LEFT], true)) {
            throw new RuntimeException('Unsupported Workspace menu type.');
        }

        return $mode;
    }

    /**
     * HR: Nalazi postojeću definiciju istog tipa i opsega ili priprema novi odvojeni zapis.
     * EN: Finds an existing definition of the same type and scope or prepares a new separated record.
     *
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    private function contextForWorkspace(object $repository, array $workspace, string $mode): array
    {
        $contexts = method_exists($repository, 'contextsForEditing')
        ? $repository->contextsForEditing($mode)
        : [];
        $contexts = is_array($contexts) ? $contexts : [];

        $scopePaths = $this->scopePaths($workspace);
        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }

            $configuredPaths = $this->lines(WorkspaceValue::string($context['path_patterns'] ?? ''));
            if (array_intersect($scopePaths, $configuredPaths) !== []) {
                return WorkspaceValue::stringKeyArray($context);
            }
        }

        $context = method_exists($repository, 'emptyContextForEditing')
        ? $repository->emptyContextForEditing()
        : [];
        $context = is_array($context) ? $context : [];

        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $context['id'] = 'workspace.' . $workspaceId . ($mode === self::MODE_TOP ? '.top' : '.left');
        $locales = method_exists($repository, 'getSupportedLocales')
        ? $repository->getSupportedLocales()
        : ['hr', 'en'];
        $labels = $this->workspaceLabels($workspace, is_array($locales) ? $locales : ['hr', 'en']);
        $context['label'] = $labels;
        $context['route_patterns'] = '';
        $context['path_patterns'] = implode("\n", $scopePaths);
        $context['enabled'] = true;
        if ($mode === self::MODE_TOP) {
            $context['top_enabled'] = true;
            $context['left_enabled'] = false;
            $context['left_label'] = [];
            $context['left_items'] = [];
        } else {
            $context['top_enabled'] = false;
            $context['top_rows'] = [];
            $context['left_enabled'] = true;
            $context['left_label'] = $labels;
            $context['left_items'] = [];
        }

        return WorkspaceValue::stringKeyArray($context);
    }

    /**
     * HR: Gradi instalacijski neovisne putanje područja i svih njegovih stranica.
     * EN: Builds installation-independent paths for a Workspace and all its pages.
     *
     * @param array<string, mixed> $workspace
     * @return list<string>
     */
    private function scopePaths(array $workspace): array
    {
        $slug = rawurlencode(WorkspaceValue::string($workspace['slug'] ?? ''));
        $path = '/' . trim($this->config->rootPath(), '/') . '/' . $slug;

        return [$path, rtrim($path, '/') . '/*'];
    }

    /**
     * HR: Vraća lokalizirano ime područja kao početnu labelu za svaki jezik aplikacije.
     * EN: Returns the localized Workspace name as the initial label for each application locale.
     *
     * @param array<string, mixed> $workspace
     * @param array<mixed> $locales
     * @return array<string, string>
     */
    private function workspaceLabels(array $workspace, array $locales = ['hr', 'en']): array
    {
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $fallbackName = WorkspaceValue::string($workspace['name'] ?? $workspace['slug'] ?? 'Workspace');
        $labels = [];
        foreach ($locales as $locale) {
            $locale = is_scalar($locale) ? strtolower(trim((string)$locale)) : '';
            if ($locale !== '') {
                $localized = $this->workspaceRepository->localizeWorkspace(
                    $workspace,
                    $locale,
                    $primaryLanguage,
                );
                $labels[$locale] = WorkspaceValue::string($localized['name'] ?? '') ?: $fallbackName;
            }
        }

        return $labels !== [] ? $labels : ['hr' => $fallbackName, 'en' => $fallbackName];
    }

    /**
     * HR: Pretvara tekstualne patterne u usporediv popis redaka.
     * EN: Converts textual patterns into a comparable list of lines.
     *
     * @return list<string>
     */
    private function lines(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $value))));
    }

    /**
     * HR: Normalizira proizvoljni povrat opcionalnog Menu servisa u listu tekstova.
     * EN: Normalizes an arbitrary optional Menu-service result into a string list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $items[] = (string)$item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * HR: Gradi URL-ove zastavica bez izravne ovisnosti o Menu klasi.
     * EN: Builds flag URLs without a direct dependency on a Menu class.
     *
     * @param array<mixed> $locales
     * @return array<string, string>
     */
    private function localeFlagPaths(array $locales): array
    {
        $paths = [];
        if (!$this->urlGenerator->namedRouteExists('menu.assets.flag')) {
            return $paths;
        }

        foreach ($locales as $locale) {
            $locale = is_scalar($locale) ? strtolower(trim((string)$locale)) : '';
            if ($locale === '') {
                continue;
            }

            $language = strtolower(strtok($locale, '-_') ?: $locale);
            $paths[$locale] = $this->urlGenerator->getPathFor('menu.assets.flag', ['file' => $language . '.svg']);
        }

        return $paths;
    }

    /**
     * HR: Vraća obavezni opcionalni servis ili jasnu kompatibilnosnu grešku.
     * EN: Returns a required optional service or a clear compatibility error.
     */
    private function requiredService(string $serviceId): object
    {
        $service = $this->service($serviceId);
        if ($service === null) {
            throw new RuntimeException('Menu module is not installed, enabled, or compatible.');
        }

        return $service;
    }

    /**
     * HR: Kasno dohvaća servis Menu modula bez rušenja Workspace modula kada Menu nije instaliran.
     * EN: Resolves a Menu service late without breaking Workspace when Menu is not installed.
     */
    private function service(string $serviceId): ?object
    {
        if (
            !$this->composerBridge->isInstalled(self::MENU_PACKAGE)
            || !$this->config->isAppModuleEnabled(self::MENU_PACKAGE)
            || !class_exists($serviceId)
        ) {
            return null;
        }

        try {
            $service = $this->container->get($serviceId);
        } catch (Throwable) {
            return null;
        }

        return is_object($service) ? $service : null;
    }
}
