<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceExternalReferenceProviderInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExternalReferenceRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceExternalReferenceRegistry::class)]
final class WorkspaceExternalReferenceRegistryTest extends TestCase
{
    /** HR: Servisni config ne smije zamijeniti registar koji su ranije popunili drugi moduli. EN: Service config must not replace a registry populated earlier by other modules. */
    public function testServiceConfigurationKeepsTheAutowireSingleton(): void
    {
        $services = file_get_contents(dirname(__DIR__) . '/config/services.php');

        $this->assertIsString($services);
        $this->assertStringNotContainsString(
            'WorkspaceExternalReferenceRegistry::class =>',
            $services,
        );
    }

    /** HR: Nepoznate oznake ostaju neriješene, a registrirani provider vraća lokalni cilj. EN: Unknown references remain unresolved while a registered provider returns a local target. */
    public function testRegisteredProviderResolvesPortableWorkspaceReference(): void
    {
        $registry = new WorkspaceExternalReferenceRegistry();
        $this->assertNull($registry->resolve('confluence', 'AAIUPUTE'));

        $registry->register(new class implements WorkspaceExternalReferenceProviderInterface {
            public function provider(): string
            {
                return 'confluence';
            }

            public function resolve(string $reference): ?array
            {
                return $reference === 'AAIUPUTE'
                    ? ['slug' => 'aaiupute', 'title' => 'AAI EduHr upute']
                    : null;
            }
        });

        $resolved = $registry->resolve('CONFLUENCE', 'AAIUPUTE');
        $this->assertSame(['slug' => 'aaiupute', 'title' => 'AAI EduHr upute'], $resolved);
        $this->assertNull($registry->resolve('confluence', 'UNKNOWN'));
    }
}
