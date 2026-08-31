<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * HR: Omogućuje izvedenom Simbioza modulu da dovrši integraciju nakon što su
 *     Workspace servisi registrirani, neovisno o Composerovu redoslijedu paketa.
 * EN: Lets a derived Simbioza module finish its integration after Workspace
 *     services are registered, independently of Composer package order.
 */
interface WorkspaceIntegrationRegistrarInterface
{
    /** HR: Registrira idempotentne integracije modula. EN: Registers idempotent module integrations. */
    public function register(): void;
}
