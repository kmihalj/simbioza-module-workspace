<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

/**
 * HR: Prenosi završni naziv i binarni sadržaj ZIP izvoza područja prema controlleru.
 * EN: Carries the final filename and binary Workspace ZIP content to the controller.
 */
final readonly class WorkspaceExport
{
    public string $mimeType;

    /**
     * HR: Stvara nepromjenjivi prijenosni objekt ZIP odgovora područja.
     * EN: Creates the immutable transfer object for a Workspace ZIP response.
     */
    public function __construct(
        public string $fileName,
        public string $content,
    ) {
        $this->mimeType = 'application/zip';
    }
}
