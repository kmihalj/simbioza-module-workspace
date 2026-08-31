<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Account;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionProviderInterface;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageService;
use HeartPhrame\Routing\UrlGenerator;

/**
 * HR: Dodaje Workspaceov osobni odabir naslovnice u proširivi Auth profil.
 * Auth pritom ne poznaje Workspace ni njegove tablice.
 * EN: Adds Workspace personal homepage selection to the extensible Auth
 * profile. Auth remains unaware of Workspace and its tables.
 */
final readonly class WorkspaceHomepageAccountSectionProvider implements AuthAccountSectionProviderInterface
{
    /**
     * HR: Prima Workspace poslovni servis i generator rute obrasca.
     * EN: Receives the Workspace business service and form route generator.
     */
    public function __construct(
        private WorkspaceHomepageService $homepages,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Vraća modularni partial samo kada je osobni odabir omogućen.
     * EN: Returns the module partial only when personal selection is enabled.
     *
     * @return array{key:string,package:string,partial:string,data:array<string,mixed>,group:string,order:int}|null
     */
    public function sectionForUser(int $userId): ?array
    {
        $data = $this->homepages->accountData($userId);
        if ($data === null) {
            return null;
        }

        $data['savePath'] = $this->urlGenerator->namedRouteExists('workspace.homepage.preference.save')
        ? $this->urlGenerator->getPathFor('workspace.homepage.preference.save')
        : rtrim($this->urlGenerator->getBasePath(), '/') . '/workspaces/homepage/preference';
        $data['assetsJsPath'] = $this->urlGenerator->namedRouteExists('workspace.assets.js')
        ? $this->urlGenerator->getPathFor('workspace.assets.js')
        : rtrim($this->urlGenerator->getBasePath(), '/') . '/workspaces/assets.js';

        return [
            'key' => 'workspace-homepage',
            'package' => ModuleWorkspace::PACKAGE_NAME,
            'partial' => 'workspace/account_homepage',
            'group' => 'personal',
            'order' => 100,
            'data' => $data,
        ];
    }
}
