<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspacePresentationProviderInterface;
use HeartPhrame\Localization\TranslatorInterface;

use function array_values;
use function is_array;
use function trim;

/**
 * EN: Combines optional Workspace presentation adjustments registered by
 *     derived modules, such as a localized personal-space name.
 *
 * HR: Objedinjuje opcionalne prikazne prilagodbe područja koje registriraju
 *     izvedeni moduli, primjerice lokalizirani naziv osobnog područja.
 */
final class WorkspacePresentationRegistry
{
    /** @var list<WorkspacePresentationProviderInterface> */
    private array $providers = [];

    /**
     * EN: Receives the translator for a safe default locale.
     *
     * HR: Prima prevoditelj radi sigurnog zadanog jezika.
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly WorkspaceRepository $repository,
        private readonly WorkspaceConfig $config,
    ) {
    }

    /**
     * EN: Registers a provider only once.
     *
     * HR: Registrira provider samo jednom.
     */
    public function register(WorkspacePresentationProviderInterface $provider): void
    {
        foreach ($this->providers as $registered) {
            if ($registered === $provider || $registered::class === $provider::class) {
                return;
            }
        }

        $this->providers[] = $provider;
    }

    /**
     * EN: Applies every provider to one Workspace.
     *
     * HR: Primjenjuje sve providere na jedno područje.
     *
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    public function one(array $workspace, string $locale = ''): array
    {
        $presented = $this->many([$workspace], $locale);

        return is_array($presented[0] ?? null) ? $presented[0] : $workspace;
    }

    /**
     * EN: Applies providers in batches so Workspace lists do not create N+1 queries.
     *
     * HR: Grupno primjenjuje providere kako popisi područja ne bi stvarali N+1 upite.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function many(array $workspaces, string $locale = ''): array
    {
        $locale = trim($locale) !== '' ? trim($locale) : $this->translator->getLocale();
        $presented = $this->repository->localizeWorkspaces(
            array_values($workspaces),
            $locale,
            $this->config->siteDefaultLanguage(),
        );
        foreach ($this->providers as $provider) {
            $candidate = $provider->present($presented, $locale);
            if (count($candidate) === count($presented)) {
                $presented = array_values($candidate);
            }
        }

        return $presented;
    }
}
