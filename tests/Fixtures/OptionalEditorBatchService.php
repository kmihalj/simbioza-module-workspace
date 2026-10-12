<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleEditorHtml\Service;

use stdClass;

/**
 * EN: Test-only optional Editor service without a real Composer dependency.
 *
 * HR: Testni opcionalni Editor servis bez stvarne Composer ovisnosti.
 */
final class EditorService
{
    /**
     * @var array<string, array{title:string,html:string,createdAt:string}>
     */
    public array $documents = [];

    /**
     * @param array<string, int> $versionNumbersByDocument
     * @return array<string, object>
     */
    public function loadVersions(array $versionNumbersByDocument, string $language): array
    {
        if ($language === '') {
            return [];
        }

        $versions = [];
        foreach ($versionNumbersByDocument as $documentId => $versionNumber) {
            $document = $this->documents[$documentId] ?? null;
            if ($document === null) {
                continue;
            }

            $version = new stdClass();
            $version->documentId = $documentId;
            $version->versionNumber = $versionNumber;
            $version->title = $document['title'];
            $version->html = $document['html'];
            $version->createdAt = $document['createdAt'];
            $versions[$documentId] = $version;
        }

        return $versions;
    }
}
