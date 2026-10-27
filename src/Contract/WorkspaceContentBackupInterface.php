<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * EN: Identifies the complete backup owner of a content type for each generic backup scope.
 *
 * HR: Određuje potpunog vlasnika backupa vrste sadržaja za svaki generički opseg.
 */
interface WorkspaceContentBackupInterface
{
    /**
     * EN: Returns a registered backup provider ID, or null when the scope cannot preserve this type.
     *
     * HR: Vraća ID registriranog backup providera ili null kad opseg ne može očuvati ovu vrstu.
     */
    public function backupProviderId(string $scope): ?string;
}
