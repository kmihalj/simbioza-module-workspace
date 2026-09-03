<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspaceEmbeddedSearchMarkupTest extends TestCase
{
    /**
     * HR: Ugrađena pretraga šalje čvrsti opseg bez preklapajućeg autosuggesta.
     * EN: Embedded search submits a fixed scope without an overlapping autosuggest.
     */
    public function testEmbeddedSearchUsesSubmittedScopeWithoutSuggestionOverlay(): void
    {
        $service = file_get_contents(dirname(__DIR__) . '/src/Service/WorkspaceDynamicContentService.php');
        $script = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.js');
        $styles = file_get_contents(dirname(__DIR__) . '/resources/assets/workspace.css');

        $this->assertIsString($service);
        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertStringContainsString('name="embedded" value="1"', $service);
        $this->assertStringContainsString(
            'Without operators, multiple words are searched as an exact phrase.',
            $service,
        );
        $this->assertStringNotContainsString('data-suggest-url', $service);
        $this->assertStringNotContainsString('data-workspace-embedded-search-results', $service);
        $this->assertStringNotContainsString('initializeEmbeddedWorkspaceSearch', $script);
        $this->assertStringNotContainsString('[data-workspace-embedded-search-results]', $styles);
    }
}
