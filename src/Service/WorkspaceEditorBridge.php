<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function get_object_vars;
use function interface_exists;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function method_exists;
use function trim;

final readonly class WorkspaceEditorBridge
{
    private const EDITOR_PACKAGE = 'aaieduhr/heartphrame-module-editor-html';

    private const EDITOR_SERVICE_NAMESPACE = 'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\';

    private const DOCUMENT_VIEW_BUILDER =
    'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\EditorDocumentViewBuilder';

    private const PUBLISHED_VERSION_PROVIDER =
    'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\EditorPublishedVersionProviderInterface';

    /**
     * EN: Receives the container and router so Workspace can use the editor as an optional integration.
     *
     * HR: Prima container i router kako bi Workspace koristio editor kao opcionalnu integraciju.
     */
    public function __construct(
        private ContainerInterface $container,
        private ComposerBridge $composerBridge,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * EN: Checks whether the enabled HTML editor and its main service are available.
     *
     * HR: Provjerava postoji li uključeni HTML editor i njegov glavni servis.
     */
    public function isAvailable(): bool
    {
        return $this->editorService() !== null;
    }

    /**
     * EN: Returns documents exposed by the editor for linking to a tree node.
     *
     * HR: Vraća dokumente koje editor izlaže za povezivanje s čvorom stabla.
     *
     * @return list<array{id:string,title:string}>
     */
    public function documents(string $language): array
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'listDocuments')) {
            return [];
        }

        try {
            $documents = $editor->listDocuments($language);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($documents)) {
            return [];
        }

        $result = [];
        foreach ($documents as $document) {
            if (!is_object($document)) {
                continue;
            }

            $id = is_scalar($document->id ?? null) ? trim((string)$document->id) : '';
            $title = is_scalar($document->title ?? null) ? trim((string)$document->title) : '';
            if ($id !== '') {
                $result[] = ['id' => $id, 'title' => $title !== '' ? $title : $id];
            }
        }

        return $result;
    }

    /**
     * EN: Returns active document attachments as neutral data for the native Workspace gallery.
     *
     * HR: Vraća aktivne privitke dokumenta kao neutralne podatke za nativnu galeriju područja.
     *
     * @return list<array<string,mixed>>
     */
    public function publicAssets(string $documentKey): array
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'listPublicAssets')) {
            return [];
        }

        try {
            $assets = $editor->listPublicAssets(trim($documentKey));
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach (is_array($assets) ? $assets : [] as $asset) {
            if (!is_object($asset)) {
                continue;
            }

            $data = get_object_vars($asset);
            $uuid = $this->scalarString($data['uuid'] ?? null);
            $mimeType = $this->scalarString($data['mimeType'] ?? null);
            if ($uuid === '') {
                continue;
            }

            $displayName = $this->scalarString($data['displayName'] ?? null);
            $originalName = $this->scalarString($data['originalName'] ?? null);
            $kind = method_exists($asset, 'kind') ? $this->scalarString($asset->kind()) : 'file';
            $result[] = [
                'uuid' => $uuid,
                'name' => $displayName !== '' ? $displayName : ($originalName !== '' ? $originalName : $uuid),
                'alt_text' => $this->scalarString($data['altText'] ?? null),
                'caption' => $this->scalarString($data['caption'] ?? null),
                'mime_type' => $mimeType,
                'kind' => $kind !== '' ? $kind : 'file',
                'created_at' => $this->scalarString($data['createdAt'] ?? null),
                'url' => $this->urlGenerator->namedRouteExists('editor-html.asset')
                    ? $this->urlGenerator->getPathFor('editor-html.asset', ['assetUuid' => $uuid])
                    : '/editor-html/asset/' . rawurlencode($uuid),
            ];
        }

        return $result;
    }

    /**
     * EN: Converts only scalar values received from an optional module to text.
     *
     * HR: Pretvara samo skalarne vrijednosti opcionalnog modula u tekst.
     */
    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * EN: Creates an initial HTML document and returns its stable document key.
     *
     * HR: Kreira početni HTML dokument i vraća stabilni document key.
     */
    public function createDocument(string $title, string $slug, string $language): string
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'create')) {
            throw new RuntimeException(__('HTML editor nije dostupan.'));
        }

        $document = $editor->create($title, $slug, $language);
        $documentId = is_object($document) && is_scalar($document->id ?? null)
        ? trim((string)$document->id)
        : '';
        if ($documentId === '') {
            throw new RuntimeException(__('HTML dokument nije moguće kreirati.'));
        }

        return $documentId;
    }

    /**
     * EN: Applies the document's explicit storage owner after the tree link is saved.
     * Older or absent Editor modules remain optional; storage failures are not hidden.
     *
     * HR: Primjenjuje izričitog vlasnika pohrane dokumenta nakon spremanja veze stabla.
     * Stariji ili odsutni Editor ostaje opcionalan; pogreške pohrane se ne skrivaju.
     */
    public function organizeDocumentPayloads(string $documentKey): void
    {
        $service = self::EDITOR_SERVICE_NAMESPACE . 'OrmEditorStorage';
        if ($documentKey === '' || !$this->container->has($service)) {
            return;
        }

        $storage = $this->container->get($service);
        if (is_object($storage) && method_exists($storage, 'relocateOwnedPayloads')) {
            $storage->relocateOwnedPayloads([$documentKey]);
        }
    }

    /**
     * EN: Checks whether the current user can load an active editor document before it is attached.
     *
     * HR: Provjerava može li trenutačni korisnik učitati aktivni editor dokument prije povezivanja.
     */
    public function hasDocument(string $documentKey): bool
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'load')) {
            return false;
        }

        try {
            return is_object($editor->load(trim($documentKey)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * EN: Retrieves the complete Editor document-view model, including content,
     * attachments, languages, history, export, and action URLs.
     *
     * HR: Dohvaća potpuni Editorov model pregleda dokumenta, uključujući
     * sadržaj, privitke, jezike, povijest, export i URL-ove akcija.
     *
     * @param array<mixed, mixed> $query
     * @return array<string, mixed>|null
     */
    public function documentView(
        string $documentKey,
        string $language,
        array $query,
        bool $canEditDocument,
        bool $canPreviewDraft = false,
    ): ?array {
        $builder = $this->documentViewBuilder();
        if ($builder === null || !method_exists($builder, 'build')) {
            return null;
        }

        try {
            $view = $builder->build(
                $documentKey,
                $language,
                $query,
                $canEditDocument,
                $canPreviewDraft,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($view) ? WorkspaceValue::stringKeyArray($view) : null;
    }

    /**
     * EN: Returns the current working Editor version number for the selected
     *     locale, including the single shared draft.
     *
     * HR: Vraća broj aktualne radne Editor verzije odabranog jezika, uključujući
     *     jedini zajednički nacrt.
     */
    public function latestVersionNumber(string $documentKey, string $language): int
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'currentVersionNumber')) {
            return 0;
        }

        try {
            $versionNumber = $editor->currentVersionNumber($documentKey, $language);
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($versionNumber) ? (int)$versionNumber : 0;
    }

    /**
     * EN: Loads exact published article versions for Shorts through the current batch contract.
     *
     * HR: Učitava točno objavljene verzije članaka za Sažetke kroz aktualni skupni ugovor.
     *
     * @param array<string, int> $versionNumbersByDocument
     * @return array<string, array{
     *     documentId:string,
     *     versionNumber:int,
     *     title:string,
     *     html:string,
     *     createdAt:string
     * }>
     */
    public function publishedVersions(array $versionNumbersByDocument, string $language): array
    {
        $editor = $this->editorService();
        if ($editor === null) {
            return [];
        }

        try {
            if (!method_exists($editor, 'loadVersions')) {
                throw new RuntimeException('HTML Editor must provide the current batch version contract.');
            }

            $versions = $editor->loadVersions($versionNumbersByDocument, $language);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($versions)) {
            return [];
        }

        return $this->normalizedVersions($versions);
    }

    /**
     * EN: Loads published versions only for the internal derived index. The
     *     current Editor skips session ACL for this background job; the actual
     *     Workspace ACL is still always applied when backlinks are displayed.
     *
     * HR: Učitava objavljene verzije isključivo za interni izvedeni indeks.
     *     Aktualni Editor za taj pozadinski posao preskače sesijski ACL; stvarni
     *     Workspace ACL i dalje se obavezno primjenjuje pri prikazu backlinkova.
     *
     * @param array<string, int> $versionNumbersByDocument
     * @return array<string, array{
     *     documentId:string,
     *     versionNumber:int,
     *     title:string,
     *     html:string,
     *     createdAt:string
     * }>
     */
    public function publishedVersionsForIndexing(array $versionNumbersByDocument, string $language): array
    {
        $provider = $this->publishedVersionProvider();
        if ($provider === null || !method_exists($provider, 'loadPublishedVersionsForIndexing')) {
            return $this->publishedVersions($versionNumbersByDocument, $language);
        }

        try {
            $versions = $provider->loadPublishedVersionsForIndexing(
                $versionNumbersByDocument,
                $language,
            );
        } catch (Throwable) {
            return [];
        }

        return is_array($versions) ? $this->normalizedVersions($versions) : [];
    }

    /**
     * EN: Retrieves ACL-filtered sources that dynamically include the target page.
     *
     * HR: Dohvaća ACL-filtrirane izvore koji dinamički uključuju ciljnu stranicu.
     *
     * @return list<array{documentKey:string,title:string,language:string}>
     */
    public function includeSources(string $targetDocumentKey, string $language): array
    {
        $class = self::EDITOR_SERVICE_NAMESPACE . 'EditorDocumentIncludeService';
        if (!class_exists($class) || !$this->container->has($class)) {
            return [];
        }

        try {
            $service = $this->container->get($class);
            if (!is_object($service) || !method_exists($service, 'sourcesIncluding')) {
                return [];
            }

            $sources = $service->sourcesIncluding($targetDocumentKey, $language);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($sources)) {
            return [];
        }

        $normalized = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $documentKey = is_scalar($source['documentKey'] ?? null)
            ? trim((string)$source['documentKey'])
            : '';
            $title = is_scalar($source['title'] ?? null)
            ? trim((string)$source['title'])
            : '';
            $sourceLanguage = is_scalar($source['language'] ?? null)
            ? trim((string)$source['language'])
            : '';
            if ($documentKey === '') {
                continue;
            }

            if ($title === '') {
                continue;
            }

            $normalized[] = [
                'documentKey' => $documentKey,
                'title' => $title,
                'language' => $sourceLanguage,
            ];
        }

        return $normalized;
    }

    /**
     * EN: Normalizes Editor version models into the stable Workspace shape.
     *
     * HR: Normalizira Editorove modele verzija u stabilni Workspace oblik.
     *
     * @param array<mixed,mixed> $versions
     * @return array<string, array{documentId:string,versionNumber:int,title:string,html:string,createdAt:string}>
     */
    private function normalizedVersions(array $versions): array
    {
        $result = [];
        foreach ($versions as $documentKey => $version) {
            if (!is_object($version)) {
                continue;
            }

            $id = is_scalar($version->documentId ?? null)
            ? trim((string)$version->documentId)
            : trim((string)$documentKey);
            $versionNumber = is_numeric($version->versionNumber ?? null)
            ? (int)$version->versionNumber
            : 0;
            if ($id === '') {
                continue;
            }

            if ($versionNumber <= 0) {
                continue;
            }

            $result[$id] = [
                'documentId' => $id,
                'versionNumber' => $versionNumber,
                'title' => is_scalar($version->title ?? null) ? trim((string)$version->title) : '',
                'html' => is_scalar($version->html ?? null) ? (string)$version->html : '',
                'createdAt' => is_scalar($version->createdAt ?? null)
                    ? trim((string)$version->createdAt)
                    : '',
            ];
        }

        return $result;
    }

    /**
     * EN: Converts the initial Editor version of a newly linked page into an
     *     unpublished draft.
     *
     * HR: Početnu Editor verziju upravo povezane stranice pretvara u
     *     neobjavljeni nacrt.
     */
    public function markVersionDraft(string $documentKey, string $language, int $versionNumber): void
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'markVersionDraft')) {
            throw new RuntimeException(__('HTML editor ne podržava nacrte područja.'));
        }

        $editor->markVersionDraft($documentKey, $language, $versionNumber);
    }

    /**
     * EN: Publishes the single shared draft through the Editor storage service.
     *
     * HR: Objavljuje jedini zajednički nacrt kroz Editorov storage servis.
     */
    public function publishDraft(string $documentKey, string $language, int $versionNumber): void
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'publishDraft')) {
            throw new RuntimeException(__('HTML editor ne podržava objavljivanje nacrta.'));
        }

        $editor->publishDraft($documentKey, $language, $versionNumber);
    }

    /**
     * EN: Discards the single shared draft without changing the last publication.
     *
     * HR: Odbacuje jedini zajednički nacrt bez promjene zadnje objave.
     */
    public function discardDraft(string $documentKey, string $language): void
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'discardDraft')) {
            throw new RuntimeException(__('HTML editor ne podržava odbacivanje nacrta.'));
        }

        $editor->discardDraft($documentKey, $language);
    }

    /**
     * EN: Checks whether a Workspace document has no publication in any locale.
     *
     * HR: Provjerava nema li Workspace dokument objavu ni na jednom jeziku.
     */
    public function isDocumentNeverPublished(string $documentKey): bool
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'isDocumentNeverPublished')) {
            return false;
        }

        return (bool)$editor->isDocumentNeverPublished($documentKey);
    }

    /**
     * EN: Permanently deletes the Editor document for a new page with no publication.
     *
     * HR: Trajno briše Editor dokument za novu stranicu bez ijedne objave.
     */
    public function deleteUnpublishedDocumentPermanently(string $documentKey): void
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'deleteUnpublishedDocumentPermanently')) {
            throw new RuntimeException(__('HTML editor ne podržava trajno brisanje neobjavljenog dokumenta.'));
        }

        $editor->deleteUnpublishedDocumentPermanently($documentKey);
    }

    /**
     * EN: Soft-deletes an editor document when its owning Workspace node is deleted.
     *
     * HR: Soft-briše editor dokument kada se briše jedini Workspace čvor koji ga posjeduje.
     */
    public function deleteDocument(string $documentKey): void
    {
        $editor = $this->editorService();
        if ($editor === null || !method_exists($editor, 'deleteDocument')) {
            return;
        }

        $editor->deleteDocument($documentKey);
    }

    /**
     * EN: Builds the WYSIWYG editor path for the selected document.
     *
     * HR: Gradi putanju prema WYSIWYG editoru za odabrani dokument.
     */
    public function editorPath(string $documentKey, string $language): string
    {
        if ($this->urlGenerator->namedRouteExists('editor-html.index')) {
            return $this->urlGenerator->getPathFor(
                'editor-html.index',
                [],
                ['document' => $documentKey, 'lang' => $language],
            );
        }

        return '/editor-html?document=' . rawurlencode($documentKey) . '&lang=' . rawurlencode($language);
    }

    /**
     * EN: Safely resolves the main editor service without a hard Composer dependency.
     *
     * HR: Sigurno dohvaća glavni editor servis bez tvrde Composer ovisnosti.
     */
    private function editorService(): ?object
    {
        $serviceId = self::EDITOR_SERVICE_NAMESPACE . 'EditorService';
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

    /**
     * EN: Safely resolves the shared Editor view builder without a hard Composer dependency.
     *
     * HR: Sigurno dohvaća zajednički Editorov builder prikaza bez tvrde Composer ovisnosti.
     */
    private function documentViewBuilder(): ?object
    {
        if (!class_exists(self::DOCUMENT_VIEW_BUILDER)) {
            return null;
        }

        try {
            $builder = $this->container->get(self::DOCUMENT_VIEW_BUILDER);
        } catch (Throwable) {
            return null;
        }

        return is_object($builder) ? $builder : null;
    }

    /**
     * EN: Resolves the narrow Editor service for session-independent derived indexes.
     *
     * HR: Dohvaća uski Editorov servis za izvedene indekse bez ovisnosti o sesiji.
     */
    private function publishedVersionProvider(): ?object
    {
        if (
            !$this->composerBridge->isInstalled(self::EDITOR_PACKAGE)
            || !interface_exists(self::PUBLISHED_VERSION_PROVIDER)
        ) {
            return null;
        }

        try {
            $provider = $this->container->get(self::PUBLISHED_VERSION_PROVIDER);
        } catch (Throwable) {
            return null;
        }

        return is_object($provider) ? $provider : null;
    }
}
