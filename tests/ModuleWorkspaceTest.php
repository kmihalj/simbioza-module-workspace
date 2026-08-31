<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleWorkspace::class)]
final class ModuleWorkspaceTest extends TestCase
{
    /**
     * HR: Provjerava stabilni Composer identitet koji koriste manifest i host aplikacija.
     * EN: Verifies the stable Composer identity used by the manifest and host application.
     */
    public function testPackageNameIsStable(): void
    {
        $this->assertSame('aaieduhr/simbioza-module-workspace', ModuleWorkspace::PACKAGE_NAME);
        $this->assertSame(
            'workspace_homepage_settings',
            ModuleWorkspace::TABLE_WORKSPACE_HOMEPAGE_SETTINGS,
        );
        $this->assertSame(
            'workspace_user_homepages',
            ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES,
        );
        $this->assertSame('workspace_themes', ModuleWorkspace::TABLE_WORKSPACE_THEMES);
    }
}
