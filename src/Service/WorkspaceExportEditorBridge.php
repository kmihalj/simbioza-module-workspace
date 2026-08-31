<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Bridge\ComposerBridge;
use Psr\Container\ContainerInterface;
use Throwable;

use function array_values;
use function basename;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function method_exists;
use function pathinfo;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * HR: Priprema objavljene Editor dokumente za Workspace ZIP, uključujući statičke
 *     kalendare, zadatke, sadržaj dokumenta i dopuštene privitke.
 * EN: Prepares published Editor documents for a Workspace ZIP, including static
 *     calendars, tasks, charts, the document outline, and permitted attachments.
 */
final readonly class WorkspaceExportEditorBridge
{
    private const EDITOR_PACKAGE = 'aaieduhr/heartphrame-module-editor-html';

    private const SERVICE_PREFIX = 'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\';

    /**
     * HR: Prima container za kasno razrješavanje svih opcionalnih Editor integracija.
     * EN: Receives the container for late resolution of every optional Editor integration.
     */
    public function __construct(
        private ContainerInterface $container,
        private ComposerBridge $composerBridge,
    ) {
    }

    /**
     * HR: Vraća podržane jezike i njihove prikazne nazive.
     * EN: Returns supported locales and their display labels.
     *
     * @return array<string, string>
     */
    public function languageLabels(): array
    {
        $config = $this->service('EditorHtmlConfig');
        if ($config === null || !method_exists($config, 'languageLabels')) {
            return ['hr' => 'HR', 'en' => 'EN'];
        }

        try {
            $labels = $config->languageLabels();
        } catch (Throwable) {
            return ['hr' => 'HR', 'en' => 'EN'];
        }

        $result = [];
        foreach (is_array($labels) ? $labels : [] as $language => $label) {
            if (is_string($language) && is_scalar($label) && trim((string)$label) !== '') {
                $result[strtolower(trim($language))] = trim((string)$label);
            }
        }

        return $result !== [] ? $result : ['hr' => 'HR', 'en' => 'EN'];
    }

    /**
     * HR: Vraća Bootstrap, Editor, Calendar i Task CSS snapshotove bez web ruta.
     * EN: Returns Bootstrap, Editor, Calendar, and Task CSS snapshots without web routes.
     *
     * @return array<string, string>
     */
    public function stylesheetContents(): array
    {
        $assets = $this->service('EditorHtmlStandaloneAssets');
        if ($assets === null || !method_exists($assets, 'assetContents')) {
            return [];
        }

        try {
            $contents = $assets->assetContents();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach (is_array($contents) ? $contents : [] as $name => $content) {
            if (is_string($name) && is_string($content)) {
                $result[$name] = $content;
            }
        }

        return $result;
    }

    /**
     * HR: Čita zadanu vidljivost sadržaja dokumenta iz iste Editor konfiguracije
     *     koju koristi web prikaz. Bez Editor modula sadržaj ostaje zatvoren.
     * EN: Reads the default document-outline visibility from the same Editor
     *     configuration used by the web view. Without Editor, it remains closed.
     */
    public function tableOfContentsVisibleByDefault(): bool
    {
        $config = $this->service('EditorHtmlConfig');
        if ($config === null || !method_exists($config, 'tableOfContentsEnabledByDefault')) {
            return false;
        }

        try {
            return (bool)$config->tableOfContentsEnabledByDefault();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * HR: Učitava točnu objavljenu verziju i pretvara svaki dinamički dio u offline snimku.
     * EN: Loads the exact published version and converts every dynamic part into an offline snapshot.
     *
     * @param array<string, mixed>|null $actor
     * @return array{
     *     title:string,
     *     html:string,
     *     headings:list<array{level:int,id:string,text:string}>,
     *     attachments:list<array{name:string,mime_type:string,file_size:int,path:string}>,
     *     attachments_visible_by_default:bool,
     *     files:array<string,string>
     * }|null
     */
    public function snapshot(
        string $documentKey,
        string $language,
        int $versionNumber,
        string $assetDirectory,
        ?array $actor = null,
    ): ?array {
        $actorContext = $this->service('EditorApiActorContext');
        if ($actorContext !== null && method_exists($actorContext, 'runAs') && is_numeric($actor['id'] ?? null)) {
            try {
                $snapshot = null;
                $actorContext->runAs(
                    $actor,
                    function () use (
                        &$snapshot,
                        $documentKey,
                        $language,
                        $versionNumber,
                        $assetDirectory,
                    ): void {
                        $snapshot = $this->snapshotForCurrentActor(
                            $documentKey,
                            $language,
                            $versionNumber,
                            $assetDirectory,
                        );
                    },
                );

                return $snapshot;
            } catch (Throwable) {
                return null;
            }
        }

        return $this->snapshotForCurrentActor($documentKey, $language, $versionNumber, $assetDirectory);
    }

    /**
     * HR: Čita Editor podatke unutar već postavljenog web ili API identiteta.
     * EN: Reads Editor data inside an already established web or API identity.
     *
     * @return array{
     *     title:string,
     *     html:string,
     *     headings:list<array{level:int,id:string,text:string}>,
     *     attachments:list<array{name:string,mime_type:string,file_size:int,path:string}>,
     *     attachments_visible_by_default:bool,
     *     files:array<string,string>
     * }|null
     */
    private function snapshotForCurrentActor(
        string $documentKey,
        string $language,
        int $versionNumber,
        string $assetDirectory,
    ): ?array {
        $editor = $this->service('EditorService');
        $formatter = $this->service('EditorHtmlDocumentFormatter');
        $outlineBuilder = $this->service('EditorDocumentOutlineBuilder');
        if (
            $editor === null
            || $formatter === null
            || $outlineBuilder === null
            || !method_exists($editor, 'loadVersion')
            || !method_exists($editor, 'listVersionAssets')
            || !method_exists($editor, 'assetContent')
            || !method_exists($formatter, 'rewriteAssetUrlsForStandalone')
            || !method_exists($outlineBuilder, 'build')
        ) {
            return null;
        }

        try {
            $version = $editor->loadVersion($documentKey, $language, $versionNumber);
            if (!is_object($version)) {
                return null;
            }

            $title = is_scalar($version->title ?? null) ? trim((string)$version->title) : '';
            $html = is_scalar($version->html ?? null) ? (string)$version->html : '';
            $assets = $editor->listVersionAssets($documentKey, $language, $versionNumber);
            $assets = is_array($assets) ? array_values($assets) : [];
            $metadata = [];
            if (method_exists($editor, 'load')) {
                $document = $editor->load($documentKey, $language);
                $metadata = is_object($document) && is_array($document->metadata ?? null)
                ? $document->metadata
                : [];
            }

            $attachmentVisibility = method_exists($editor, 'normalizeAttachmentVisibility')
            ? $editor->normalizeAttachmentVisibility(
                is_scalar($metadata['attachment_visibility'] ?? null)
                    ? (string)$metadata['attachment_visibility']
                    : '',
            )
            : 'none';
            $showAttachmentList = in_array(
                is_scalar($attachmentVisibility) ? (string)$attachmentVisibility : 'none',
                ['public', 'authenticated'],
                true,
            );

            $assetHrefs = [];
            $files = [];
            $attachments = [];
            $usedNames = [];
            foreach ($assets as $asset) {
                if (!is_object($asset)) {
                    continue;
                }

                $uuid = is_scalar($asset->uuid ?? null) ? trim((string)$asset->uuid) : '';
                if ($uuid === '') {
                    continue;
                }

                $content = $editor->assetContent($uuid);
                if (!is_string($content)) {
                    continue;
                }

                $originalName = is_scalar($asset->originalName ?? null)
                ? (string)$asset->originalName
                : $uuid;
                $fileName = $this->uniqueFileName($originalName, $uuid, $usedNames);
                $path = trim($assetDirectory, '/') . '/' . $fileName;
                $assetHrefs[$uuid] = '../' . $path;
                $files[$path] = $content;
                if ($showAttachmentList) {
                    $displayName = is_scalar($asset->displayName ?? null)
                    ? trim((string)$asset->displayName)
                    : '';
                    $attachments[] = [
                        'name' => $displayName !== '' ? $displayName : $originalName,
                        'mime_type' => is_scalar($asset->mimeType ?? null)
                            ? (string)$asset->mimeType
                            : '',
                        'file_size' => is_numeric($asset->fileSize ?? null)
                            ? (int)$asset->fileSize
                            : 0,
                        'path' => '../' . $path,
                    ];
                }
            }

            $calendar = $this->service('EditorHtmlCalendarIntegration');
            $includes = $this->service('EditorDocumentIncludeService');
            if ($includes !== null && method_exists($includes, 'exportDependencies')) {
                $dependencies = $includes->exportDependencies($html, $documentKey, $language);
                foreach (is_array($dependencies) ? $dependencies : [] as $dependency) {
                    if (!is_array($dependency)) {
                        continue;
                    }

                    $dependencyKey = is_scalar($dependency['documentKey'] ?? null)
                    ? trim((string)$dependency['documentKey'])
                    : '';
                    $dependencyLanguage = is_scalar($dependency['language'] ?? null)
                    ? trim((string)$dependency['language'])
                    : $language;
                    $dependencyVersion = is_numeric($dependency['versionNumber'] ?? null)
                    ? (int)$dependency['versionNumber']
                    : 0;
                    if ($dependencyKey === '') {
                        continue;
                    }

                    $dependencyAssets = $dependencyVersion > 0
                    ? $editor->listVersionAssets($dependencyKey, $dependencyLanguage, $dependencyVersion)
                    : (method_exists($editor, 'listPublicAssets')
                            ? $editor->listPublicAssets($dependencyKey)
                            : []);
                    foreach (is_array($dependencyAssets) ? $dependencyAssets : [] as $dependencyAsset) {
                        if (!is_object($dependencyAsset)) {
                            continue;
                        }

                        $uuid = is_scalar($dependencyAsset->uuid ?? null)
                        ? trim((string)$dependencyAsset->uuid)
                        : '';
                        if ($uuid === '') {
                            continue;
                        }

                        if (isset($assetHrefs[$uuid])) {
                            continue;
                        }

                        $content = $editor->assetContent($uuid);
                        if (!is_string($content)) {
                            continue;
                        }

                        $originalName = is_scalar($dependencyAsset->originalName ?? null)
                        ? (string)$dependencyAsset->originalName
                        : $uuid;
                        $fileName = $this->uniqueFileName($originalName, $uuid, $usedNames);
                        $path = trim($assetDirectory, '/') . '/' . $fileName;
                        $assetHrefs[$uuid] = '../' . $path;
                        $files[$path] = $content;
                    }
                }
            }

            if ($includes !== null && method_exists($includes, 'render')) {
                $renderedIncludes = $includes->render($html, $documentKey, $language);
                if (is_string($renderedIncludes)) {
                    $html = $renderedIncludes;
                }
            }

            if ($calendar !== null && method_exists($calendar, 'renderEmbeds')) {
                $renderedCalendar = $calendar->renderEmbeds($html, [
                    'include_ics_export' => false,
                    'include_edit_action' => false,
                ]);
                if (is_string($renderedCalendar)) {
                    $html = $renderedCalendar;
                }
            }

            $tasks = $this->service('EditorTaskIntegration');
            if ($tasks !== null && method_exists($tasks, 'renderTasks')) {
                $renderedTasks = $tasks->renderTasks($html, $documentKey, $language, false);
                if (is_string($renderedTasks)) {
                    $html = $renderedTasks;
                }
            }

            // HR: Grafikon se u izvozu pretvara u samostalan inline SVG, dok
            //     kanonska uređiva konfiguracija ostaje samo u backupu.
            // EN: In export, a chart becomes self-contained inline SVG while
            //     the canonical editable configuration remains in backup only.
            $charts = $this->service('EditorHtmlChartService');
            if ($charts !== null && method_exists($charts, 'render')) {
                $renderedCharts = $charts->render($html);
                if (is_string($renderedCharts)) {
                    $html = $renderedCharts;
                }
            }

            $rewrittenHtml = $formatter->rewriteAssetUrlsForStandalone($html, $assetHrefs);
            if (is_string($rewrittenHtml)) {
                $html = $rewrittenHtml;
            }

            $outlined = $outlineBuilder->build($html);
            if (!is_object($outlined)) {
                return null;
            }

            $headings = [];
            foreach (is_array($outlined->headings ?? null) ? $outlined->headings : [] as $heading) {
                if (!is_object($heading)) {
                    continue;
                }

                $headings[] = [
                    'level' => is_numeric($heading->level ?? null) ? (int)$heading->level : 1,
                    'id' => is_scalar($heading->id ?? null) ? (string)$heading->id : '',
                    'text' => is_scalar($heading->text ?? null) ? (string)$heading->text : '',
                ];
            }

            $outlinedHtml = $outlined->html ?? null;

            return [
                'title' => $title !== '' ? $title : $documentKey,
                'html' => is_string($outlinedHtml) ? $outlinedHtml : $html,
                'headings' => $headings,
                'attachments' => $attachments,
                'attachments_visible_by_default' => false,
                'files' => $files,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * HR: Gradi čistu samostalnu HTML datoteku istim formatterom kao izvoz jedne
     *     stranice. Datoteka zato radi izravno preko `file://`, bez offline ljuske.
     * EN: Builds a clean standalone HTML file with the same formatter as the
     *     single-page export. The file therefore works directly over `file://`
     *     without the offline application shell.
     *
     * @param array<string, mixed> $snapshot
     * @param list<string> $stylesheetHrefs
     */
    public function standaloneDocument(array $snapshot, string $language, array $stylesheetHrefs): string
    {
        $formatter = $this->service('EditorHtmlDocumentFormatter');
        $title = is_scalar($snapshot['title'] ?? null) ? trim((string)$snapshot['title']) : '';
        $html = is_scalar($snapshot['html'] ?? null) ? (string)$snapshot['html'] : '';
        $attachments = is_array($snapshot['attachments'] ?? null)
        ? array_values($snapshot['attachments'])
        : [];
        $attachmentsHtml = $this->standaloneAttachmentList($attachments);
        if ($formatter !== null && method_exists($formatter, 'standaloneDocument')) {
            try {
                $document = $formatter->standaloneDocument(
                    $title,
                    $language,
                    $html,
                    $stylesheetHrefs,
                    [],
                    $attachmentsHtml,
                    $this->attachmentLabel('Prikaži privitke', 'Show attachments', $language),
                    $this->attachmentLabel('Sakrij privitke', 'Hide attachments', $language),
                );
                if (is_string($document)) {
                    return $document;
                }
            } catch (Throwable) {
                // HR: Čitljivi fallback ispod čuva izvoz i bez formattera.
                // EN: The readable fallback below preserves export without the formatter.
            }
        }

        return '<!doctype html><html lang="' . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head>'
        . '<body><main id="editor-html-standalone-content">' . $html . '</main>'
        . $attachmentsHtml . '</body></html>';
    }

    /**
     * HR: Pretvara dopuštene privitke u neutralan popis za samostalni dokument.
     * EN: Converts permitted attachments into a neutral standalone-document list.
     *
     * @param list<mixed> $attachments
     */
    private function standaloneAttachmentList(array $attachments): string
    {
        $items = '';
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $path = is_scalar($attachment['path'] ?? null) ? trim((string)$attachment['path']) : '';
            $name = is_scalar($attachment['name'] ?? null) ? trim((string)$attachment['name']) : '';
            if ($path === '') {
                continue;
            }

            if ($name === '') {
                continue;
            }

            $items .= '<li><a href="'
            . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
        }

        return $items !== '' ? '<ul class="editor-html-export-attachment-list">' . $items . '</ul>' : '';
    }

    /**
     * HR: Lokalizira malu samostalnu kontrolu bez mijenjanja web prevoditelja.
     * EN: Localizes the small standalone control without mutating the web translator.
     */
    private function attachmentLabel(string $croatian, string $english, string $language): string
    {
        return str_starts_with(strtolower(trim($language)), 'hr') ? $croatian : $english;
    }

    /**
     * HR: Čisti naziv privitka i osigurava jedinstveno ime u direktoriju dokumenta.
     * EN: Cleans an attachment filename and keeps it unique inside the document directory.
     *
     * @param list<string> $usedNames
     */
    private function uniqueFileName(string $fileName, string $fallback, array &$usedNames): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        $fileName = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $fileName);
        $fileName = trim($fileName, ". \t\n\r\0\x0B");
        $fileName = $fileName !== '' ? $fileName : $fallback;

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;
        $counter = 2;
        while (in_array(strtolower($candidate), $usedNames, true)) {
            $candidate = $stem . '-' . $counter . ($extension !== '' ? '.' . $extension : '');
            ++$counter;
        }

        $usedNames[] = strtolower($candidate);

        return $candidate;
    }

    /**
     * HR: Kasno dohvaća Editor servis i ostavlja Workspace funkcionalnim bez Editor paketa.
     * EN: Resolves an Editor service late and keeps Workspace functional without the Editor package.
     */
    private function service(string $shortName): ?object
    {
        $serviceId = self::SERVICE_PREFIX . $shortName;
        if (!$this->composerBridge->isInstalled(self::EDITOR_PACKAGE) || !class_exists($serviceId)) {
            return null;
        }

        try {
            $service = $this->container->get($serviceId);
        } catch (Throwable) {
            return null;
        }

        return is_object($service) ? $service : null;
    }
}
