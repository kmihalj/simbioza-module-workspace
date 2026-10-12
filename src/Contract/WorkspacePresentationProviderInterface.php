<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * EN: Allows a derived module to change only a Workspace's presentation values
 *     for the requested locale without mutating the stored Workspace record.
 *
 * HR: Omogućuje izvedenom modulu da promijeni isključivo prikazne vrijednosti
 *     područja za traženi jezik, bez promjene spremljenog Workspace zapisa.
 */
interface WorkspacePresentationProviderInterface
{
    /**
     * EN: Returns presentation values for already loaded Workspaces.
     *
     * HR: Vraća prikazne vrijednosti za već učitana područja.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function present(array $workspaces, string $locale): array;
}
