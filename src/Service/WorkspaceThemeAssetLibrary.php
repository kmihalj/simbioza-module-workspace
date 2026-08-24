<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use finfo;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * HR: Upravlja sigurnim slikovnim datotekama privatne teme jednog područja.
 * EN: Manages safe image files belonging to one workspace's private theme.
 */
final readonly class WorkspaceThemeAssetLibrary
{
    private const MAX_FILE_BYTES = 26_214_400;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    /** @var list<string> */
    private const ROLES = ['hero', 'icon', 'logo', 'other'];

    /**
     * HR: Prima konfiguraciju sigurnog podatkovnog direktorija privatnih tema područja.
     * EN: Receives configuration for the private Workspace theme data directory.
     */
    public function __construct(private WorkspaceConfig $config)
    {
    }

    /**
     * HR: Vraća samo postojeće i validno opisane datoteke privatne teme.
     * EN: Returns only existing and validly described private-theme files.
     *
     * @param array<string, mixed> $theme
     * @return list<array<string, mixed>>
     */
    public function assets(int $workspaceId, array $theme): array
    {
        $assets = [];
        foreach ($this->readManifest($workspaceId)['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $file = is_scalar($asset['file'] ?? null) ? (string)$asset['file'] : '';
            if ($this->assetPath($workspaceId, $file) === null) {
                continue;
            }

            $reference = '@runtime-theme-assets/' . $file;
            $assets[] = $asset + [
                'reference' => $reference,
                'used' => $this->containsReference($theme, $reference),
            ];
        }

        $roleOrder = array_flip(self::ROLES);
        usort($assets, static fn(array $left, array $right): int => [
            $roleOrder[WorkspaceValue::string($left['role'] ?? 'other')] ?? count(self::ROLES),
            strtolower(WorkspaceValue::string($left['file'] ?? '')),
        ] <=> [
            $roleOrder[WorkspaceValue::string($right['role'] ?? 'other')] ?? count(self::ROLES),
            strtolower(WorkspaceValue::string($right['file'] ?? '')),
        ]);

        return WorkspaceValue::rows($assets);
    }

    /**
     * HR: Sprema upload nakon provjere stvarnog MIME-a i sigurnosti SVG sadržaja.
     * EN: Stores an upload after validating its real MIME type and SVG safety.
     *
     * @return array<string, mixed>
     */
    public function upload(int $workspaceId, UploadedFileInterface $uploadedFile, string $role): array
    {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Workspace theme asset upload failed.');
        }

        $role = in_array($role, self::ROLES, true) ? $role : 'other';
        $root = $this->config->workspaceThemePath($workspaceId);
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Workspace theme directory cannot be created.');
        }

        $temporary = $root . DIRECTORY_SEPARATOR . '.upload-' . bin2hex(random_bytes(8));
        $this->storeUpload($uploadedFile, $temporary);

        try {
            [$mime, $extension] = $this->validateMediaFile($temporary, $uploadedFile->getClientFilename());
            $checksum = hash_file('sha256', $temporary);
            if (!is_string($checksum)) {
                throw new RuntimeException('Workspace theme asset checksum cannot be calculated.');
            }

            $manifest = $this->readManifest($workspaceId);
            foreach ($manifest['assets'] as $asset) {
                if (is_array($asset) && ($asset['sha256'] ?? null) === $checksum) {
                    $file = is_scalar($asset['file'] ?? null) ? (string)$asset['file'] : '';
                    if ($this->assetPath($workspaceId, $file) !== null) {
                        return WorkspaceValue::stringKeyArray(
                            $asset + ['reference' => '@runtime-theme-assets/' . $file, 'used' => false],
                        );
                    }
                }
            }

            $file = $this->availableFileName($workspaceId, $uploadedFile->getClientFilename(), $extension);
            $target = $this->config->workspaceThemeAssetsPath($workspaceId) . DIRECTORY_SEPARATOR . $file;
            if (!rename($temporary, $target)) {
                throw new RuntimeException('Workspace theme asset cannot be stored.');
            }

            [$width, $height] = $this->imageDimensions($target, $mime);
            $label = trim(str_replace(['-', '_'], ' ', pathinfo(
                $uploadedFile->getClientFilename() ?? $file,
                PATHINFO_FILENAME,
            )));
            $asset = [
                'file' => $file,
                'role' => $role,
                'label' => ['hr' => $label, 'en' => $label],
                'mime' => $mime,
                'width' => $width,
                'height' => $height,
                'bytes' => filesize($target),
                'sha256' => $checksum,
            ];
            $manifest['assets'][] = $asset;
            $this->writeManifest($workspaceId, $manifest);

            return $asset + ['reference' => '@runtime-theme-assets/' . $file, 'used' => false];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * HR: Kopira već provjeren sistemski asset u privatnu biblioteku i vraća novu referencu.
     * EN: Copies an already validated system asset into the private library and returns its new reference.
     */
    public function copyFile(int $workspaceId, string $sourcePath, string $role, string $label): string
    {
        if (!is_file($sourcePath)) {
            throw new InvalidArgumentException('Source theme asset does not exist.');
        }

        [$mime, $extension] = $this->validateMediaFile($sourcePath, basename($sourcePath));
        $file = $this->availableFileName($workspaceId, basename($sourcePath), $extension);
        $target = $this->config->workspaceThemeAssetsPath($workspaceId) . DIRECTORY_SEPARATOR . $file;
        if (!copy($sourcePath, $target)) {
            throw new RuntimeException('System theme asset cannot be copied to the workspace.');
        }

        [$width, $height] = $this->imageDimensions($target, $mime);
        $checksum = hash_file('sha256', $target);
        $manifest = $this->readManifest($workspaceId);
        $manifest['assets'][] = [
            'file' => $file,
            'role' => in_array($role, self::ROLES, true) ? $role : 'other',
            'label' => ['hr' => $label, 'en' => $label],
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'bytes' => filesize($target),
            'sha256' => is_string($checksum) ? $checksum : '',
        ];
        $this->writeManifest($workspaceId, $manifest);

        return '@runtime-theme-assets/' . $file;
    }

    /**
     * HR: Briše samo nekorišteni asset privatne teme.
     * EN: Deletes only an unused private-theme asset.
     *
     * @param array<string, mixed> $theme
     */
    public function delete(int $workspaceId, string $file, array $theme): void
    {
        $path = $this->assetPath($workspaceId, $file);
        if ($path === null) {
            throw new InvalidArgumentException('Workspace theme asset does not exist.');
        }

        if ($this->containsReference($theme, '@runtime-theme-assets/' . $file)) {
            throw new InvalidArgumentException(
                'A workspace theme asset in use cannot be deleted. Select and save a replacement first.',
            );
        }

        if (!unlink($path)) {
            throw new RuntimeException('Workspace theme asset cannot be deleted.');
        }

        $manifest = $this->readManifest($workspaceId);
        $manifest['assets'] = array_values(array_filter(
            $manifest['assets'],
            static fn(mixed $asset): bool => !is_array($asset) || ($asset['file'] ?? null) !== $file,
        ));
        $this->writeManifest($workspaceId, $manifest);
    }

    /**
     * HR: Nepovratno uklanja isključivo privatni theme direktorij zadanog područja.
     * EN: Irreversibly removes only the supplied Workspace's private-theme directory.
     */
    public function purgeWorkspace(int $workspaceId): void
    {
        if ($workspaceId <= 0) {
            throw new InvalidArgumentException('Workspace theme scope is invalid.');
        }

        $directory = $this->config->workspaceThemePath($workspaceId);
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $path = $item->getPathname();
            $removed = $item->isDir() && !$item->isLink() ? rmdir($path) : unlink($path);
            if (!$removed) {
                throw new RuntimeException('Workspace theme asset cannot be permanently deleted.');
            }
        }

        if (!rmdir($directory)) {
            throw new RuntimeException('Workspace theme directory cannot be permanently deleted.');
        }
    }

    /**
     * HR: Razrješava samo postojeću datoteku sigurnog imena unutar pripadajućeg područja.
     * EN: Resolves only an existing safely named file within its owning Workspace.
     */
    public function assetPath(int $workspaceId, string $file): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $file) !== 1) {
            return null;
        }

        $path = $this->config->workspaceThemePath($workspaceId)
        . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * HR: Čita manifest privatnih datoteka ili vraća prazan manifest prve verzije.
     * EN: Reads the private asset manifest or returns an empty version-one manifest.
     *
     * @return array{version:int,assets:list<mixed>}
     */
    public function readManifest(int $workspaceId): array
    {
        $path = $this->config->workspaceThemePath($workspaceId) . DIRECTORY_SEPARATOR . 'theme-assets.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents) || trim($contents) === '') {
            return ['version' => 1, 'assets' => []];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return [
            'version' => 1,
            'assets' => is_array($decoded) && is_array($decoded['assets'] ?? null)
                ? array_values($decoded['assets'])
                : [],
        ];
    }

    /**
     * HR: Atomski zapisuje normalizirani manifest privatnih datoteka područja.
     * EN: Atomically writes the Workspace's normalized private asset manifest.
     *
     * @param array{version:int,assets:list<mixed>} $manifest
     */
    public function writeManifest(int $workspaceId, array $manifest): void
    {
        $root = $this->config->workspaceThemePath($workspaceId);
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Workspace theme directory cannot be created.');
        }

        $path = $root . DIRECTORY_SEPARATOR . 'theme-assets.json';
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode(
            ['version' => 1, 'assets' => array_values($manifest['assets'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Workspace theme asset manifest cannot be written.');
        }
    }

    /**
     * HR: Tokom kopira upload u privremenu datoteku uz strogo ograničenje veličine.
     * EN: Streams an upload into a temporary file under a strict size limit.
     */
    private function storeUpload(UploadedFileInterface $file, string $path): void
    {
        $target = fopen($path, 'wb');
        if ($target === false) {
            throw new RuntimeException('Workspace theme temporary file cannot be created.');
        }

        $stream = $file->getStream();
        $written = 0;
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (!$stream->eof()) {
                $chunk = $stream->read(1_048_576);
                $written += strlen($chunk);
                if ($written > self::MAX_FILE_BYTES) {
                    throw new InvalidArgumentException('Workspace theme asset is larger than 25 MB.');
                }

                if ($chunk !== '' && fwrite($target, $chunk) === false) {
                    throw new RuntimeException('Workspace theme upload cannot be stored.');
                }
            }
        } finally {
            fclose($target);
        }

        if ($written === 0) {
            throw new InvalidArgumentException('Workspace theme asset is empty.');
        }
    }

    /**
     * HR: Provjerava stvarni slikovni sadržaj i vraća sigurni MIME i nastavak.
     * EN: Validates actual image content and returns its safe MIME type and extension.
     *
     * @return array{string,string}
     */
    private function validateMediaFile(string $path, ?string $clientName): array
    {
        if (strtolower(pathinfo($clientName ?? '', PATHINFO_EXTENSION)) === 'svg') {
            $this->validateSvg($path);
            return ['image/svg+xml', 'svg'];
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime]) || $mime === 'image/svg+xml') {
            throw new InvalidArgumentException('Unsupported workspace theme asset format.');
        }

        if (@getimagesize($path) === false) {
            throw new InvalidArgumentException('Workspace theme asset is not a valid image.');
        }

        return [$mime, self::MIME_EXTENSIONS[$mime]];
    }

    /**
     * HR: Odbacuje SVG s aktivnim elementima, vanjskim poveznicama ili nesigurnim atributima.
     * EN: Rejects SVG files with active elements, external links, or unsafe attributes.
     */
    private function validateSvg(string $path): void
    {
        $contents = file_get_contents($path);
        if (!is_string($contents) || stripos($contents, '<svg') === false) {
            throw new InvalidArgumentException('Uploaded SVG is invalid.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;
        if (!$loaded || !($root instanceof DOMElement) || strtolower($root->localName ?? '') !== 'svg') {
            throw new InvalidArgumentException('Uploaded SVG is invalid.');
        }

        $xpath = new DOMXPath($document);
        $active = $xpath->query(
            '//*[local-name()="script" or local-name()="foreignObject" or local-name()="iframe"'
            . ' or local-name()="object" or local-name()="embed"]',
        );
        if ($active === false || $active->length > 0) {
            throw new InvalidArgumentException('Uploaded SVG contains active content.');
        }

        $attributes = $xpath->query('//@*');
        if ($attributes === false) {
            throw new InvalidArgumentException('Uploaded SVG is invalid.');
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');
            if (
                str_starts_with($name, 'on')
                || (in_array($name, ['href', 'xlink:href'], true) && $value !== '' && !str_starts_with($value, '#'))
                || preg_match('/(?:javascript:|expression\s*\(|@import|url\s*\()/i', $value) === 1
            ) {
                throw new InvalidArgumentException('Uploaded SVG contains unsafe content.');
            }
        }
    }

    /**
     * HR: Izrađuje jedinstveno sigurno ime datoteke bez prepisivanja postojećeg asseta.
     * EN: Builds a unique safe filename without overwriting an existing asset.
     */
    private function availableFileName(int $workspaceId, ?string $clientName, string $extension): string
    {
        $stem = strtolower(pathinfo($clientName ?? 'theme-asset', PATHINFO_FILENAME));
        $stem = trim((string)preg_replace('/[^a-z0-9]+/', '-', $stem), '-');
        $stem = $stem !== '' ? substr($stem, 0, 96) : 'theme-asset';

        $file = $stem . '.' . $extension;
        for ($suffix = 2; $this->assetPath($workspaceId, $file) !== null; ++$suffix) {
            $file = $stem . '-' . $suffix . '.' . $extension;
        }

        return $file;
    }

    /**
     * HR: Čita dimenzije rastera ili SVG viewBoxa za prikaz u biblioteci teme.
     * EN: Reads raster dimensions or the SVG viewBox for display in the theme library.
     *
     * @return array{int,int}
     */
    private function imageDimensions(string $path, string $mime): array
    {
        if ($mime !== 'image/svg+xml') {
            $size = getimagesize($path);
            return is_array($size) ? [$size[0], $size[1]] : [0, 0];
        }

        $document = new DOMDocument();
        $document->load($path, LIBXML_NONET);

        $root = $document->documentElement;
        if (!($root instanceof DOMElement)) {
            return [0, 0];
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if (is_array($viewBox) && count($viewBox) === 4) {
            return [(int)round((float)$viewBox[2]), (int)round((float)$viewBox[3])];
        }

        return [(int)$root->getAttribute('width'), (int)$root->getAttribute('height')];
    }

    /**
     * HR: Rekurzivno provjerava koristi li konfiguracija zadanu privatnu referencu.
     * EN: Recursively checks whether configuration uses the supplied private reference.
     */
    private function containsReference(mixed $value, string $reference): bool
    {
        if (is_string($value)) {
            return $value === $reference;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->containsReference($item, $reference)) {
                return true;
            }
        }

        return false;
    }
}
