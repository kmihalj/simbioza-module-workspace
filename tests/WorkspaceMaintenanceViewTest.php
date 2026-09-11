<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspaceMaintenanceViewTest extends TestCase
{
    /**
     * HR: Štiti progress bar i ograničeni web worker od slučajnog uklanjanja iz administratorskog prikaza.
     * EN: Protects the progress bar and bounded web worker from accidental removal from the admin view.
     */
    public function testImageOptimizationUsesVisibleResumableProgress(): void
    {
        $view = file_get_contents(__DIR__ . '/../views/settings/maintenance.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('data-image-optimization-progress', $view);
        $this->assertStringContainsString('data-image-optimization-panel', $view);
        $this->assertStringContainsString('data-status-path', $view);
        $this->assertStringContainsString('data-step-path', $view);
        $this->assertStringContainsString("['queued', 'running']", $view);
        $this->assertStringContainsString(
            "panel.hidden = !['queued', 'running', 'failed'].includes(status)",
            $view,
        );
        $this->assertStringContainsString('labels.reconnecting', $view);
        $this->assertStringContainsString('const recover = async (error)', $view);
        $this->assertStringContainsString('scheduleWork(state?.worker_busy ? 1000 : 750)', $view);
    }
}
