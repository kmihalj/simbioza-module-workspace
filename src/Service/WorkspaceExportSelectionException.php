<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use RuntimeException;

/**
 * EN: Indicates that the selected Workspace or node selection contains no
 *     published page the user may export.
 *
 * HR: Označava da odabrano područje ili izbor čvorova nema nijednu
 *     objavljenu stranicu koju korisnik smije izvesti.
 */
final class WorkspaceExportSelectionException extends RuntimeException
{
}
