<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

/**
 * EN: Resolves declared storage ownership without GUI, authentication or route state.
 * Disabled tree nodes retain ownership; documents shared by workspaces have no single owner.
 *
 * HR: Određuje deklarirano vlasništvo pohrane bez GUI-a, autentikacije i stanja ruta.
 * Isključeni čvorovi zadržavaju vlasništvo; dokument koji dijele područja nema jednog vlasnika.
 */
final readonly class WorkspacePayloadOwner
{
    /**
     * EN: Uses domain ownership records, independent of UI services.
     *
     * HR: Koristi domenske zapise vlasništva, neovisno o UI servisima.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * EN: Returns the unique workspace owner, or null for shared/unowned documents.
     *
     * HR: Vraća jedinog vlasnika područja ili null za zajedničke/nevlasničke dokumente.
     */
    public function storageWorkspaceId(string $documentKey): ?int
    {
        $owners = $this->owners($documentKey);
        return count($owners) === 1 ? $owners[0] : null;
    }

    /**
     * EN: Supplies the same readable owner name as Menu and private themes, without an interactive service.
     *
     * HR: Daje isti čitljivi naziv vlasnika kao Menu i privatne teme, bez interaktivnog servisa.
     */
    public function storageWorkspaceDirectory(int $workspaceId): string
    {
        return (new WorkspaceDataDirectory($this->database))->workspace($workspaceId);
    }

    /**
     * EN: Distinguishes multiple owners from a document without an owner.
     *
     * HR: Razlikuje više vlasnika od dokumenta bez vlasnika.
     */
    public function hasMultipleStorageOwners(string $documentKey): bool
    {
        return count($this->owners($documentKey)) > 1;
    }

    /**
     * EN: Collects distinct owners, including disabled nodes, if Workspace is installed.
     *
     * HR: Prikuplja različite vlasnike, uključujući isključene čvorove, ako je Workspace instaliran.
     *
     * @return list<int>
     */
    private function owners(string $documentKey): array
    {
        if (!$this->database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)) {
            return [];
        }

        $ids = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('document_key', '=', trim($documentKey))->where('node_type', '=', 'document')->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['workspace_id'] ?? null) && (int)$row['workspace_id'] > 0) {
                $ids[(int)$row['workspace_id']] = true;
            }
        }

        return array_keys($ids);
    }
}
