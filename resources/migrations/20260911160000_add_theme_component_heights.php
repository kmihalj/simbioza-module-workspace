<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\MigrationInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeRepository;

return new class implements MigrationInterface {
    /**
     * HR: Privatnim temama područja dodaje dosadašnje visine samo kada novi ključevi nedostaju.
     * EN: Adds legacy heights to private Workspace themes only when the new keys are absent.
     */
    public function up(Database $db): void
    {
        (new WorkspaceThemeRepository($db))->persistMissingComponentHeights();
    }
};
