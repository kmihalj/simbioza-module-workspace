<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;

/**
 * HR: Administratorski pregled zauzeća i nepovratno održavanje sadržaja.
 * EN: Administrator storage dashboard and irreversible content maintenance.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var array<string, int> $siteStatistics
 * @var list<array<string, mixed>> $workspaces
 * @var list<array<string, mixed>> $deletedWorkspaces
 * @var string $runPath
 * @var string $imageOptimizePath
 * @var string $purgePath
 * @var string $settingsPath
 * @var string $homepagePath
 * @var string $allPath
 * @var string $deletedPath
 * @var string $maintenancePath
 * @var string $settingsMenuActiveSection
 * @var object|null $menuRenderer
 * @var string $assetsCssPath
 */

$mib = static fn(mixed $bytes): string => number_format(max(0, WorkspaceValue::int($bytes)) / 1048576, 2, ',', '.');
$tr = fn(string $message): string => $this->escape(__($message));
$databaseEstimateNote = $tr(
    'Veličina baze je procjena korisnog sadržaja redaka, ne fizička veličina '
    . 'datoteke baze koja ovisi o sustavu baze i njegovu održavanju.',
);
$referencedAssetNote = $tr(
    'Referencirani privitci ostaju sačuvani dok ih koristi neka zadržana verzija.',
);
$irreversibleNote = $tr(
    'Razumijem da je čišćenje nepovratno i da sadržaj mogu vratiti samo iz backupa.',
);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$keptVersionsLabelJson = json_encode(__('Broj sačuvanih verzija'), $jsonFlags);
$ageDaysLabelJson = json_encode(__('Starost u danima'), $jsonFlags);
?>
<link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">

<div class="row g-4">
    <aside class="col-lg-3">
        <?php require __DIR__ . '/sidebar.php'; ?>
    </aside>

    <div class="col-lg-9">
        <section class="card mb-4">
            <div class="card-body">
                <header class="mb-4">
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $tr('Pregledajte koliko prostora zauzimaju povijest i obrisane stavke prije čišćenja.') ?>
                    </p>
                </header>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-body-secondary small"><?= $tr('Povijesne verzije') ?></div>
                            <div class="fs-4 fw-semibold">
                                <?= WorkspaceValue::int($siteStatistics['history_versions'] ?? 0) ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-body-secondary small"><?= $tr('Obrisane stranice') ?></div>
                            <div class="fs-4 fw-semibold">
                                <?= WorkspaceValue::int($siteStatistics['deleted_documents'] ?? 0) ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-body-secondary small"><?= $tr('Procjena baze') ?></div>
                            <div class="fs-4 fw-semibold"><?= $mib($siteStatistics['database_bytes'] ?? 0) ?> MiB</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-body-secondary small"><?= $tr('Datotečni sustav') ?></div>
                            <div class="fs-4 fw-semibold">
                                <?= $mib($siteStatistics['filesystem_bytes'] ?? 0) ?> MiB
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2"><?= $tr('Povijest') ?></div>
                            <div class="d-flex flex-wrap gap-4">
                                <span>
                                    <span class="text-body-secondary"><?= $tr('Baza') ?>:</span>
                                    <?= $mib($siteStatistics['history_database_bytes'] ?? 0) ?> MiB
                                </span>
                                <span>
                                    <span class="text-body-secondary"><?= $tr('Datoteke') ?>:</span>
                                    <?= $mib($siteStatistics['history_filesystem_bytes'] ?? 0) ?> MiB
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2"><?= $tr('Obrisano') ?></div>
                            <div class="d-flex flex-wrap gap-4">
                                <span>
                                    <span class="text-body-secondary"><?= $tr('Baza') ?>:</span>
                                    <?= $mib($siteStatistics['deleted_database_bytes'] ?? 0) ?> MiB
                                </span>
                                <span>
                                    <span class="text-body-secondary"><?= $tr('Datoteke') ?>:</span>
                                    <?= $mib($siteStatistics['deleted_filesystem_bytes'] ?? 0) ?> MiB
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="small text-body-secondary">
                    <?= $databaseEstimateNote ?>
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col"><?= $tr('Područje') ?></th>
                                <th scope="col" class="text-end"><?= $tr('Povijest') ?></th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Povijest') ?> · <?= $tr('Baza') ?>
                                </th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Povijest') ?> · <?= $tr('Datoteke') ?>
                                </th>
                                <th scope="col" class="text-end"><?= $tr('Obrisano') ?></th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Obrisano') ?> · <?= $tr('Baza') ?>
                                </th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Obrisano') ?> · <?= $tr('Datoteke') ?>
                                </th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Baza') ?> · <?= $tr('Ukupno') ?>
                                </th>
                                <th scope="col" class="text-end text-nowrap">
                                    <?= $tr('Datoteke') ?> · <?= $tr('Ukupno') ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($workspaces as $workspace) : ?>
                            <?php $stats = WorkspaceValue::stringKeyArray($workspace['statistics'] ?? null); ?>
                            <tr>
                                <th scope="row">
                            <?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?>
                                </th>
                                <td class="text-end"><?= WorkspaceValue::int($stats['history_versions'] ?? 0) ?></td>
                                <td class="text-end text-nowrap">
                            <?= $mib($stats['history_database_bytes'] ?? 0) ?> MiB
                                </td>
                                <td class="text-end text-nowrap">
                            <?= $mib($stats['history_filesystem_bytes'] ?? 0) ?> MiB
                                </td>
                                <td class="text-end">
                            <?= WorkspaceValue::int($stats['deleted_documents'] ?? 0)
                            + WorkspaceValue::int($stats['deleted_assets'] ?? 0) ?>
                                </td>
                                <td class="text-end text-nowrap">
                            <?= $mib($stats['deleted_database_bytes'] ?? 0) ?> MiB
                                </td>
                                <td class="text-end text-nowrap">
                            <?= $mib($stats['deleted_filesystem_bytes'] ?? 0) ?> MiB
                                </td>
                                <td class="text-end text-nowrap"><?= $mib($stats['database_bytes'] ?? 0) ?> MiB</td>
                                <td class="text-end text-nowrap"><?= $mib($stats['filesystem_bytes'] ?? 0) ?> MiB</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-lg">
                        <h2 class="h4 mb-1"><?= $tr('Optimizacija slika') ?></h2>
                        <p class="text-body-secondary mb-0">
                            <?= $tr(
                                'Izradite smanjene web-kopije postojećih slika. '
                                . 'Originali ostaju sačuvani i dostupni otvaranjem slike.',
                            ) ?>
                        </p>
                    </div>
                    <div class="col-12 col-lg-auto">
                        <form method="post" action="<?= $this->escape($imageOptimizePath) ?>">
                            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                            <button class="btn btn-primary" type="submit">
                                <?= $tr('Optimiziraj postojeće slike') ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section class="card border-danger">
            <div class="card-body">
                <h2 class="h4 mb-1"><?= $tr('Čišćenje') ?></h2>
                <p class="text-body-secondary mb-4">
                    <?= $tr('Čišćenje je nepovratno. Prije pokretanja izradite potpuni backup sitea.') ?>
                </p>

                <form method="post" action="<?= $this->escape($runPath) ?>" data-workspace-maintenance-form>
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <div class="row g-3">
                        <div class="col-12 col-lg-4">
                            <label class="form-label" for="maintenance-scope"><?= $tr('Opseg') ?></label>
                            <select id="maintenance-scope" class="form-select" name="scope" data-maintenance-scope>
                                <option value="site"><?= $tr('Cijeli site') ?></option>
                                <option value="workspace"><?= $tr('Odabrano područje') ?></option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-8" data-maintenance-workspace hidden>
                            <label class="form-label" for="maintenance-workspace"><?= $tr('Područje') ?></label>
                            <select id="maintenance-workspace" class="form-select" name="workspace_id">
                                <?php foreach ($workspaces as $workspace) : ?>
                                    <option value="<?= WorkspaceValue::int($workspace['id'] ?? 0) ?>">
                                    <?= $this->escape(WorkspaceValue::string($workspace['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="maintenance-history-policy">
                                <?= $tr('Povijest stranica') ?>
                            </label>
                            <select
                                id="maintenance-history-policy"
                                class="form-select"
                                name="history_policy"
                                data-maintenance-history
                            >
                                <option value="none"><?= $tr('Ne diraj povijest') ?></option>
                                <option value="all">
                                    <?= $tr('Ukloni svu povijest osim aktualnih i objavljenih verzija') ?>
                                </option>
                                <option value="keep"><?= $tr('Zadrži posljednjih nekoliko verzija') ?></option>
                                <option value="older">
                                    <?= $tr('Ukloni povijest stariju od odabranog broja dana') ?>
                                </option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-6" data-maintenance-history-value hidden>
                            <label
                                class="form-label"
                                for="maintenance-history-value"
                                data-maintenance-history-label
                            ><?= $tr('Vrijednost') ?></label>
                            <select id="maintenance-history-value" class="form-select" name="history_value"></select>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="maintenance-deleted-days">
                                <?= $tr('Trajno uklanjanje obrisanih stavki') ?>
                            </label>
                            <select id="maintenance-deleted-days" class="form-select" name="deleted_days">
                                <option value="0"><?= $tr('Ne uklanjaj obrisane stavke') ?></option>
                                <option value="10"><?= $tr('Starije od 10 dana') ?></option>
                                <option value="30"><?= $tr('Starije od 30 dana') ?></option>
                                <option value="90"><?= $tr('Starije od 90 dana') ?></option>
                            </select>
                            <div class="form-text">
                                <?= $referencedAssetNote ?>
                            </div>
                        </div>
                        <div class="col-12 d-flex align-items-end">
                            <div class="form-check">
                                <input
                                    id="maintenance-confirm"
                                    class="form-check-input"
                                    type="checkbox"
                                    name="confirm"
                                    value="1"
                                    required
                                >
                                <label class="form-check-label" for="maintenance-confirm">
                                    <?= $irreversibleNote ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-danger" type="submit"><?= $tr('Pokreni održavanje') ?></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card border-danger mt-4">
            <div class="card-body">
                <h2 class="h4 mb-1"><?= $tr('Trajno brisanje područja') ?></h2>
                <p class="text-body-secondary mb-4">
                    <?= $tr(
                        'Trajno se mogu izbrisati samo područja koja su prethodno obrisana. '
                        . 'Uklanjaju se stranice, povijest, privitci, ovlasti, privatna tema i povezani podaci modula.',
                    ) ?>
                </p>
                <?php if ($deletedWorkspaces === []) : ?>
                    <div class="border rounded p-3 text-body-secondary"><?= $tr('Nema obrisanih područja.') ?></div>
                <?php else : ?>
                    <div class="vstack gap-3">
                    <?php foreach ($deletedWorkspaces as $workspace) : ?>
                        <?php
                        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
                        $workspaceName = WorkspaceValue::string($workspace['name'] ?? '');
                        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
                        ?>
                            <form
                                class="border border-danger rounded p-3"
                                method="post"
                                action="<?= $this->escape($purgePath) ?>"
                            >
                        <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                <input type="hidden" name="workspace_id" value="<?= $workspaceId ?>">
                                <input type="hidden" name="return_to" value="maintenance">
                                <input type="hidden" name="confirm" value="1">
                                <div class="row g-3 align-items-end">
                                    <div class="col-12 col-lg-4">
                                        <div class="fw-semibold"><?= $this->escape($workspaceName) ?></div>
                                        <div class="small text-body-secondary font-monospace">
                        <?= $this->escape($workspaceSlug) ?>
                                        </div>
                                    </div>
                                    <div class="col-12 col-lg-5">
                                        <label class="form-label" for="maintenance-purge-slug-<?= $workspaceId ?>">
                        <?= $tr('Za potvrdu upišite točan slug područja') ?>
                                        </label>
                                        <input
                                            id="maintenance-purge-slug-<?= $workspaceId ?>"
                                            class="form-control font-monospace"
                                            name="confirm_slug"
                                            autocomplete="off"
                                            required
                                        >
                                    </div>
                                    <div class="col-12 col-lg-3 d-grid">
                                        <button class="btn btn-danger" type="submit">
                        <?= $tr('Trajno izbriši') ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const form = document.querySelector('[data-workspace-maintenance-form]');
    if (!form) return;
    const scope = form.querySelector('[data-maintenance-scope]');
    const workspace = form.querySelector('[data-maintenance-workspace]');
    const history = form.querySelector('[data-maintenance-history]');
    const valueWrap = form.querySelector('[data-maintenance-history-value]');
    const value = form.querySelector('#maintenance-history-value');
    const label = form.querySelector('[data-maintenance-history-label]');
    const labels = {
        versions: <?= $keptVersionsLabelJson ?>,
        days: <?= $ageDaysLabelJson ?>,
    };
    const refresh = () => {
        workspace.hidden = scope.value !== 'workspace';
        const values = history.value === 'keep' ? [3, 5, 10] : history.value === 'older' ? [10, 30, 90] : [];
        valueWrap.hidden = values.length === 0;
        value.innerHTML = '';
        values.forEach((number) => value.add(new Option(String(number), String(number))));
        label.textContent = history.value === 'keep' ? labels.versions : labels.days;
    };
    scope.addEventListener('change', refresh);
    history.addEventListener('change', refresh);
    refresh();
})();
</script>
