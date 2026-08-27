<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(WorkspaceExportService::class)]
final class WorkspaceExportTabsTest extends TestCase
{
    /**
     * HR: Cijeli offline izvoz područja mora zadržati tabove, prilagodljivu
     *     visinu panela te upravljanje mišem i tipkovnicom nakon promjene stranice.
     * EN: The complete offline Workspace export must preserve tabs, content-sized
     *     panels, and mouse/keyboard controls after each page change.
     */
    public function testOfflineExportContainsTabStylesAndRuntime(): void
    {
        $reflection = new ReflectionClass(WorkspaceExportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $css = $reflection->getMethod('exportCss')->invoke($service);
        $javascript = $reflection->getMethod('exportJs')->invoke($service);

        $this->assertIsString($css);
        $this->assertIsString($javascript);
        $this->assertStringContainsString('.editor-html-tabs__panel[hidden]', $css);
        $this->assertStringContainsString('overflow-y: visible', $css);
        $this->assertStringContainsString('function initializeTabs(container)', $javascript);
        $this->assertStringContainsString('initializeTabs(pageHost)', $javascript);
        $this->assertStringContainsString('[data-editor-html-tabs="1"] [role="tab"]', $javascript);
        $this->assertStringContainsString("['ArrowLeft', 'ArrowRight', 'Home', 'End']", $javascript);
    }
}
