<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(WorkspaceExportService::class)]
final class WorkspaceExportTabsTest extends TestCase
{
    /**
     * EN: The complete offline Workspace export must preserve tabs, content-sized
     *     panels, and mouse/keyboard controls after each page change.
     *
     * HR: Cijeli offline izvoz područja mora zadržati tabove, prilagodljivu
     *     visinu panela te upravljanje mišem i tipkovnicom nakon promjene stranice.
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
        $this->assertStringContainsString('[data-export-theme] option', $javascript);
        $this->assertStringContainsString('data-export-label-${language}', $javascript);
        // EN: Native table CSS accompanies its script; styles inside templates do not apply automatically.
        //
        // HR: Izvorni CSS tablice prati skriptu; stilovi unutar predložaka ne primjenjuju se sami.
        $this->assertStringContainsString('link[rel="stylesheet"],style,script', $javascript);
        $this->assertStringContainsString('document.createElement(source.tagName.toLowerCase())', $javascript);
        $this->assertStringContainsString('asset.textContent = source.textContent', $javascript);
        $this->assertStringContainsString("source.tagName === 'STYLE' ||", $javascript);
    }
}
