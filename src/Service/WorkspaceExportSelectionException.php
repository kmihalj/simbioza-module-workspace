<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use RuntimeException;

/**
 * HR: Označava da odabrano područje ili izbor čvorova nema nijednu
 *     objavljenu stranicu koju korisnik smije izvesti.
 * EN: Indicates that the selected Workspace or node selection contains no
 *     published page the user may export.
 */
final class WorkspaceExportSelectionException extends RuntimeException
{
}
