<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\View\View;
use Psr\Http\Message\ResponseInterface;

final readonly class WorkspaceModuleViewRenderer
{
    /**
     * HR: Prima framework renderere i omogućuje host aplikaciji override Workspace viewa.
     * EN: Receives framework renderers and allows host applications to override Workspace views.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private ConfigInterface $config,
        private View $viewRenderer,
    ) {
    }

    /**
     * HR: Renderira punu stranicu iz aplikacijskog overridea ili modula.
     * EN: Renders a full page from an application override or the module.
     *
     * @param array<string, mixed> $data
     */
    public function render(
        string $view,
        array $data = [],
        null|true|string $layout = true,
        int $status = 200,
    ): ResponseInterface {
        $override = $this->findOverrideView($view);
        if ($override !== null) {
            return $this->responseFactory->view($override, $data, $layout, $status);
        }

        return $this->responseFactory->viewForModule(
            ModuleWorkspace::PACKAGE_NAME,
            $view,
            $data,
            $layout,
            $status,
        );
    }

    /**
     * HR: Renderira parcijalni template za rekurzivno stablo.
     * EN: Renders a partial template for the recursive tree.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $view, array $data = []): string
    {
        $override = $this->findOverrideView($view);
        if ($override !== null) {
            return $this->viewRenderer->for($override, $data);
        }

        return $this->viewRenderer->forModulePartial(ModuleWorkspace::PACKAGE_NAME, $view, $data);
    }

    /**
     * HR: Traži kratku i punu aplikacijsku override putanju.
     * EN: Searches short and fully qualified application override paths.
     */
    private function findOverrideView(string $view): ?string
    {
        $viewsRoot = rtrim($this->config->getAsString('app.views.path') ?? '', '/');
        if ($viewsRoot === '') {
            return null;
        }

        foreach (
            [
                'modules/simbioza-module-workspace/' . $view,
                'modules/aaieduhr/simbioza-module-workspace/' . $view,
            ] as $candidate
        ) {
            if (is_file($viewsRoot . '/' . $candidate . '.php')) {
                return $candidate;
            }
        }

        return null;
    }
}
