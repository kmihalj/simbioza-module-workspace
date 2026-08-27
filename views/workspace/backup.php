<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Upraviteljsko sučelje za šifrirani izvoz i nastavivi import područja.
 * EN: Manager UI for encrypted Workspace export and resumable import.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string,mixed> $workspace
 * @var bool $isAdministrator
 * @var string $managePath
 * @var string $createPath
 * @var string $uploadStartPath
 * @var string $uploadChunkPath
 * @var string $uploadFinishPath
 * @var string $preflightPath
 * @var string $restorePath
 * @var string $csrfPath
 * @var string $csrfName
 * @var string $csrfToken
 * @var int $maxArchiveSize
 */
$workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
$workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
$workspaceName = WorkspaceValue::string($workspace['name'] ?? '');
$noFileSelectedJson = json_encode(__('Nije odabrana datoteka'), JSON_UNESCAPED_UNICODE);
$this->addToPlaceholder('head', <<<'HTML'
<style>
    .workspace-backup-grid{display:grid;gap:1rem;grid-template-columns:repeat(2,minmax(0,1fr))}
    .workspace-backup-result{white-space:pre-wrap;overflow-wrap:anywhere}
    .workspace-backup-progress{height:.65rem}
    .workspace-backup-file-control{display:flex;align-items:stretch;min-width:0}
    .workspace-backup-file-control .btn{border-radius:.375rem 0 0 .375rem;white-space:nowrap}
    .workspace-backup-file-name{
        display:flex;align-items:center;min-width:0;flex:1;padding:.375rem .75rem;
        border:1px solid var(--bs-border-color);border-left:0;border-radius:0 .375rem .375rem 0;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        background:var(--bs-body-bg);color:var(--bs-body-color)
    }
    @media(max-width:900px){.workspace-backup-grid{grid-template-columns:1fr}}
</style>
HTML);
?>
<header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?= $this->escape($title) ?></h1>
        <p class="text-body-secondary mb-0">
            <?= $this->escape($workspaceName) ?> · <?= $this->escape($workspaceSlug) ?>
        </p>
    </div>
    <a class="btn btn-secondary" href="<?= $this->escape($managePath) ?>">
        <?= $this->escape(__('Natrag na područje')) ?>
    </a>
</header>

<div class="workspace-backup-grid">
    <section class="card" aria-labelledby="workspace-backup-export-title">
        <div class="card-body">
            <h2 id="workspace-backup-export-title" class="h4">
                <?= $this->escape(__('Izvezi backup područja')) ?>
            </h2>
            <p class="text-body-secondary">
                <?= $this->escape(__(
                    'Arhiv uključuje stranice i povijest, privitke, stablo, ACL, privatnu temu, '
                    . 'posebne menije i obnovu indeksa.',
                )) ?>
            </p>
            <form method="post" action="<?= $this->escape($createPath) ?>">
                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                <div class="mb-3">
                    <label class="form-label" for="workspace-backup-export-passphrase">
                        <?= $this->escape(__('Zaporka arhiva')) ?>
                    </label>
                    <input
                        id="workspace-backup-export-passphrase"
                        class="form-control"
                        type="password"
                        name="passphrase"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                    <div class="form-text">
                        <?= $this->escape(__(
                            'Zaporka štiti sadržaj i ovlasti te se ne sprema u aplikaciji. Sačuvajte je odvojeno.',
                        )) ?>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">
                    <?= $this->escape(__('Preuzmi šifrirani backup')) ?>
                </button>
            </form>
        </div>
    </section>

    <section class="card" aria-labelledby="workspace-backup-import-title">
        <div class="card-body">
            <h2 id="workspace-backup-import-title" class="h4">
                <?= $this->escape(__('Vrati backup područja')) ?>
            </h2>
            <p class="text-body-secondary">
                <?= $this->escape(__(
                    'Datoteka se šalje u nastavivim dijelovima. Prije vraćanja obavezno se provjeravaju '
                    . 'integritet, komponente, identiteti i sukobi.',
                )) ?>
            </p>
            <div class="mb-3">
                <label class="form-label" for="workspace-backup-file">
                    <?= $this->escape(__('Backup datoteka')) ?>
                </label>
                <div class="workspace-backup-file-control">
                    <label class="btn btn-secondary mb-0" for="workspace-backup-file">
                        <?= $this->escape(__('Odaberi datoteku')) ?>
                    </label>
                    <span id="workspace-backup-file-name" class="workspace-backup-file-name" aria-live="polite">
                        <?= $this->escape(__('Nije odabrana datoteka')) ?>
                    </span>
                    <input id="workspace-backup-file" class="visually-hidden" type="file"
                           accept=".zip,application/zip">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="workspace-backup-passphrase">
                    <?= $this->escape(__('Zaporka arhiva')) ?>
                </label>
                <input id="workspace-backup-passphrase" class="form-control"
                       type="password" autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label" for="workspace-backup-target">
                    <?= $this->escape(__('Ciljno područje')) ?>
                </label>
                <input id="workspace-backup-target" class="form-control"
                       value="<?= $this->escape($workspaceSlug) ?>" <?= $isAdministrator ? '' : 'readonly' ?>>
            </div>
            <div class="mb-3">
                <label class="form-label" for="workspace-backup-mode">
                    <?= $this->escape(__('Način vraćanja')) ?>
                </label>
                <select id="workspace-backup-mode" class="form-select">
                    <option value="replace"><?= $this->escape(__('Zamijeni postojeće područje')) ?></option>
                    <option value="merge"><?= $this->escape(__('Spoji s istim područjem')) ?></option>
                    <?php if ($isAdministrator) : ?>
                        <option value="copy"><?= $this->escape(__('Kreiraj novo područje')) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="progress workspace-backup-progress mb-3" role="progressbar"
                 aria-label="<?= $this->escape(__('Napredak prijenosa')) ?>">
                <div class="progress-bar" id="workspace-backup-progress" style="width:0"></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-secondary" type="button" id="workspace-backup-upload">
                    <?= $this->escape(__('Učitaj i provjeri')) ?>
                </button>
                <button class="btn btn-secondary" type="button" id="workspace-backup-preflight" disabled>
                    <?= $this->escape(__('Preflight provjera')) ?>
                </button>
                <button class="btn btn-danger" type="button" id="workspace-backup-restore" disabled>
                    <?= $this->escape(__('Pokreni vraćanje')) ?>
                </button>
            </div>
            <pre class="workspace-backup-result alert alert-info mt-3 d-none" id="workspace-backup-result"></pre>
        </div>
    </section>
</div>

<script>
(() => {
    'use strict';
    const config = <?= json_encode([
        'workspace' => $workspaceSlug,
        'start' => $uploadStartPath,
        'chunk' => $uploadChunkPath,
        'finish' => $uploadFinishPath,
        'preflight' => $preflightPath,
        'restore' => $restorePath,
        'csrf' => $csrfPath,
        'csrfHeader' => 'X-' . str_replace('_', '-', strtoupper($csrfName)),
        'csrfToken' => $csrfToken,
        'maxSize' => $maxArchiveSize,
        'confirm' => __('Vratiti backup područja sada? Postojeći sadržaj može biti promijenjen.'),
        'selectFile' => __('Odaberite backup datoteku.'),
        'requestFailed' => __('Zahtjev nije uspio.'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const element = (id) => document.getElementById(id);
    const result = element('workspace-backup-result');
    const restoreButton = element('workspace-backup-restore');
    const fileInput = element('workspace-backup-file');
    const fileName = element('workspace-backup-file-name');
    let upload = null;
    let preflightPassed = false;

    fileInput.addEventListener('change', () => {
        fileName.textContent = fileInput.files[0]?.name || <?= $noFileSelectedJson ?>;
    });

    const show = (value) => {
        result.classList.remove('d-none');
        result.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    };
    const payload = async (response) => {
        const text = await response.text();
        if (text === '') return {};
        try {
            return JSON.parse(text);
        } catch (_error) {
            return {error: text};
        }
    };
    const refreshCsrf = async () => {
        const response = await fetch(config.csrf, {headers: {Accept: 'application/json'}, cache: 'no-store'});
        const data = await payload(response);
        if (!response.ok || typeof data.csrf_token !== 'string' || data.csrf_token === '') {
            throw new Error(data.error || config.requestFailed);
        }
        config.csrfToken = data.csrf_token;
    };
    const request = async (url, data) => {
        await refreshCsrf();
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', [config.csrfHeader]: config.csrfToken},
            body: JSON.stringify(data),
        });
        const responsePayload = await payload(response);
        if (!response.ok) {
            throw new Error(responsePayload.error || config.requestFailed);
        }
        return responsePayload;
    };
    const input = () => ({
        uuid: upload?.uuid || '',
        workspace: config.workspace,
        target_workspace: element('workspace-backup-target').value.trim(),
        conflict_mode: element('workspace-backup-mode').value,
        passphrase: element('workspace-backup-passphrase').value,
    });

    element('workspace-backup-upload').addEventListener('click', async () => {
        try {
            const file = fileInput.files[0];
            if (!file) throw new Error(config.selectFile);
            if (file.size > config.maxSize) throw new Error(config.requestFailed);
            upload = await request(config.start, {workspace: config.workspace, name: file.name, size: file.size});
            while (upload.next_offset < file.size) {
                const end = Math.min(file.size, upload.next_offset + upload.chunk_size);
                await refreshCsrf();
                const response = await fetch(config.chunk, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/octet-stream',
                        'X-Backup-Upload': upload.uuid,
                        'X-Backup-Offset': String(upload.next_offset),
                        [config.csrfHeader]: config.csrfToken,
                    },
                    body: file.slice(upload.next_offset, end),
                });
                const chunkPayload = await payload(response);
                if (!response.ok) throw new Error(chunkPayload.error || config.requestFailed);
                upload = chunkPayload;
                element('workspace-backup-progress').style.width =
                    Math.round(upload.next_offset / file.size * 100) + '%';
            }
            upload = await request(config.finish, {...input(), workspace: config.workspace});
            element('workspace-backup-preflight').disabled = false;
            show(upload.manifest);
        } catch (error) {
            show(error instanceof Error ? error.message : config.requestFailed);
        }
    });

    element('workspace-backup-preflight').addEventListener('click', async () => {
        try {
            const response = await request(config.preflight, input());
            preflightPassed = Array.isArray(response.errors) && response.errors.length === 0;
            restoreButton.disabled = !preflightPassed;
            show(response);
        } catch (error) {
            preflightPassed = false;
            restoreButton.disabled = true;
            show(error instanceof Error ? error.message : config.requestFailed);
        }
    });

    restoreButton.addEventListener('click', async () => {
        if (!preflightPassed || !window.confirm(config.confirm)) return;
        try {
            const response = await request(config.restore, input());
            restoreButton.disabled = true;
            show(response);
        } catch (error) {
            show(error instanceof Error ? error.message : config.requestFailed);
        }
    });
})();
</script>
