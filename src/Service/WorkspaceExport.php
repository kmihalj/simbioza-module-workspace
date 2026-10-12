<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

/**
 * EN: Carries the final filename and binary Workspace ZIP content to the controller.
 *
 * HR: Prenosi završni naziv i binarni sadržaj ZIP izvoza područja prema controlleru.
 */
final readonly class WorkspaceExport
{
    public string $mimeType;

    /**
     * EN: Creates the immutable transfer object for a Workspace ZIP response.
     *
     * HR: Stvara nepromjenjivi prijenosni objekt ZIP odgovora područja.
     */
    public function __construct(
        public string $fileName,
        public string $content,
    ) {
        $this->mimeType = 'application/zip';
    }
}
