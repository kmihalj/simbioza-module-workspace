<?php

/**
 * HR: Neutralni opis Workspace API scopeova. Workspace ne ovisi o API modulu;
 * API modul čita ovu datoteku samo kada su oba modula instalirana i uključena.
 *
 * EN: Neutral description of Workspace API scopes. Workspace does not depend
 * on the API module; the API module reads this file only when both modules are
 * installed and enabled.
 */

declare(strict_types=1);

return [
    'module' => 'workspace',
    'extension' => \AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceApiExtension::class,
    'resources' => [
        'workspace' => [
            'label' => ['hr' => 'Područja', 'en' => 'Workspaces'],
            'scopes' => [
                'workspace:read' => [
                    'label' => ['hr' => 'Pregled', 'en' => 'Read'],
                    'description' => [
                        'hr' => 'Pregled područja i stabala koja vlasnik API ključa smije vidjeti.',
                        'en' => 'Read workspaces and trees visible to the API key owner.',
                    ],
                ],
                'workspace:manage' => [
                    'label' => ['hr' => 'Upravljanje', 'en' => 'Manage'],
                    'description' => [
                        'hr' => 'Kreiranje, uređivanje, brisanje i vraćanje područja te upravljanje '
                            . 'ACL-om, stablom, temom i naslovnicama, uz obaveznu provjeru korisničkih prava.',
                        'en' => 'Create, update, delete, and restore workspaces and manage ACLs, trees, '
                            . 'themes, and homepages, subject to mandatory user permission checks.',
                    ],
                ],
            ],
        ],
    ],
];
