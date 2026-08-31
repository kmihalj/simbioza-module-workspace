<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Api;

use RuntimeException;

/**
 * HR: Predstavlja očekivanu Workspace API grešku sa stabilnim kodom i HTTP statusom.
 *
 * EN: Represents an expected Workspace API failure with a stable code and HTTP status.
 */
final class WorkspaceApiException extends RuntimeException
{
    /**
     * HR: Sprema javni kod greške, sigurnu poruku i pripadajući HTTP status.
     *
     * EN: Stores the public error code, safe message, and matching HTTP status.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
