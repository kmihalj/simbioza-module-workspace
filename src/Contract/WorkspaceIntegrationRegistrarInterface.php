<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * EN: Lets a derived Simbioza module finish its integration after Workspace
 *     services are registered, independently of Composer package order.
 *
 * HR: Omogućuje izvedenom Simbioza modulu da dovrši integraciju nakon što su
 *     Workspace servisi registrirani, neovisno o Composerovu redoslijedu paketa.
 */
interface WorkspaceIntegrationRegistrarInterface
{
    /**
     * EN: Registers idempotent module integrations.
     *
     * HR: Registrira idempotentne integracije modula.
     */
    public function register(): void;
}
