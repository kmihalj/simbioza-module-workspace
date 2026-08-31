<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeBridge;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Localization\TranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(WorkspaceThemeBridge::class)]
final class WorkspaceThemeBridgeTest extends TestCase
{
    /**
     * HR: Već razriješene HTTP putanje moraju se vratiti na ugrađene offline
     *     izvore jer `file://` ne može dohvatiti rutu instalirane aplikacije.
     * EN: Already-resolved HTTP paths must map back to embedded offline sources
     *     because `file://` cannot fetch the installed application's route.
     */
    public function testResolvedThemeRoutesAreEmbeddedForFileProtocolExports(): void
    {
        $bridge = new WorkspaceThemeBridge(
            $this->createStub(ContainerInterface::class),
            $this->createStub(ComposerBridge::class),
            $this->createStub(TranslatorInterface::class),
        );
        $method = new ReflectionMethod($bridge, 'embedRenderedThemeAssets');
        $html = '<img src="/HFC/theme/assets/library/simbioza/icon.png">'
        . '<img src="https://example.test/HFC/theme/assets/visual/hero.svg">'
        . '<img src="/HFC/images/mark.png"><img src="https://cdn.example.test/external.png">';
        $sources = [
            '@theme-assets/simbioza/icon.png' => 'data:image/png;base64,aWNvbg==',
            '@theme/hero.svg' => 'data:image/svg+xml;base64,aGVybw==',
            '@app/images/mark.png' => 'data:image/png;base64,bWFyaw==',
        ];

        $embedded = $method->invoke($bridge, $html, $sources);

        $this->assertIsString($embedded);
        $this->assertStringContainsString('src="data:image/png;base64,aWNvbg=="', $embedded);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,aGVybw=="', $embedded);
        $this->assertStringContainsString('src="data:image/png;base64,bWFyaw=="', $embedded);
        $this->assertStringContainsString('src="https://cdn.example.test/external.png"', $embedded);
        $this->assertStringNotContainsString('/HFC/theme/assets/', $embedded);
    }

    /**
     * HR: Izvoz mora dobiti potpun i čitljiv fallback ugovor kada Theme modul
     *     nije instaliran; dohvat Theme servisa tada se ne smije ni pokušati.
     * EN: Export must receive a complete readable fallback contract when the
     *     Theme module is absent, without attempting to resolve Theme services.
     */
    public function testMissingThemeModuleReturnsPortableFallbackContract(): void
    {
        $container = new class implements ContainerInterface {
            /**
             * HR: Test zabranjuje dohvat servisa kada Theme modul nedostaje.
             * EN: The test forbids service resolution while Theme is absent.
             */
            public function get(string $id): never
            {
                throw new RuntimeException('Unexpected service lookup: ' . $id);
            }

            /**
             * HR: Prazni testni container nema registriranih servisa.
             * EN: The empty test container has no registered services.
             */
            public function has(string $id): bool
            {
                return false;
            }
        };
        $composer = new class extends ComposerBridge {
            /**
             * HR: Simulira instalaciju bez Theme paketa.
             * EN: Simulates an installation without the Theme package.
             */
            public function isInstalled(string $packageName): bool
            {
                return false;
            }
        };
        $translator = new class implements TranslatorInterface {
            /**
             * HR: Fallback test vraća ključ jer ovdje provjerava samo strukturu.
             * EN: The fallback test returns the key because it checks structure only.
             *
             * @param array<string, string|int|float> $replace
             */
            public function trans(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }

            /**
             * HR: Vraća zadani hrvatski jezik testa.
             * EN: Returns the test's default Croatian locale.
             */
            public function getLocale(): string
            {
                return 'hr';
            }

            /**
             * HR: Testni prevoditelj nema promjenjivo stanje.
             * EN: The test translator has no mutable state.
             */
            public function setLocale(string $locale): void
            {
            }
        };

        $bridge = new WorkspaceThemeBridge($container, $composer, $translator);

        $this->assertSame([
            'css' => '',
            'files' => [],
            'sources' => [],
            'mode' => 'auto',
            'navigation_placement' => 'standalone',
            'header_enabled' => false,
            'enabled' => false,
        ], $bridge->bundle());
        $this->assertSame('', $bridge->renderHeader('hr', '', '', [], '#page-1'));
        $this->assertSame('', $bridge->renderHero('hr', [], '', []));
        $this->assertSame([
            'classes' => 'container-fluid hph-container-wide px-4 hph-main-content',
            'stage_classes' => 'hph-page-stage',
        ], $bridge->mainContentPresentation(true, true));
    }
}
