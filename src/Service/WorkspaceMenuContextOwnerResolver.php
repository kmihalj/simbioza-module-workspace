<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleMenu\Service\MenuWorkspaceContextOwnerResolverInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use RuntimeException;

/**
 * EN: Supplies explicit Workspace/page ownership to the optional generic Menu storage.
 * Owners are declared explicitly; context names and arbitrary route patterns are not owners.
 *
 * HR: Daje izričito vlasništvo područja/stranice opcionalnoj generičkoj Menu pohrani.
 * Vlasnici su izričito deklarirani; nazivi konteksta i proizvoljni route patterni nisu vlasnici.
 */
final readonly class WorkspaceMenuContextOwnerResolver implements MenuWorkspaceContextOwnerResolverInterface
{
    /**
     * EN: Receives only domain identities, never GUI state.
     *
     * HR: Prima samo domenske identitete, nikada stanje GUI-a.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * EN: Selects owned backup contexts by workspace identity, not their displayed URL.
     * Shared contexts return null so the generic module can retain the shared-context policy.
     *
     * HR: Odabire vlasničke backup kontekste po identitetu područja, ne prikazanom URL-u.
     * Zajednički konteksti vraćaju null radi zadržavanja politike zajedničkih konteksta generičkog modula.
     *
     * @param array<string,mixed> $context
     */
    public function belongsToWorkspace(array $context, string $slug): ?bool
    {
        $target = $this->ownerTarget($context);
        if ($target === null) {
            return null;
        }

        // EN: Replace preparation may already have removed page nodes; scope ownership uses the workspace ID.
        //
        // HR: Priprema zamjene možda je već uklonila čvorove stranica; vlasništvo opsega koristi ID područja.
        $workspace = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('slug', '=', trim($slug))->first();
        if (!is_array($workspace) || !is_numeric($workspace['id'] ?? null)) {
            return false;
        }

        return (int)explode('.', $target)[1] === (int)$workspace['id'];
    }

    /**
     * EN: Validates the declared owner and returns a stable data-relative directory.
     *
     * HR: Provjerava deklariranog vlasnika i vraća stabilni direktorij relativan podatcima.
     *
     * @param array<string,mixed> $context
     */
    public function directory(array $context): ?string
    {
        $target = $this->ownerTarget($context);
        if ($target === null) {
            return null;
        }

        if (preg_match('/^workspace\.([1-9][0-9]*)(?:\.page\.([1-9][0-9]*))?$/D', $target, $match) !== 1) {
            throw new RuntimeException('Unknown Menu owner target.');
        }

        $workspaceId = (int)$match[1];
        if (
            !is_array($this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', $workspaceId)->first())
        ) {
            throw new RuntimeException('Menu owner Workspace is unavailable.');
        }

        $path = 'workspaces/' . (new WorkspaceDataDirectory($this->database))->workspace($workspaceId);
        if (!isset($match[2])) {
            return $path;
        }

        $node = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)->where('id', '=', (int)$match[2])->first();
        if (!is_array($node)) {
            throw new RuntimeException('Menu owner page is unavailable.');
        }

        $key = $node['document_key'] ?? null;
        // EN: HTML payloads use document UUIDs; non-document tree nodes use their node UUID instead.
        //
        // HR: HTML payload koristi UUID dokumenta; ostali čvorovi stabla koriste vlastiti UUID.
        $uuid = $node['uuid'] ?? null;
        if (is_string($key) && $key !== '' && $this->database->schema()->hasTable('editor_html_documents')) {
            $document = $this->database->table('editor_html_documents')->where('document_key', '=', $key)->first();
            $uuid = is_array($document) ? ($document['uuid'] ?? null) : $uuid;
        }

        if (!is_string($uuid) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $uuid) !== 1) {
            throw new RuntimeException('Invalid Menu owner page UUID.');
        }

        $pageName = WorkspaceDataDirectory::page(
            is_string($key) && $key !== '' ? $key : (string)($node['slug'] ?? ''),
            $uuid,
        );
        if (isset($document)) {
            foreach (['editor_html_document_versions', 'editor_html_assets'] as $table) {
                if (!$this->database->schema()->hasTable($table)) {
                    continue;
                }

                $payload = $this->database->table($table)->where('document_id', '=', $document['id'])
                    ->where('storage_driver', '=', 'filesystem')->first();
                $stored = is_array($payload) ? (string)($payload['content_path'] ?? '') : '';
                $pattern = '#^@data/workspaces/[a-z0-9-]{1,96}--' . $workspaceId
                . '/pages/([a-z0-9-]{1,96}--' . preg_quote($uuid, '#') . ')/#Di';
                if (preg_match($pattern, $stored, $storedPage) === 1) {
                    $pageName = $storedPage[1];
                    break;
                }
            }
        }

        return $path . '/pages/' . $pageName;
    }

    /**
     * EN: Resolves a declared owner, without depending on live page nodes.
     *
     * HR: Razrješava deklariranog vlasnika, bez ovisnosti o postojećim čvorovima.
     *
     * @param array<string,mixed> $context
     */
    private function ownerTarget(array $context): ?string
    {
        $target = $context['owner_target'] ?? '';
        if (!is_string($target)) {
            throw new RuntimeException('Invalid Menu owner target.');
        }

        if ($target === '') {
            return null;
        }

        if (preg_match('/^workspace\.([1-9][0-9]*)(?:\.page\.([1-9][0-9]*))?$/D', $target) !== 1) {
            throw new RuntimeException('Unknown Menu owner target.');
        }

        return $target;
    }
}
