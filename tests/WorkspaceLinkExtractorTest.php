<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceLinkExtractor;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceLinkExtractor::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceLinkExtractorTest extends TestCase
{
    /**
     * HR: Prepoznaje relativne i apsolutne Workspace putanje neovisno o poddirektoriju instalacije.
     * EN: Recognizes relative and absolute Workspace paths independently of the installation subdirectory.
     */
    public function testExtractsInternalWorkspaceLinksFromPublishedHtml(): void
    {
        $extractor = new WorkspaceLinkExtractor($this->workspaceConfig());

        $links = $extractor->extract(<<<'HTML'
            <p>
                <a href="/HFC/workspace/qa-space/first-page?lang=hr"> Prva   stranica </a>
                <a href="https://example.test/HFC/workspace/qa-space/second-page#part">Druga</a>
                <a href="mailto:test@example.test">E-pošta</a>
                <a href="/HFC/calendars">Kalendari</a>
                <a href="#local">Lokalni naslov</a>
            </p>
            HTML);

        $this->assertSame([
            ['workspaceSlug' => 'qa-space', 'nodeSlug' => 'first-page', 'linkText' => 'Prva stranica'],
            ['workspaceSlug' => 'qa-space', 'nodeSlug' => 'second-page', 'linkText' => 'Druga'],
        ], $links);
    }

    /**
     * HR: Kod bez poveznica ili prazni HTML ne stvara lažne backlinkove.
     * EN: Markup without links or empty HTML creates no false backlinks.
     */
    public function testIgnoresNonWorkspaceTargets(): void
    {
        $extractor = new WorkspaceLinkExtractor($this->workspaceConfig());

        $this->assertSame([], $extractor->extract(''));
        $this->assertSame([], $extractor->extract('<a href="https://example.test/about">O nama</a>'));
    }

    /** HR: Gradi stvarnu konfiguraciju modula. EN: Builds the real module configuration. */
    private function workspaceConfig(): WorkspaceConfig
    {
        return new WorkspaceConfig(new Config(new Helper(), []), dirname(__DIR__));
    }
}
