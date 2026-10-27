<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * EN: Both content and extension ACLs share one empty, paged, accessible dropdown template.
 *
 * HR: Ovlasti sadržaja i proširenja dijele isti prazan, straničen i pristupačan predložak padajućeg odabira.
 */
#[CoversNothing]
final class WorkspaceSubjectPickerViewTest extends TestCase
{
    /**
     * EN: Keeps existing IDs, bounded remote lookup hooks and a real dropdown list rather than inline directory rows.
     *
     * HR: Čuva postojeće oznake, ograničeni udaljeni dohvat i pravi padajući popis umjesto redaka cijelog imenika.
     */
    public function testSharedUserAndGroupDropdownMarkup(): void
    {
        $renderer = new class {
            public function escape(mixed $value): string
            {
                return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        };
        foreach (['user', 'group'] as $kind) {
            foreach ([false, true] as $realOnly) {
                $html = (function () use ($kind, $realOnly): string {
                    $workspaceSubjectId = 'synthetic-' . $kind;
                    $workspaceSubjectResultsId = 'synthetic-results-' . $kind;
                    $workspaceSubjectType = $kind;
                    $workspaceSubjectSearchUrl = '/workspace/subjects?test="safe"';
                    $workspaceSubjectWorkspaceId = 17;
                    $workspaceSubjectRealOnly = $realOnly;
                    ob_start();
                    try {
                        require dirname(__DIR__) . '/views/partials/subject-picker.php';
                        return (string)ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                })->call($renderer);
                $this->assertStringContainsString('class="workspace-subject-results list-group"', $html);
                $this->assertStringContainsString('role="combobox"', $html);
                $this->assertStringContainsString('data-workspace-min-query-length="0"', $html);
                $this->assertStringContainsString('role="listbox"', $html);
                $this->assertStringContainsString('aria-controls="synthetic-results-' . $kind . '"', $html);
                $realSubjectsAttribute = 'data-workspace-real-subjects-only="' . ($realOnly ? '1' : '0') . '"';
                $this->assertStringContainsString($realSubjectsAttribute, $html);
                $this->assertStringContainsString('data-workspace-subject-type="' . $kind . '"', $html);
                $this->assertStringContainsString('data-workspace-subject-results hidden></div>', $html);
                $this->assertStringContainsString('test=&quot;safe&quot;', $html);
                $this->assertStringNotContainsString('<option', $html);
                $this->assertStringNotContainsString('list-group-item', $html);
            }
        }
    }
}
