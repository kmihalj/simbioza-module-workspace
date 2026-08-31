<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Localization\TranslatorInterface;
use Psr\Container\ContainerInterface;
use Throwable;

use function array_keys;
use function array_values;
use function base64_encode;
use function basename;
use function file_get_contents;
use function hash;
use function html_entity_decode;
use function htmlspecialchars;
use function is_array;
use function is_file;
use function is_object;
use function is_scalar;
use function is_string;
use function method_exists;
use function parse_url;
use function pathinfo;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

/**
 * HR: Povezuje Workspace izvoz s opcionalnim Theme modulom bez tvrde Composer ovisnosti.
 *     Vraća stvarni aktivni CSS, light/dark assete i strukturne renderere teme.
 * EN: Connects Workspace export to the optional Theme module without a hard Composer dependency.
 *     It provides the actual active CSS, light/dark assets, and structural theme renderers.
 */
final readonly class WorkspaceThemeBridge
{
    private const THEME_PACKAGE = 'aaieduhr/heartphrame-module-theme';

    private const REPOSITORY = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeConfigRepository';

    private const CSS_RENDERER = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeRenderer';

    private const LAYOUT_RENDERER = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeLayoutRenderer';

    /**
     * HR: Prima container i prevoditelj za kasno razrješavanje teme i lokaliziranih elemenata.
     * EN: Receives the container and translator for late theme and localized-element resolution.
     */
    public function __construct(
        private ContainerInterface $container,
        private ComposerBridge $composerBridge,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * HR: Priprema CSS i samo odabrane logo/hero datoteke aktivne teme.
     * EN: Prepares CSS and only the selected logo/hero files of the active theme.
     *
     * @return array{
     *     css:string,
     *     files:array<string,string>,
     *     sources:array<string,string>,
     *     mode:string,
     *     navigation_placement:string,
     *     header_enabled:bool,
     *     enabled:bool
     * }
     */
    public function bundle(): array
    {
        $fallback = [
            'css' => '',
            'files' => [],
            'sources' => [],
            'mode' => 'auto',
            'navigation_placement' => 'standalone',
            'header_enabled' => false,
            'enabled' => false,
        ];
        $repository = $this->service(self::REPOSITORY);
        $cssRenderer = $this->service(self::CSS_RENDERER);
        $layoutRenderer = $this->service(self::LAYOUT_RENDERER);
        if ($repository === null || $cssRenderer === null || $layoutRenderer === null) {
            return $fallback;
        }

        if (!$this->rendererEnabled($layoutRenderer)) {
            return $fallback;
        }

        try {
            $components = method_exists($repository, 'componentSettings')
            ? $repository->componentSettings()
            : [];
            $components = WorkspaceValue::stringKeyArray($components);
            $references = $this->assetReferences($components);
            $files = [];
            $sources = [];
            foreach ($references as $reference) {
                if (!method_exists($repository, 'themeAssetPath')) {
                    continue;
                }

                $sourcePath = $repository->themeAssetPath($reference);
                if (!is_string($sourcePath)) {
                    continue;
                }

                if (!is_file($sourcePath)) {
                    continue;
                }

                $content = file_get_contents($sourcePath);
                if (!is_string($content)) {
                    continue;
                }

                $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                $base = (string)preg_replace('/[^a-z0-9._-]+/i', '-', basename($sourcePath));
                $target = 'assets/theme/' . substr(hash('sha256', $content), 0, 12)
                . '-' . ($base !== '' ? $base : 'asset' . ($extension !== '' ? '.' . $extension : ''));
                $files[$target] = $content;
                /*
                 * HR: Zaglavlje i hero ugrađuju odabrane assete kao data URI. Tako
                 *     glavna offline stranica ostaje vizualno potpuna i kada se ZIP
                 *     raspakira na drugom operacijskom sustavu ili otvori preko
                 *     `file://`. Izvorna datoteka svejedno ostaje u ZIP-u radi
                 *     transparentnosti i provjere integriteta paketa.
                 * EN: The header and hero embed selected assets as data URIs. This
                 *     keeps the main offline page visually complete when the ZIP is
                 *     extracted on another operating system or opened through
                 *     `file://`. The original file remains in the ZIP for package
                 *     transparency and integrity checks.
                 */
                $sources[$reference] = $this->dataUri($extension, $content);
            }

            $css = method_exists($cssRenderer, 'renderPortableCss')
            ? $cssRenderer->renderPortableCss()
            : '';
            $mode = method_exists($repository, 'modePolicy') ? $repository->modePolicy() : 'auto';
            $placement = method_exists($layoutRenderer, 'navigationPlacement')
            ? $layoutRenderer->navigationPlacement()
            : 'standalone';
            $headerEnabled = method_exists($layoutRenderer, 'headerEnabled') && (bool)$layoutRenderer->headerEnabled();

            return [
                'css' => is_string($css) ? $css : '',
                'files' => $files,
                'sources' => $sources,
                'mode' => is_scalar($mode) ? trim((string)$mode) : 'auto',
                'navigation_placement' => is_scalar($placement)
                    ? trim((string)$placement)
                    : 'standalone',
                'header_enabled' => $headerEnabled,
                'enabled' => true,
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * HR: Renderira aktivno zaglavlje teme s offline kontrolama jezika i načina prikaza.
     * EN: Renders the active theme header with offline language and display-mode controls.
     *
     * @param array<string, string> $assetSources
     */
    public function renderHeader(
        string $locale,
        string $languageControl,
        string $themeControl,
        array $assetSources,
        string $homeHref,
    ): string {
        $renderer = $this->service(self::LAYOUT_RENDERER);
        if (
            $renderer === null
            || !$this->rendererEnabled($renderer)
            || !method_exists($renderer, 'renderHeader')
        ) {
            return '';
        }

        $previousLocale = $this->translator->getLocale();
        try {
            $this->translator->setLocale($locale);
            $html = $renderer->renderHeader([
                'language' => $languageControl,
                'account' => $themeControl,
            ], $assetSources);
        } catch (Throwable) {
            return '';
        } finally {
            $this->translator->setLocale($previousLocale);
        }

        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $html = (string)preg_replace(
            '/(<a\b[^>]*\bhref=")[^"]*("[^>]*>)/i',
            '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $homeHref) . '$2',
            $html,
            1,
        );

        $html = $this->withRequiredOfflineControls($html, $languageControl, $themeControl);

        return $this->embedRenderedThemeAssets($html, $assetSources);
    }

    /**
     * HR: Renderira hero aktivne teme s lokalnim assetima ZIP paketa.
     * EN: Renders the active theme hero with local ZIP-package assets.
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $assetSources
     */
    public function renderHero(
        string $locale,
        array $context,
        string $navigationHtml,
        array $assetSources,
    ): string {
        $renderer = $this->service(self::LAYOUT_RENDERER);
        if (
            $renderer === null
            || !$this->rendererEnabled($renderer)
            || !method_exists($renderer, 'renderHero')
        ) {
            return '';
        }

        $previousLocale = $this->translator->getLocale();
        try {
            $this->translator->setLocale($locale);
            $html = $renderer->renderHero($context, $navigationHtml, $assetSources);
        } catch (Throwable) {
            return '';
        } finally {
            $this->translator->setLocale($previousLocale);
        }

        return is_string($html) ? $this->embedRenderedThemeAssets($html, $assetSources) : '';
    }

    /**
     * HR: Vraća iste klase glavnog sadržaja i stagea koje koristi web aplikacija.
     *     Time offline područje zadržava širinu, gustoću i preklapanje s heroom.
     * EN: Returns the same main-content and stage classes used by the web app.
     *     This preserves width, density, and hero overlap in the offline Workspace.
     *
     * @return array{classes:string,stage_classes:string}
     */
    public function mainContentPresentation(bool $hasHero, bool $hasHeroText): array
    {
        $fallback = [
            'classes' => 'container-fluid hph-container-wide px-4 hph-main-content',
            'stage_classes' => 'hph-page-stage',
        ];
        $renderer = $this->service(self::LAYOUT_RENDERER);
        if (
            $renderer === null
            || !$this->rendererEnabled($renderer)
            || !method_exists($renderer, 'mainContentPresentation')
        ) {
            return $fallback;
        }

        try {
            $presentation = $renderer->mainContentPresentation(
                false,
                $hasHero,
                $hasHeroText,
                'integrated',
            );
        } catch (Throwable) {
            return $fallback;
        }

        if (!is_array($presentation)) {
            return $fallback;
        }

        $classes = is_scalar($presentation['classes'] ?? null)
        ? trim((string)$presentation['classes'])
        : '';
        $stageClasses = is_scalar($presentation['stage_classes'] ?? null)
        ? trim((string)$presentation['stage_classes'])
        : '';

        return [
            'classes' => $classes !== '' ? $classes : $fallback['classes'],
            'stage_classes' => $stageClasses !== '' ? $stageClasses : $fallback['stage_classes'],
        ];
    }

    /**
     * HR: Isključeni Theme modul tretira jednako kao nedostupan modul kako
     *     offline paket ne bi potajno primijenio neaktivnu temu.
     * EN: Treats a disabled Theme module like an unavailable module so the
     *     offline package cannot silently apply an inactive theme.
     */
    private function rendererEnabled(object $renderer): bool
    {
        if (!method_exists($renderer, 'isEnabled')) {
            return true;
        }

        try {
            return (bool)$renderer->isEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * HR: Pretvara lokalni slikovni asset u samostalni URI siguran za offline HTML.
     * EN: Converts a local image asset into a self-contained URI safe for offline HTML.
     */
    private function dataUri(string $extension, string $content): string
    {
        $mime = match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    /**
     * HR: Zamjenjuje i već razriješene web rute logo/hero asseta njihovim
     *     samostalnim offline izvorom. Ovo je namjerno drugi sigurnosni sloj:
     *     starija ili dekorirana verzija Theme renderera može prije bridgea
     *     pretvoriti `@theme-assets/...` u `/theme/assets/library/...`.
     * EN: Replaces already-resolved logo/hero web routes with their standalone
     *     offline source. This deliberately acts as a second safety layer: an
     *     older or decorated Theme renderer may turn `@theme-assets/...` into
     *     `/theme/assets/library/...` before the bridge sees the final HTML.
     *
     * @param array<string, string> $assetSources
     */
    private function embedRenderedThemeAssets(string $html, array $assetSources): string
    {
        if ($html === '' || $assetSources === []) {
            return $html;
        }

        $embedded = preg_replace_callback(
            '/\bsrc="([^"]*)"/i',
            function (array $matches) use ($assetSources): string {
                $renderedSource = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $offlineSource = $assetSources[$renderedSource]
                ?? $this->offlineSourceForResolvedPath($renderedSource, $assetSources);
                if (!is_string($offlineSource) || $offlineSource === '') {
                    return $matches[0];
                }

                return 'src="' . htmlspecialchars(
                    $offlineSource,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                ) . '"';
            },
            $html,
        );

        return is_string($embedded) ? $embedded : $html;
    }

    /**
     * HR: Povezuje razriješenu aplikacijsku URL putanju s izvornom prijenosnom
     *     referencom, neovisno o URL prefiksu pod kojim je aplikacija instalirana.
     * EN: Maps a resolved application URL path back to its portable source
     *     reference, independently of the URL prefix used by the installation.
     *
     * @param array<string, string> $assetSources
     */
    private function offlineSourceForResolvedPath(string $renderedSource, array $assetSources): ?string
    {
        $path = parse_url($renderedSource, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach ($assetSources as $reference => $offlineSource) {
            $suffix = $this->resolvedPathSuffix($reference);
            if ($suffix !== '' && str_ends_with($path, $suffix)) {
                return $offlineSource;
            }
        }

        return null;
    }

    /**
     * HR: Pretvara podržanu prijenosnu referencu u stabilni završetak javne rute.
     * EN: Converts a supported portable reference into a stable public-route suffix.
     */
    private function resolvedPathSuffix(string $reference): string
    {
        if (str_starts_with($reference, '@theme-assets/')) {
            return '/theme/assets/library/' . substr($reference, 14);
        }

        if (str_starts_with($reference, '@theme/')) {
            return '/theme/assets/visual/' . substr($reference, 7);
        }

        if (str_starts_with($reference, '@app/')) {
            return '/' . substr($reference, 5);
        }

        return '';
    }

    /**
     * HR: Izdvaja jedinstvene reference koje aktivna tema stvarno koristi u zaglavlju i herou.
     * EN: Extracts unique references actually used by the active theme header and hero.
     *
     * @param array<string, mixed> $components
     * @return list<string>
     */
    private function assetReferences(array $components): array
    {
        $references = [];
        $header = WorkspaceValue::stringKeyArray($components['header'] ?? null);
        $items = is_array($header['items'] ?? null) ? array_values($header['items']) : [];
        foreach ($items as $item) {
            $item = WorkspaceValue::stringKeyArray($item);
            if (WorkspaceValue::string($item['type'] ?? '') !== 'logo') {
                continue;
            }

            foreach (['src_light', 'src_dark'] as $key) {
                $reference = trim(WorkspaceValue::string($item[$key] ?? ''));
                if ($reference !== '') {
                    $references[$reference] = true;
                }
            }
        }

        $hero = WorkspaceValue::stringKeyArray($components['hero'] ?? null);
        foreach (['visual_src_light', 'visual_src_dark'] as $key) {
            $reference = trim(WorkspaceValue::string($hero[$key] ?? ''));
            if ($reference !== '') {
                $references[$reference] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * HR: Jezik i light/dark izbor ostaju dostupni i kada aktivna tema nema
     *     odgovarajuće kontrolne stavke u vlastitom sastavljivom zaglavlju.
     * EN: Language and light/dark selection remain available when the active
     *     theme lacks matching control items in its composable header.
     */
    private function withRequiredOfflineControls(
        string $html,
        string $languageControl,
        string $themeControl,
    ): string {
        $missingControls = '';
        if (!str_contains($html, 'data-export-language')) {
            $missingControls .= $languageControl;
        }

        if (!str_contains($html, 'data-export-theme')) {
            $missingControls .= $themeControl;
        }

        if ($missingControls === '') {
            return $html;
        }

        $controls = '<div class="hph-site-header__group hph-site-header__group--end '
        . 'workspace-export-header-controls"><ul class="navbar-nav hph-site-header__control">'
        . $missingControls . '</ul></div>';
        $augmented = preg_replace('/<\/div>\s*<\/header>\s*$/i', $controls . '</div></header>', $html, 1);

        /*
         * HR: Kontrole se nikada ne dodaju izvan zaglavlja. Prazno zaglavlje
         *     znači da ih Workspace izvoz treba smjestiti u glavni meni.
         * EN: Controls are never appended outside the header. An empty header
         *     means the Workspace export must place them in the primary menu.
         */
        return is_string($augmented) && $augmented !== $html ? $augmented : $html;
    }

    /**
     * HR: Kasno dohvaća servis Theme modula i sigurno pada na null kada modul nije prisutan.
     * EN: Resolves a Theme service late and safely falls back to null when the module is absent.
     */
    private function service(string $serviceId): ?object
    {
        if (!$this->composerBridge->isInstalled(self::THEME_PACKAGE) || !class_exists($serviceId)) {
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
