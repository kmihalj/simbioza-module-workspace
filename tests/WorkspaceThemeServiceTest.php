<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(WorkspaceThemeService::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceThemeServiceTest extends TestCase
{
    /**
     * HR: Konfiguracijske mape Theme modula moraju zadržati ključeve grupa i
     *     skalarne vrijednosti jer ih zajednički editor koristi za izgradnju polja.
     * EN: Theme configuration maps must retain group keys and scalar values
     *     because the shared editor uses them to build its controls.
     */
    public function testThemeRepositoryConfigurationMapKeepsSemanticKeys(): void
    {
        $service = (new ReflectionClass(WorkspaceThemeService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WorkspaceThemeService::class, 'repositoryMap');
        $repository = new class {
            /** @return array<string, string|array<string, mixed>> */
            public function colorFieldGroups(): array
            {
                return [
                    'base' => ['label' => 'Base colors', 'fields' => ['body_bg']],
                    'menu_font_size' => '1rem',
                ];
            }
        };

        $result = $method->invoke($service, $repository, 'colorFieldGroups');

        $this->assertSame([
            'base' => ['label' => 'Base colors', 'fields' => ['body_bg']],
            'menu_font_size' => '1rem',
        ], $result);
    }
}
