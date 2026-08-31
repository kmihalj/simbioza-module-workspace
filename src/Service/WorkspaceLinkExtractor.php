<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;

use function array_search;
use function array_values;
use function html_entity_decode;
use function in_array;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function parse_url;
use function preg_replace;
use function rawurldecode;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * HR: Izdvaja samo interne poveznice na Workspace stranice iz objavljenog HTML-a.
 * EN: Extracts only internal Workspace-page links from published HTML.
 */
final readonly class WorkspaceLinkExtractor
{
    /** HR: Prima instalacijski neovisnu putanju područja. EN: Receives the installation-independent Workspace path. */
    public function __construct(private WorkspaceConfig $config)
    {
    }

    /**
     * HR: Vraća slug područja, slug stranice i čitljiv tekst svake interne poveznice.
     * EN: Returns the Workspace slug, page slug, and readable text of every internal link.
     *
     * @return list<array{workspaceSlug:string,nodeSlug:string,linkText:string}>
     */
    public function extract(string $html): array
    {
        if (trim($html) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }

        $result = [];
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            $target = $this->targetFromHref($anchor->getAttribute('href'));
            if ($target === null) {
                continue;
            }

            $target['linkText'] = $this->linkText($anchor);
            $result[] = $target;
        }

        return $result;
    }

    /**
     * HR: Prepoznaje rutu neovisno o tome je li aplikacija u web rootu ili poddirektoriju.
     * EN: Recognizes the route whether the application is installed at the web root or in a subdirectory.
     *
     * @return array{workspaceSlug:string,nodeSlug:string}|null
     */
    private function targetFromHref(string $href): ?array
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $scheme = strtolower((string)(parse_url($href, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || trim($path, '/') === '') {
            return null;
        }

        $segments = array_values(array_filter(
            array_map(rawurldecode(...), explode('/', trim($path, '/'))),
            static fn(string $segment): bool => $segment !== '',
        ));
        $rootIndex = array_search($this->config->rootPath(), $segments, true);
        if (!is_int($rootIndex) || !isset($segments[$rootIndex + 1], $segments[$rootIndex + 2])) {
            return null;
        }

        return [
            'workspaceSlug' => strtolower(trim($segments[$rootIndex + 1])),
            'nodeSlug' => strtolower(trim($segments[$rootIndex + 2])),
        ];
    }

    /** HR: Svodi tekst poveznice na jedan red. EN: Collapses link text to one line. */
    private function linkText(DOMElement $anchor): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($anchor->textContent));

        return is_string($text) ? mb_substr($text, 0, 255) : '';
    }
}
