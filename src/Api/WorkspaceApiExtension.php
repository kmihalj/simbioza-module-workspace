<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Api;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface;
use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;

/**
 * HR: Oglašava Workspace API rute generičkoj API jezgri.
 *
 * EN: Advertises Workspace API routes to the generic API core.
 * @see \AaiEduHr\SimbiozaModuleWorkspace\Tests\Api\WorkspaceApiExtensionTest
 */
final readonly class WorkspaceApiExtension implements ApiExtensionInterface
{
    /**
     * HR: Vraća stabilni identifikator Workspace proširenja.
     * EN: Returns the stable Workspace extension identifier.
     */
    public function id(): string
    {
        return 'workspace';
    }

    /**
     * HR: Dodaje Workspace endpointove kroz zajednički sigurni registar.
     * EN: Adds Workspace endpoints through the shared secure registry.
     */
    public function register(ApiRouteRegistry $routes): void
    {
        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $routes->add(
                $method,
                $path,
                WorkspaceResourceController::class,
                $action,
                $name,
            );
        }
    }

    /**
     * HR: Vraća stabilan popis ruta; posebne `deleted` rute dolaze prije dinamičkog sluga.
     * EN: Returns the stable route list; specific `deleted` routes precede the dynamic slug.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/workspaces', 'listWorkspaces', 'api.v1.workspaces.list'],
            ['POST', '/api/v1/workspaces', 'createWorkspace', 'api.v1.workspaces.create'],
            [
                'GET',
                '/api/v1/workspaces/deleted',
                'listDeletedWorkspaces',
                'api.v1.workspaces.deleted.list',
            ],
            [
                'POST',
                '/api/v1/workspaces/deleted/{workspaceId}/restore',
                'restoreWorkspace',
                'api.v1.workspaces.deleted.restore',
            ],
            [
                'GET',
                '/api/v1/workspaces/homepage/settings',
                'getHomepageSettings',
                'api.v1.workspaces.homepage.settings',
            ],
            [
                'PUT',
                '/api/v1/workspaces/homepage/settings',
                'saveHomepageSettings',
                'api.v1.workspaces.homepage.settings.save',
            ],
            [
                'GET',
                '/api/v1/workspaces/homepage/preference',
                'getHomepagePreference',
                'api.v1.workspaces.homepage.preference',
            ],
            [
                'PUT',
                '/api/v1/workspaces/homepage/preference',
                'saveHomepagePreference',
                'api.v1.workspaces.homepage.preference.save',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}', 'getWorkspace', 'api.v1.workspaces.get'],
            [
                'PATCH',
                '/api/v1/workspaces/{workspaceSlug}',
                'updateWorkspace',
                'api.v1.workspaces.update',
            ],
            [
                'DELETE',
                '/api/v1/workspaces/{workspaceSlug}',
                'deleteWorkspace',
                'api.v1.workspaces.delete',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}/tree', 'getTree', 'api.v1.workspaces.tree'],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/shorts',
                'getShorts',
                'api.v1.workspaces.shorts',
            ],
            [
                'POST',
                '/api/v1/workspaces/{workspaceSlug}/exports/html',
                'exportWorkspace',
                'api.v1.workspaces.exports.html',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/theme',
                'getWorkspaceTheme',
                'api.v1.workspaces.theme',
            ],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/theme/selection',
                'selectWorkspaceTheme',
                'api.v1.workspaces.theme.selection',
            ],
            [
                'PATCH',
                '/api/v1/workspaces/{workspaceSlug}/theme',
                'saveWorkspaceTheme',
                'api.v1.workspaces.theme.save',
            ],
            [
                'POST',
                '/api/v1/workspaces/{workspaceSlug}/theme/import',
                'importWorkspaceTheme',
                'api.v1.workspaces.theme.import',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/theme/export',
                'exportWorkspaceTheme',
                'api.v1.workspaces.theme.export',
            ],
            [
                'POST',
                '/api/v1/workspaces/{workspaceSlug}/theme/assets',
                'uploadWorkspaceThemeAsset',
                'api.v1.workspaces.theme.assets.upload',
            ],
            [
                'DELETE',
                '/api/v1/workspaces/{workspaceSlug}/theme/assets/{file}',
                'deleteWorkspaceThemeAsset',
                'api.v1.workspaces.theme.assets.delete',
            ],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/tree/order',
                'reorderTree',
                'api.v1.workspaces.tree.order',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}/acl', 'getWorkspaceAcl', 'api.v1.workspaces.acl'],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/acl',
                'replaceWorkspaceAcl',
                'api.v1.workspaces.acl.replace',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/acl/subjects',
                'searchAclSubjects',
                'api.v1.workspaces.acl.subjects',
            ],
            [
                'POST',
                '/api/v1/workspaces/{workspaceSlug}/nodes',
                'createLinkNode',
                'api.v1.workspaces.nodes.create',
            ],
            [
                'PATCH',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}',
                'updateNode',
                'api.v1.workspaces.nodes.update',
            ],
            [
                'DELETE',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}',
                'deleteLinkNode',
                'api.v1.workspaces.nodes.delete',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}/acl',
                'getNodeAcl',
                'api.v1.workspaces.nodes.acl',
            ],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}/acl',
                'replaceNodeAcl',
                'api.v1.workspaces.nodes.acl.replace',
            ],
        ];
    }
}
