<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceExternalReferenceProviderInterface;
use Throwable;

use function is_array;
use function is_scalar;
use function strtolower;
use function trim;

/**
 * EN: Holds optional external-reference resolvers without coupling Workspace to
 *     a concrete importer.
 *
 * HR: Drži opcionalne resolvere vanjskih oznaka bez vezivanja Workspace modula
 *     uz konkretan importer.
 */
final class WorkspaceExternalReferenceRegistry
{
    /** @var array<string,WorkspaceExternalReferenceProviderInterface> */
    private array $providers = [];

    /**
     * EN: Registers one provider idempotently.
     *
     * HR: Registrira jedan provider idempotentno.
     */
    public function register(WorkspaceExternalReferenceProviderInterface $provider): void
    {
        $name = strtolower(trim($provider->provider()));
        if ($name !== '') {
            $this->providers[$name] = $provider;
        }
    }

    /**
     * EN: Safely resolves an external reference; an optional provider failure
     *     must not break page rendering.
     *
     * HR: Sigurno razrješava vanjsku oznaku; kvar opcionalnog providera ne smije
     *     prekinuti prikaz stranice.
     *
     * @return array{slug:string,title:string}|null
     */
    public function resolve(string $provider, string $reference): ?array
    {
        $provider = strtolower(trim($provider));
        $reference = trim($reference);
        if ($provider === '' || $reference === '' || !isset($this->providers[$provider])) {
            return null;
        }

        try {
            $resolved = $this->providers[$provider]->resolve($reference);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($resolved)) {
            return null;
        }

        $slug = is_scalar($resolved['slug'] ?? null) ? trim((string)$resolved['slug']) : '';
        if ($slug === '') {
            return null;
        }

        $title = is_scalar($resolved['title'] ?? null) ? trim((string)$resolved['title']) : '';

        return ['slug' => $slug, 'title' => $title !== '' ? $title : $slug];
    }
}
