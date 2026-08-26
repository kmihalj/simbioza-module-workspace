(function () {
    'use strict';

    /**
     * HR: Kodira običan tekst prije umetanja u HTML poruke učitavanja.
     * EN: Encodes plain text before inserting it into HTML loading messages.
     *
     * @param {string} value
     * @returns {string}
     */
    function escapeHtml(value) {
        const container = document.createElement('span');
        container.textContent = String(value || '');
        return container.innerHTML;
    }

    /**
     * HR: Prikazuje samo kontrole koje pripadaju odabranoj vrsti stavke stabla
     * i onemogućuje skrivena polja kako se ne bi slala u POST zahtjevu.
     *
     * EN: Shows only controls belonging to the selected tree item type and
     * disables hidden fields so they are not submitted in the POST request.
     *
     * @param {HTMLElement} container
     * @returns {void}
     */
    function synchronizeNodeFields(container) {
        const typeControl = container.querySelector('[data-workspace-node-type]');
        if (!(typeControl instanceof HTMLSelectElement)) {
            return;
        }

        const selectedType = typeControl.value;
        container.querySelectorAll('[data-workspace-node-types]').forEach((group) => {
            const allowedTypes = String(group.dataset.workspaceNodeTypes || '')
                .split(' ')
                .filter(Boolean);
            const isVisible = allowedTypes.includes(selectedType);

            group.hidden = !isVisible;
            group.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !isVisible;
            });
        });
    }

    /**
     * HR: Povezuje sve napredne obrasce stabla nakon učitavanja dokumenta.
     * EN: Connects every advanced tree form after the document has loaded.
     *
     * @param {ParentNode} [root=document]
     * @returns {void}
     */
    function initializeNodeForms(root = document) {
        root.querySelectorAll('[data-workspace-node-fields]').forEach((container) => {
            if (container.dataset.workspaceNodeFieldsReady === '1') {
                return;
            }

            const typeControl = container.querySelector('[data-workspace-node-type]');
            if (!(typeControl instanceof HTMLSelectElement)) {
                return;
            }

            container.dataset.workspaceNodeFieldsReady = '1';
            typeControl.addEventListener('change', () => {
                synchronizeNodeFields(container);
            });
            synchronizeNodeFields(container);
        });
    }

    /**
     * HR: Vraća retke jednog vizualnog organizatora redom kojim su prikazani.
     * EN: Returns rows of one visual organizer in their displayed order.
     *
     * @param {HTMLElement} list
     * @returns {HTMLElement[]}
     */
    function treeRows(list) {
        return Array.from(list.querySelectorAll('[data-workspace-tree-order-row]'));
    }

    /**
     * HR: Čita tehnički ID stranice iz jednog retka organizatora.
     * EN: Reads the technical page ID from one organizer row.
     *
     * @param {HTMLElement} row
     * @returns {string}
     */
    function treeNodeId(row) {
        const input = row.querySelector('.workspace-tree-node-id');
        return input instanceof HTMLInputElement ? input.value.trim() : '';
    }

    /**
     * HR: Vraća skriveno polje roditelja jednog retka.
     * EN: Returns the hidden parent field of one row.
     *
     * @param {HTMLElement} row
     * @returns {HTMLInputElement|null}
     */
    function treeParentInput(row) {
        const input = row.querySelector('.workspace-tree-parent-id');
        return input instanceof HTMLInputElement ? input : null;
    }

    /**
     * HR: Čita ID roditelja, pri čemu prazan tekst označava korijen.
     * EN: Reads the parent ID, where an empty string denotes the tree root.
     *
     * @param {HTMLElement} row
     * @returns {string}
     */
    function treeParentId(row) {
        const input = treeParentInput(row);
        return input instanceof HTMLInputElement ? input.value.trim() : '';
    }

    /**
     * HR: Pronalazi red prema ID-u unutar istog organizatora.
     * EN: Finds a row by ID inside the same organizer.
     *
     * @param {HTMLElement} list
     * @param {string} nodeId
     * @returns {HTMLElement|null}
     */
    function treeFindRow(list, nodeId) {
        if (nodeId === '') {
            return null;
        }

        return treeRows(list).find((row) => treeNodeId(row) === nodeId) || null;
    }

    /**
     * HR: Izračunava dubinu retka hodanjem prema korijenu i prekida mogući ciklus.
     * EN: Calculates row depth by walking toward the root and stops a possible cycle.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} row
     * @returns {number}
     */
    function treeLevelFor(list, row) {
        let level = 0;
        let cursor = treeFindRow(list, treeParentId(row));
        const seen = new Set([treeNodeId(row)]);

        while (cursor instanceof HTMLElement) {
            const cursorId = treeNodeId(cursor);
            if (cursorId === '' || seen.has(cursorId)) {
                break;
            }

            seen.add(cursorId);
            level += 1;
            cursor = treeFindRow(list, treeParentId(cursor));
        }

        return level;
    }

    /**
     * HR: Provjerava pripada li red podgrani zadanog pretka.
     * EN: Checks whether a row belongs to the specified ancestor subtree.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} row
     * @param {string} ancestorId
     * @returns {boolean}
     */
    function treeIsDescendantOf(list, row, ancestorId) {
        let cursorId = treeParentId(row);
        const seen = new Set();

        while (cursorId !== '') {
            if (cursorId === ancestorId) {
                return true;
            }
            if (seen.has(cursorId)) {
                return false;
            }

            seen.add(cursorId);
            const parentRow = treeFindRow(list, cursorId);
            if (!(parentRow instanceof HTMLElement)) {
                return false;
            }
            cursorId = treeParentId(parentRow);
        }

        return false;
    }

    /**
     * HR: Vraća odabrani red i sve njegove uzastopno prikazane potomke.
     * EN: Returns the selected row and all of its consecutively displayed descendants.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} rootRow
     * @returns {HTMLElement[]}
     */
    function treeSubtreeRows(list, rootRow) {
        const rootId = treeNodeId(rootRow);
        if (rootId === '') {
            return [rootRow];
        }

        const rows = treeRows(list);
        const start = rows.indexOf(rootRow);
        const block = [rootRow];
        for (let index = start + 1; index < rows.length; index += 1) {
            if (!treeIsDescendantOf(list, rows[index], rootId)) {
                break;
            }
            block.push(rows[index]);
        }

        return block;
    }

    /**
     * HR: Pronalazi najbližu prethodnu stavku istog roditelja.
     * EN: Finds the nearest preceding item with the same parent.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} row
     * @returns {HTMLElement|null}
     */
    function treePreviousSibling(list, row) {
        const rows = treeRows(list);
        const parentId = treeParentId(row);
        return rows
            .slice(0, rows.indexOf(row))
            .reverse()
            .find((candidate) => treeParentId(candidate) === parentId) || null;
    }

    /**
     * HR: Pronalazi najbližu sljedeću stavku istog roditelja iza cijele podgrane.
     * EN: Finds the nearest following item with the same parent after the complete subtree.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} row
     * @returns {HTMLElement|null}
     */
    function treeNextSibling(list, row) {
        const rows = treeRows(list);
        const block = treeSubtreeRows(list, row);
        const start = rows.indexOf(block[block.length - 1]) + 1;
        const parentId = treeParentId(row);
        return rows.slice(start).find((candidate) => treeParentId(candidate) === parentId) || null;
    }

    /**
     * HR: Provjerava smije li stavka postati roditelj druge stavke.
     * EN: Checks whether an item may become another item's parent.
     *
     * @param {HTMLElement|null} row
     * @returns {boolean}
     */
    function treeCanBeParent(row) {
        return row instanceof HTMLElement && row.dataset.canParent === '1';
    }

    /**
     * HR: Premješta cijeli blok prije zadane točke ili na kraj popisa.
     * EN: Moves a complete block before the specified anchor or to the list end.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement[]} block
     * @param {Element|null} anchor
     * @returns {void}
     */
    function treeMoveBlock(list, block, anchor) {
        block.forEach((blockRow) => {
            list.insertBefore(blockRow, anchor);
        });
    }

    /**
     * HR: Sinkronizira uvlake, redoslijed među braćom i dostupnost strelica.
     * EN: Synchronizes indentation, sibling order, and arrow availability.
     *
     * @param {HTMLElement} list
     * @returns {void}
     */
    function refreshTreeOrganizer(list) {
        const siblingPositions = new Map();
        treeRows(list).forEach((row) => {
            const parentId = treeParentId(row);
            const position = (siblingPositions.get(parentId) || 0) + 1;
            siblingPositions.set(parentId, position);

            const sortInput = row.querySelector('.workspace-tree-sort-order');
            if (sortInput instanceof HTMLInputElement) {
                sortInput.value = String(position * 10);
            }

            const label = row.querySelector('.workspace-tree-order-label');
            if (label instanceof HTMLElement) {
                label.style.setProperty('--workspace-tree-level', String(treeLevelFor(list, row)));
            }

            const previous = treePreviousSibling(list, row);
            const next = treeNextSibling(list, row);
            const parent = treeFindRow(list, treeParentId(row));
            const grandparent = parent instanceof HTMLElement
                ? treeFindRow(list, treeParentId(parent))
                : null;
            const canUseRoot = list.dataset.canUseRoot === '1';

            const up = row.querySelector('[data-workspace-tree-action="up"]');
            const down = row.querySelector('[data-workspace-tree-action="down"]');
            const indent = row.querySelector('[data-workspace-tree-action="indent"]');
            const outdent = row.querySelector('[data-workspace-tree-action="outdent"]');
            if (up instanceof HTMLButtonElement) {
                up.disabled = !(previous instanceof HTMLElement);
            }
            if (down instanceof HTMLButtonElement) {
                down.disabled = !(next instanceof HTMLElement);
            }
            if (indent instanceof HTMLButtonElement) {
                indent.disabled = !treeCanBeParent(previous);
            }
            if (outdent instanceof HTMLButtonElement) {
                outdent.disabled = !(parent instanceof HTMLElement)
                    || (grandparent === null && !canUseRoot)
                    || (grandparent !== null && !treeCanBeParent(grandparent));
            }
        });
    }

    /**
     * HR: Povezuje strelice i završnu sinkronizaciju jednoga organizatora.
     * EN: Connects arrows and final synchronization for one organizer.
     *
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function initializeTreeOrganizer(form) {
        const list = form.querySelector('[data-workspace-tree-order-list]');
        if (!(list instanceof HTMLElement)) {
            return;
        }

        list.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const button = target.closest('[data-workspace-tree-action]');
            const row = target.closest('[data-workspace-tree-order-row]');
            if (
                !(button instanceof HTMLButtonElement)
                || button.disabled
                || !(row instanceof HTMLElement)
            ) {
                return;
            }

            const action = button.dataset.workspaceTreeAction || '';
            if (action === 'up') {
                const previous = treePreviousSibling(list, row);
                if (previous instanceof HTMLElement) {
                    treeMoveBlock(list, treeSubtreeRows(list, row), previous);
                }
            } else if (action === 'down') {
                const next = treeNextSibling(list, row);
                if (next instanceof HTMLElement) {
                    const nextBlock = treeSubtreeRows(list, next);
                    treeMoveBlock(
                        list,
                        treeSubtreeRows(list, row),
                        nextBlock[nextBlock.length - 1].nextElementSibling,
                    );
                }
            } else if (action === 'indent') {
                const newParent = treePreviousSibling(list, row);
                const input = treeParentInput(row);
                if (treeCanBeParent(newParent) && input instanceof HTMLInputElement) {
                    input.value = treeNodeId(newParent);
                }
            } else if (action === 'outdent') {
                const oldParent = treeFindRow(list, treeParentId(row));
                const input = treeParentInput(row);
                if (oldParent instanceof HTMLElement && input instanceof HTMLInputElement) {
                    const parentBlock = treeSubtreeRows(list, oldParent);
                    const anchor = parentBlock[parentBlock.length - 1].nextElementSibling;
                    treeMoveBlock(list, treeSubtreeRows(list, row), anchor);
                    input.value = treeParentId(oldParent);
                }
            }

            refreshTreeOrganizer(list);
        });

        form.addEventListener('submit', () => {
            refreshTreeOrganizer(list);
        });
        refreshTreeOrganizer(list);
    }

    /**
     * HR: Povezuje sve vizualne organizatore stabla na stranici.
     * EN: Connects every visual tree organizer on the page.
     *
     * @returns {void}
     */
    function initializeTreeOrganizers() {
        document.querySelectorAll('[data-workspace-tree-order-form]').forEach((form) => {
            if (form instanceof HTMLFormElement) {
                initializeTreeOrganizer(form);
            }
        });
    }

    /**
     * HR: Prebacuje lijevu karticu između običnog stabla i organizatora bez
     * napuštanja prikazane stranice.
     *
     * EN: Switches the left card between the regular tree and organizer without
     * leaving the displayed page.
     *
     * @returns {void}
     */
    function initializeTreeEditModes() {
        document.querySelectorAll('[data-workspace-tree-edit-toggle]').forEach((toggle) => {
            if (!(toggle instanceof HTMLButtonElement)) {
                return;
            }

            const card = toggle.closest('.workspace-tree-card');
            const treeView = card?.querySelector('[data-workspace-tree-view]');
            const treeEditor = card?.querySelector('[data-workspace-tree-editor]');
            if (!(treeView instanceof HTMLElement) || !(treeEditor instanceof HTMLElement)) {
                return;
            }

            toggle.addEventListener('click', async () => {
                const editing = treeEditor.hidden;
                treeEditor.hidden = !editing;
                treeView.hidden = editing;
                toggle.setAttribute('aria-pressed', editing ? 'true' : 'false');
                toggle.classList.toggle('active', editing);

                if (editing) {
                    if (treeEditor.dataset.workspaceTreeEditorReady !== '1') {
                        const url = treeEditor.dataset.workspaceTreeEditorUrl || '';
                        const loading = treeEditor.dataset.workspaceTreeEditorLoading || '';
                        const errorMessage = treeEditor.dataset.workspaceTreeEditorError || '';
                        treeEditor.innerHTML = `<p class="text-body-secondary mb-0">${escapeHtml(loading)}</p>`;

                        try {
                            const response = await fetch(url, {
                                headers: {'X-Requested-With': 'XMLHttpRequest'},
                                credentials: 'same-origin',
                            });
                            const html = await response.text();
                            if (!response.ok) {
                                throw new Error(html || errorMessage);
                            }

                            treeEditor.innerHTML = html;
                            treeEditor.dataset.workspaceTreeEditorReady = '1';
                            treeEditor.querySelectorAll('[data-workspace-lazy-modal]').forEach((modal) => {
                                if (modal instanceof HTMLElement) {
                                    document.body.append(modal);
                                    initializeNodeForms(modal);
                                }
                            });
                            const form = treeEditor.querySelector('[data-workspace-tree-order-form]');
                            if (form instanceof HTMLFormElement) {
                                initializeTreeOrganizer(form);
                            }
                        } catch (_error) {
                            treeEditor.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(errorMessage)}</div>`;
                            return;
                        }
                    }

                    const list = treeEditor.querySelector('[data-workspace-tree-order-list]');
                    if (list instanceof HTMLElement) {
                        refreshTreeOrganizer(list);
                    }
                }
            });
        });
    }

    /**
     * HR: Povezuje strelice za sažimanje grana samo u read-only stablu.
     *     Organizator poretka koristi zaseban prikaz i uvijek zadržava sve razine.
     * EN: Connects branch-collapse arrows only in the read-only tree. The order
     *     organizer uses a separate view and always keeps every level visible.
     *
     * @returns {void}
     */
    function initializeReadableTrees() {
        document.querySelectorAll('[data-workspace-tree-view]').forEach((tree) => {
            if (!(tree instanceof HTMLElement) || tree.dataset.workspaceReadableTreeReady === '1') {
                return;
            }

            tree.dataset.workspaceReadableTreeReady = '1';
            const treeKey = tree.dataset.workspaceTreeKey || '';
            const storageKey = treeKey !== ''
                ? `heartphrame.workspace.tree.v1.${treeKey}`
                : '';
            const scrollStorageKey = treeKey !== ''
                ? `heartphrame.workspace.tree.scroll.v1.${treeKey}`
                : '';
            let storedExpandedNodes = null;
            let storedScrollTop = null;

            if (storageKey !== '') {
                try {
                    const storedValue = window.sessionStorage.getItem(storageKey);
                    const parsedValue = storedValue === null ? null : JSON.parse(storedValue);
                    if (Array.isArray(parsedValue)) {
                        storedExpandedNodes = new Set(parsedValue.filter((value) => (
                            typeof value === 'string' && value !== ''
                        )));
                    }
                } catch (_error) {
                    storedExpandedNodes = null;
                }
            }

            if (scrollStorageKey !== '') {
                try {
                    const storedValue = window.sessionStorage.getItem(scrollStorageKey);
                    const parsedValue = storedValue === null
                        ? Number.NaN
                        : Number.parseFloat(storedValue);
                    if (Number.isFinite(parsedValue) && parsedValue >= 0) {
                        storedScrollTop = parsedValue;
                    }
                } catch (_error) {
                    storedScrollTop = null;
                }
            }

            /**
             * HR: Sprema samo otvorene grane ovog područja u trenutačnu karticu
             *     preglednika. Nedostupan sessionStorage ne prekida navigaciju.
             * EN: Stores only this Workspace's expanded branches in the current
             *     browser tab. Unavailable sessionStorage never blocks navigation.
             *
             * @returns {void}
             */
            const persistExpandedBranches = () => {
                if (storageKey === '') {
                    return;
                }

                const expandedNodeIds = storedExpandedNodes instanceof Set
                    ? Array.from(storedExpandedNodes)
                    : [];

                try {
                    window.sessionStorage.setItem(storageKey, JSON.stringify(expandedNodeIds));
                } catch (_error) {
                    // HR: Privatni način rada može zabraniti pohranu; stablo i dalje radi.
                    // EN: Private browsing may deny storage; the tree still works.
                }
            };

            /**
             * HR: Sprema vertikalni položaj stabla prije navigacije kako klik
             *     na stranicu ne bi vratio dugačko stablo na njegov početak.
             * EN: Stores the tree's vertical position before navigation so a
             *     page click never returns a long tree to its beginning.
             *
             * @returns {void}
             */
            const persistTreeScrollPosition = () => {
                if (scrollStorageKey === '') {
                    return;
                }

                try {
                    window.sessionStorage.setItem(
                        scrollStorageKey,
                        String(Math.max(0, tree.scrollTop)),
                    );
                } catch (_error) {
                    // HR: Nedostupna pohrana ne smije zaustaviti navigaciju.
                    // EN: Unavailable storage must never block navigation.
                }
            };

            /**
             * HR: Usklađuje vidljivost grane, stanje gumba i njegov pristupačni
             *     opis. Ista se rutina koristi prije i nakon naknadnog učitavanja.
             * EN: Synchronizes branch visibility, button state, and its accessible
             *     label. The same routine is used before and after lazy loading.
             *
             * @param {HTMLButtonElement} toggle
             * @param {HTMLElement} branch
             * @param {boolean} expanded
             * @returns {void}
             */
            const setBranchState = (toggle, branch, expanded) => {
                branch.hidden = !expanded;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                const label = expanded
                    ? toggle.dataset.expandedLabel
                    : toggle.dataset.collapsedLabel;
                if (typeof label === 'string' && label !== '') {
                    toggle.setAttribute('aria-label', label);
                    toggle.setAttribute('title', label);
                }
            };

            const loadingText = tree.dataset.workspaceTreeLoading || '';
            const errorText = tree.dataset.workspaceTreeError || '';
            const branchRequests = new WeakMap();

            /**
             * HR: Učitava samo zatraženu, već ACL-filtriranu granu. Istodobni
             *     klikovi dijele isti zahtjev kako se grana ne bi duplicirala.
             * EN: Loads only the requested, already ACL-filtered branch. Concurrent
             *     clicks share one request so the branch is never duplicated.
             *
             * @param {HTMLButtonElement} toggle
             * @param {HTMLElement} branch
             * @returns {Promise<boolean>}
             */
            const loadBranch = async (toggle, branch) => {
                if (branch.dataset.workspaceTreeLoaded === '1') {
                    return true;
                }

                const pendingRequest = branchRequests.get(branch);
                if (pendingRequest instanceof Promise) {
                    return pendingRequest;
                }

                const branchUrl = toggle.dataset.workspaceTreeBranchUrl || '';
                if (branchUrl === '') {
                    return false;
                }

                const request = (async () => {
                    toggle.disabled = true;
                    branch.setAttribute('aria-busy', 'true');
                    if (loadingText !== '') {
                        branch.textContent = loadingText;
                    }

                    try {
                        const response = await window.fetch(branchUrl, {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'text/html',
                            },
                        });
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        branch.innerHTML = await response.text();
                        branch.dataset.workspaceTreeLoaded = '1';
                        branch.removeAttribute('aria-busy');
                        toggle.disabled = false;

                        return true;
                    } catch (_error) {
                        branch.removeAttribute('aria-busy');
                        branch.textContent = errorText;
                        toggle.disabled = false;

                        return false;
                    } finally {
                        branchRequests.delete(branch);
                    }
                })();

                branchRequests.set(branch, request);

                return request;
            };

            /**
             * HR: Primjenjuje spremljeno stanje na već isporučene čvorove i po
             *     potrebi slijedno vraća dublje grane iz trenutačne kartice.
             * EN: Applies saved state to delivered nodes and, when needed,
             *     sequentially restores deeper branches for the current tab.
             *
             * @param {HTMLElement} container
             * @returns {Promise<void>}
             */
            const restoreBranches = async (container) => {
                const toggles = Array.from(
                    container.querySelectorAll('[data-workspace-tree-branch-toggle]'),
                );
                for (const toggle of toggles) {
                    if (!(toggle instanceof HTMLButtonElement) || toggle.dataset.workspaceTreeReady === '1') {
                        continue;
                    }

                    const node = toggle.closest('[data-workspace-tree-node]');
                    const branchId = toggle.getAttribute('aria-controls') || '';
                    const branch = branchId !== '' ? document.getElementById(branchId) : null;
                    if (!(node instanceof HTMLElement)
                        || !(branch instanceof HTMLElement)
                        || !tree.contains(branch)) {
                        continue;
                    }

                    toggle.dataset.workspaceTreeReady = '1';
                    const level = Number.parseInt(node.dataset.workspaceTreeLevel || '1', 10);
                    const nodeId = node.dataset.workspaceTreeNode || '';
                    const containsActivePage = branch.querySelector('[aria-current="page"]') !== null;
                    const expanded = containsActivePage || (storedExpandedNodes instanceof Set
                        ? storedExpandedNodes.has(nodeId)
                        : level === 1);
                    setBranchState(toggle, branch, expanded);

                    if (expanded && branch.dataset.workspaceTreeLoaded !== '1') {
                        const loaded = await loadBranch(toggle, branch);
                        if (loaded) {
                            await restoreBranches(branch);
                        }
                    }
                }
            };

            /**
             * HR: Bez spremljenog stanja pokazuje prvu razinu i put do aktivne
             *     stranice. Inače vraća otvorene grane, ali uvijek prisilno
             *     otvara pretke aktivne stranice. Stanje se priprema i kada je
             *     cijeli mobilni panel skriven.
             * EN: Without stored state, shows the first level and active-page
             *     path. Otherwise restores expanded branches but always forces
             *     active-page ancestors open. State is prepared even while the
             *     entire mobile panel is hidden.
             */
            /**
             * HR: Najprije vraća otvorene i naknadno učitane grane, a zatim
             *     u dva okvira iscrtavanja vraća spremljeni položaj stabla.
             * EN: Restores expanded and lazy-loaded branches first, then uses
             *     two animation frames to restore the saved tree position.
             *
             * @returns {Promise<void>}
             */
            const restoreTreeState = async () => {
                await restoreBranches(tree);
                if (storedScrollTop === null) {
                    return;
                }

                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        const maximumScrollTop = Math.max(0, tree.scrollHeight - tree.clientHeight);
                        tree.scrollTop = Math.min(storedScrollTop, maximumScrollTop);
                    });
                });
            };

            void restoreTreeState();

            let scrollPersistenceFrame = null;
            tree.addEventListener('scroll', () => {
                if (scrollPersistenceFrame !== null) {
                    return;
                }

                scrollPersistenceFrame = window.requestAnimationFrame(() => {
                    scrollPersistenceFrame = null;
                    persistTreeScrollPosition();
                });
            }, { passive: true });

            window.addEventListener('pagehide', persistTreeScrollPosition);

            tree.addEventListener('click', async (event) => {
                const link = event.target instanceof Element
                    ? event.target.closest('a[href]')
                    : null;
                if (link instanceof HTMLAnchorElement && tree.contains(link)) {
                    persistTreeScrollPosition();
                }

                const target = event.target instanceof Element
                    ? event.target.closest('[data-workspace-tree-branch-toggle]')
                    : null;
                if (!(target instanceof HTMLButtonElement)) {
                    return;
                }

                const branchId = target.getAttribute('aria-controls') || '';
                const branch = branchId !== '' ? document.getElementById(branchId) : null;
                if (!(branch instanceof HTMLElement) || !tree.contains(branch)) {
                    return;
                }

                const nextExpanded = target.getAttribute('aria-expanded') !== 'true';
                if (nextExpanded && branch.dataset.workspaceTreeLoaded !== '1') {
                    const loaded = await loadBranch(target, branch);
                    if (!loaded) {
                        setBranchState(target, branch, true);
                        return;
                    }
                    await restoreBranches(branch);
                }

                setBranchState(target, branch, nextExpanded);
                const node = target.closest('[data-workspace-tree-node]');
                const nodeId = node instanceof HTMLElement
                    ? node.dataset.workspaceTreeNode || ''
                    : '';
                if (!(storedExpandedNodes instanceof Set)) {
                    storedExpandedNodes = new Set();
                }
                if (nodeId !== '') {
                    if (nextExpanded) {
                        storedExpandedNodes.add(nodeId);
                    } else {
                        storedExpandedNodes.delete(nodeId);
                    }
                }
                persistExpandedBranches();
            });
        });
    }

    /**
     * HR: Pretvara CSS duljinu stabla (npr. rem) u piksele bez pretpostavke o
     *     korisnikovoj veličini fonta. Privremeni mjerač nije vidljiv niti
     *     utječe na raspored stranice.
     * EN: Converts a tree CSS length (for example rem) to pixels without
     *     assuming the user's font size. The temporary ruler is invisible and
     *     does not affect page layout.
     *
     * @param {HTMLElement} context
     * @param {string} value
     * @returns {number}
     */
    function workspaceCssLengthInPixels(context, value) {
        const ruler = document.createElement('span');
        ruler.style.cssText = [
            'display:block',
            'height:0',
            'inset:0 auto auto 0',
            'overflow:hidden',
            'pointer-events:none',
            'position:fixed',
            'visibility:hidden',
            `width:${value}`,
        ].join(';');
        context.appendChild(ruler);
        const width = ruler.getBoundingClientRect().width;
        ruler.remove();

        return width;
    }

    /**
     * HR: Mjeri naslov u jednom retku s njegovim stvarnim fontom. Tako stablo
     *     ostaje kompaktno za kratke nazive, ali se može proširiti prije nego
     *     što dugi nazivi počnu stvarati nepotrebne retke od jedne riječi.
     * EN: Measures a title on one line with its actual font. This keeps the
     *     tree compact for short names while allowing it to grow before long
     *     names create unnecessary one-word lines.
     *
     * @param {HTMLElement} title
     * @returns {number}
     */
    function workspaceTreeTitleNaturalWidth(title) {
        const style = window.getComputedStyle(title);
        const ruler = document.createElement('span');
        ruler.textContent = title.textContent || '';
        ruler.style.cssText = [
            'display:inline-block',
            `font:${style.font}`,
            `font-kerning:${style.fontKerning}`,
            `letter-spacing:${style.letterSpacing}`,
            'inset:0 auto auto 0',
            'pointer-events:none',
            'position:fixed',
            'visibility:hidden',
            'white-space:nowrap',
        ].join(';');
        document.body.appendChild(ruler);
        const width = ruler.getBoundingClientRect().width;
        ruler.remove();

        return width;
    }

    /**
     * HR: Prilagođava samo desktop stupac stabla stvarnom sadržaju unutar
     *     zadanog kompaktnog i maksimalnog raspona. Mobilni drawer zadržava
     *     svoju postojeću širinu, a glavni sadržaj i breadcrumb automatski
     *     prate istu CSS varijablu.
     * EN: Adapts only the desktop tree column to its real content within the
     *     configured compact and maximum range. The mobile drawer keeps its
     *     existing width, while main content and breadcrumbs automatically
     *     follow the same CSS variable.
     *
     * @returns {void}
     */
    function initializeAdaptiveTreeWidths() {
        const desktopQuery = window.matchMedia('(min-width: 992px)');

        document.querySelectorAll('.workspace-shell').forEach((shell) => {
            if (!(shell instanceof HTMLElement)) {
                return;
            }

            const sidebar = shell.querySelector(':scope > .workspace-sidebar');
            const tree = sidebar?.querySelector('[data-workspace-tree-view]');
            const cardBody = sidebar?.querySelector('.workspace-tree-card > .card-body');
            if (!(sidebar instanceof HTMLElement)
                || !(tree instanceof HTMLElement)
                || !(cardBody instanceof HTMLElement)) {
                return;
            }

            const synchronizeWidth = () => {
                shell.style.removeProperty('--workspace-tree-width');
                if (!desktopQuery.matches) {
                    return;
                }

                const shellStyle = window.getComputedStyle(shell);
                const minimum = workspaceCssLengthInPixels(
                    shell,
                    shellStyle.getPropertyValue('--workspace-tree-min-width').trim() || '18rem',
                );
                const maximum = workspaceCssLengthInPixels(
                    shell,
                    shellStyle.getPropertyValue('--workspace-tree-max-width').trim() || '22rem',
                );
                const bodyStyle = window.getComputedStyle(cardBody);
                const bodyPadding = Number.parseFloat(bodyStyle.paddingInlineStart || '0')
                    + Number.parseFloat(bodyStyle.paddingInlineEnd || '0');
                let requiredWidth = minimum;

                tree.querySelectorAll('.workspace-tree-link-title').forEach((title) => {
                    if (!(title instanceof HTMLElement)) {
                        return;
                    }

                    const row = title.closest('.workspace-tree-row');
                    const link = title.closest('.workspace-tree-link');
                    if (!(row instanceof HTMLElement) || !(link instanceof HTMLElement)) {
                        return;
                    }

                    const rowStyle = window.getComputedStyle(row);
                    const linkStyle = window.getComputedStyle(link);
                    const toggle = row.querySelector(
                        '.workspace-tree-branch-toggle, .workspace-tree-branch-spacer',
                    );
                    const status = link.querySelector('.workspace-tree-status');
                    const indentation = Number.parseFloat(rowStyle.paddingInlineStart || '0');
                    const linkPadding = Number.parseFloat(linkStyle.paddingInlineStart || '0')
                        + Number.parseFloat(linkStyle.paddingInlineEnd || '0');
                    const toggleWidth = toggle instanceof HTMLElement
                        ? Number.parseFloat(window.getComputedStyle(toggle).width || '0')
                        : 0;
                    const statusWidth = status instanceof HTMLElement
                        ? status.getBoundingClientRect().width
                        : 0;
                    const titleWidth = workspaceTreeTitleNaturalWidth(title);

                    // HR: Mali dodatak pokriva razmak flexa i eventualni scrollbar.
                    // EN: A small allowance covers the flex gap and a possible scrollbar.
                    requiredWidth = Math.max(
                        requiredWidth,
                        bodyPadding + indentation + toggleWidth + linkPadding
                            + statusWidth + titleWidth + 20,
                    );
                });

                const resolvedWidth = Math.min(maximum, Math.max(minimum, requiredWidth));
                shell.style.setProperty('--workspace-tree-width', `${Math.ceil(resolvedWidth)}px`);
            };

            synchronizeWidth();
            desktopQuery.addEventListener('change', synchronizeWidth);
            window.addEventListener('resize', synchronizeWidth, { passive: true });
            document.fonts?.ready.then(synchronizeWidth).catch(() => {});
        });
    }

    /**
     * HR: Vraća lagani početni sadržaj zajedničkog modala dok se postavke
     * odabranog čvora učitavaju sa servera.
     *
     * EN: Returns lightweight initial content for the shared modal while the
     * selected node settings are loaded from the server.
     *
     * @param {string} message
     * @param {string} closeLabel
     * @returns {string}
     */
    function nodeDialogPlaceholder(message, closeLabel) {
        const safeMessage = document.createElement('div');
        const safeCloseLabel = document.createElement('div');
        safeMessage.textContent = message;
        safeCloseLabel.textContent = closeLabel;

        return '<div class="modal-header">'
            + '<h2 class="modal-title fs-5">'
            + safeMessage.innerHTML
            + '</h2>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="'
            + safeCloseLabel.innerHTML
            + '"></button>'
            + '</div><div class="modal-body"><p class="text-body-secondary mb-0">'
            + safeMessage.innerHTML
            + '</p></div>';
    }

    /**
     * HR: Premješta sve Workspace modale iz tematskog stacking contexta pod
     *     `body`, uz Bootstrap backdrop kojem pripadaju. Obuhvaća statičke
     *     popise, dodavanje stavke i dinamički modal postavki čvora.
     *
     * EN: Moves every Workspace modal out of the Theme stacking context and
     *     under `body`, beside its Bootstrap backdrop. This covers static
     *     lists, item creation, and the dynamic node-settings dialog.
     *
     * @returns {void}
     */
    function initializeModalPortals() {
        document.querySelectorAll('.workspace-shell ~ .modal, .workspace-shell .modal')
            .forEach((modal) => {
                if (modal instanceof HTMLElement && modal.parentElement !== document.body) {
                    document.body.append(modal);
                }
            });
    }

    /**
     * HR: U jedan zajednički Bootstrap modal učitava obrazac, ograničenja i
     * brisanje tek za čvor čiju je edit ikonu korisnik odabrao.
     *
     * EN: Loads the form, restrictions, and delete action into one shared
     * Bootstrap modal only for the node whose edit icon the user selected.
     *
     * @returns {void}
     */
    function initializeNodeDialog() {
        const modal = document.querySelector('[data-workspace-node-editor-modal]');
        if (!(modal instanceof HTMLElement)) {
            return;
        }

        /*
         * HR: Modal mora biti izravan potomak bodyja. Hero i tematski layouti
         * mogu stvarati vlastiti stacking context pa bi lokalni modal završio
         * ispod Bootstrap backdroppa i postao potpuno neklikabilan.
         *
         * EN: The modal must be a direct body child. Hero and theme layouts may
         * create their own stacking contexts, leaving a nested modal below the
         * Bootstrap backdrop and therefore completely unclickable.
         */
        if (modal.parentElement !== document.body) {
            document.body.append(modal);
        }

        const content = modal.querySelector('.modal-content');
        if (!(content instanceof HTMLElement)) {
            return;
        }

        const loadingMessage = modal.dataset.workspaceNodeDialogLoading || 'Loading...';
        const errorMessage = modal.dataset.workspaceNodeDialogError || 'Unable to load settings.';
        const closeLabel = modal.dataset.workspaceNodeDialogClose || 'Close';
        let requestController = null;

        document.addEventListener('click', async (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const trigger = target.closest('[data-workspace-node-dialog-url]');
            if (!(trigger instanceof HTMLElement)) {
                return;
            }

            const url = trigger.dataset.workspaceNodeDialogUrl || '';
            if (url === '') {
                return;
            }

            if (requestController instanceof AbortController) {
                requestController.abort();
            }
            requestController = new AbortController();
            content.innerHTML = nodeDialogPlaceholder(loadingMessage, closeLabel);

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    signal: requestController.signal,
                });
                const html = await response.text();
                if (!response.ok && html.trim() === '') {
                    throw new Error(errorMessage);
                }

                content.innerHTML = html;
                initializeNodeForms(modal);
                initializeAclControls(modal);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                content.innerHTML = nodeDialogPlaceholder(errorMessage, closeLabel);
            }
        });

        modal.addEventListener('hidden.bs.modal', () => {
            if (requestController instanceof AbortController) {
                requestController.abort();
            }
            content.innerHTML = nodeDialogPlaceholder(loadingMessage, closeLabel);
        });
    }

    /**
     * HR: Osvježava poruku prazne ACL tablice nakon dodavanja ili uklanjanja subjekta.
     * EN: Refreshes the empty ACL-table message after adding or removing a subject.
     *
     * @param {HTMLElement} section
     * @returns {void}
     */
    function refreshAclEmptyState(section) {
        const emptyRow = section.querySelector('[data-workspace-acl-empty]');
        const assignedRows = section.querySelectorAll('[data-workspace-acl-row]');
        if (emptyRow instanceof HTMLTableRowElement) {
            emptyRow.hidden = assignedRows.length > 0;
        }
    }

    /**
     * HR: Stvara malu SVG ikonu uklanjanja bez umetanja korisničkog HTML-a.
     * EN: Creates a small removal SVG icon without inserting user-provided HTML.
     *
     * @returns {SVGElement}
     */
    function aclRemoveIcon() {
        const namespace = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(namespace, 'svg');
        const path = document.createElementNS(namespace, 'path');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('viewBox', '0 0 24 24');
        path.setAttribute('d', 'M6 6l12 12M18 6L6 18');
        svg.append(path);

        return svg;
    }

    /**
     * HR: Dodaje odabrani imenik-subjekt u odgovarajuću ACL tablicu i zadano
     *     mu uključuje pregled. Javno dobiva isključivo pravo pregleda.
     * EN: Adds a selected directory subject to its ACL table and enables view
     *     by default. Public receives view permission only.
     *
     * @param {HTMLFormElement} form
     * @param {Object} subject
     * @returns {void}
     */
    function addAclSubjectRow(form, subject) {
        const category = String(subject.category || '');
        const subjectType = String(subject.type || '');
        const subjectId = String(subject.id || '');
        const label = String(subject.label || '');
        const key = subjectType + ':' + subjectId;
        if (
            !['user', 'group'].includes(category)
            || subjectType === ''
            || subjectId === ''
            || label === ''
            || form.querySelector('[data-workspace-acl-row="' + CSS.escape(key) + '"]')
        ) {
            return;
        }

        const section = form.querySelector('[data-workspace-acl-section="' + category + '"]');
        const body = section?.querySelector('[data-workspace-acl-rows="' + category + '"]');
        if (!(section instanceof HTMLElement) || !(body instanceof HTMLTableSectionElement)) {
            return;
        }

        const row = document.createElement('tr');
        row.dataset.workspaceAclRow = key;
        const heading = document.createElement('th');
        heading.scope = 'row';
        heading.append(document.createTextNode(label));
        if (Boolean(subject.is_builtin)) {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary ms-1';
            badge.textContent = String(form.dataset.workspaceBuiltInLabel || 'Built-in');
            heading.append(badge);
        }
        row.append(heading);

        ['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'].forEach((permission) => {
            const cell = document.createElement('td');
            const checkbox = document.createElement('input');
            cell.className = 'text-center';
            checkbox.className = 'form-check-input';
            checkbox.type = 'checkbox';
            checkbox.name = 'acl[' + subjectType + '][' + subjectId + '][' + permission + ']';
            checkbox.value = '1';
            checkbox.checked = permission === 'can_view';
            checkbox.disabled = Boolean(subject.is_read_only) && permission !== 'can_view';
            const permissionLabel = form.getAttribute(
                'data-workspace-permission-' + permission.replaceAll('_', '-') + '-label',
            ) || permission;
            checkbox.setAttribute('aria-label', permissionLabel + ': ' + label);
            cell.append(checkbox);
            row.append(cell);
        });

        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');
        const removeLabel = String(form.dataset.workspaceRemoveLabel || 'Remove');
        actionCell.className = 'text-end';
        removeButton.className = 'btn btn-sm btn-link text-danger workspace-acl-remove';
        removeButton.type = 'button';
        removeButton.title = removeLabel;
        removeButton.setAttribute('aria-label', removeLabel + ': ' + label);
        removeButton.dataset.workspaceAclRemove = '';
        removeButton.append(aclRemoveIcon());
        actionCell.append(removeButton);
        row.append(actionCell);

        const emptyRow = body.querySelector('[data-workspace-acl-empty]');
        body.insertBefore(row, emptyRow);
        refreshAclEmptyState(section);
    }

    /**
     * HR: Dodaje korisnika ili grupu u popis subjekata koji smiju kreirati područja.
     * EN: Adds a user or group to the subjects allowed to create workspaces.
     *
     * @param {HTMLFormElement} form
     * @param {Object} subject
     * @returns {void}
     */
    function addCreatorSubjectRow(form, subject) {
        const category = String(subject.category || '');
        const id = String(subject.id || '');
        const label = String(subject.label || '');
        const key = category + ':' + id;
        if (
            !['user', 'group'].includes(category)
            || String(subject.type || '') !== category
            || id === ''
            || label === ''
            || form.querySelector('[data-workspace-creator-row="' + CSS.escape(key) + '"]')
        ) {
            return;
        }

        const section = form.querySelector('[data-workspace-creator-section="' + category + '"]');
        const body = section?.querySelector('[data-workspace-creator-rows="' + category + '"]');
        if (!(body instanceof HTMLTableSectionElement)) {
            return;
        }

        const row = document.createElement('tr');
        const heading = document.createElement('th');
        const hidden = document.createElement('input');
        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');
        const removeLabel = String(form.dataset.workspaceRemoveLabel || 'Remove');
        row.dataset.workspaceCreatorRow = key;
        heading.scope = 'row';
        heading.append(document.createTextNode(label));
        hidden.type = 'hidden';
        hidden.name = category === 'user' ? 'creator_users[]' : 'creator_groups[]';
        hidden.value = id;
        heading.append(hidden);
        row.append(heading);

        actionCell.className = 'text-end';
        removeButton.className = 'btn btn-sm btn-link text-danger workspace-acl-remove';
        removeButton.type = 'button';
        removeButton.title = removeLabel;
        removeButton.setAttribute('aria-label', removeLabel + ': ' + label);
        removeButton.dataset.workspaceCreatorRemove = '';
        removeButton.append(aclRemoveIcon());
        actionCell.append(removeButton);
        row.append(actionCell);

        const emptyRow = body.querySelector('[data-workspace-creator-empty]');
        body.insertBefore(row, emptyRow);
        if (emptyRow instanceof HTMLTableRowElement) {
            emptyRow.hidden = true;
        }
    }

    /**
     * HR: Osvježava prazno stanje tablice korisničkih ograničenja.
     * EN: Refreshes the user-restriction table's empty state.
     *
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function refreshRestrictionEmptyState(form) {
        const emptyRow = form.querySelector('[data-workspace-restriction-empty]');
        const assignedRows = form.querySelectorAll('[data-workspace-restriction-row]');
        if (emptyRow instanceof HTMLTableRowElement) {
            emptyRow.hidden = assignedRows.length > 0;
        }
    }

    /**
     * HR: Dodaje korisnika s njegovim stvarno naslijeđenim pravima u tablicu
     *     ograničenja. Zelena kvačica može se isključiti u crveno uskraćivanje.
     * EN: Adds a user with their actually inherited rights to the restriction
     *     table. A green check can be switched off into a red denial.
     *
     * @param {HTMLFormElement} form
     * @param {Object} subject
     * @returns {void}
     */
    function addRestrictionUserRow(form, subject) {
        const userId = String(subject.id || subject.subject_id || '');
        const label = String(subject.label || '');
        if (
            String(subject.type || '') !== 'user'
            || userId === ''
            || label === ''
            || form.querySelector('[data-workspace-restriction-row="' + CSS.escape(userId) + '"]')
        ) {
            return;
        }

        const body = form.querySelector('[data-workspace-restriction-rows]');
        if (!(body instanceof HTMLTableSectionElement)) {
            return;
        }

        const row = document.createElement('tr');
        row.dataset.workspaceRestrictionRow = userId;
        const heading = document.createElement('th');
        const selected = document.createElement('input');
        heading.scope = 'row';
        heading.append(document.createTextNode(label));
        selected.type = 'hidden';
        selected.name = 'acl[user][' + userId + '][_selected]';
        selected.value = '1';
        heading.append(selected);
        row.append(heading);

        ['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'].forEach((permission) => {
            const cell = document.createElement('td');
            const inherited = Boolean(subject[permission]);
            const permissionLabel = form.getAttribute(
                'data-workspace-permission-' + permission.replaceAll('_', '-') + '-label',
            ) || permission;
            cell.className = 'text-center';
            if (inherited) {
                const toggle = document.createElement('label');
                const checkbox = document.createElement('input');
                const state = document.createElement('span');
                toggle.className = 'workspace-node-restriction-toggle';
                checkbox.className = 'visually-hidden';
                checkbox.type = 'checkbox';
                checkbox.name = 'acl[user][' + userId + '][' + permission + ']';
                checkbox.value = '1';
                checkbox.checked = true;
                checkbox.dataset.workspaceRestrictionPermission = permission;
                checkbox.setAttribute('aria-label', permissionLabel + ': ' + label);
                state.setAttribute('aria-hidden', 'true');
                toggle.append(checkbox, state);
                cell.append(toggle);
            } else {
                const unavailable = document.createElement('span');
                unavailable.className = 'workspace-node-restriction-unavailable';
                unavailable.setAttribute('aria-label', permissionLabel + ': ' + label);
                cell.append(unavailable);
            }
            row.append(cell);
        });

        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');
        const removeLabel = String(form.dataset.workspaceRemoveLabel || 'Remove');
        actionCell.className = 'text-end';
        removeButton.className = 'btn btn-sm btn-link text-danger workspace-acl-remove';
        removeButton.type = 'button';
        removeButton.title = removeLabel;
        removeButton.setAttribute('aria-label', removeLabel + ': ' + label);
        removeButton.dataset.workspaceRestrictionRemove = '';
        removeButton.append(aclRemoveIcon());
        actionCell.append(removeButton);
        row.append(actionCell);

        const emptyRow = body.querySelector('[data-workspace-restriction-empty]');
        body.insertBefore(row, emptyRow);
        refreshRestrictionEmptyState(form);
    }

    /**
     * HR: Održava ovisnosti prava u jednom retku ograničenja.
     * EN: Keeps permission dependencies consistent in one restriction row.
     *
     * @param {HTMLTableRowElement} row
     * @param {HTMLInputElement} changed
     * @returns {void}
     */
    function normalizeRestrictionRow(row, changed) {
        const inputs = {};
        row.querySelectorAll('[data-workspace-restriction-permission]').forEach((input) => {
            if (input instanceof HTMLInputElement) {
                inputs[String(input.dataset.workspaceRestrictionPermission || '')] = input;
            }
        });
        const setChecked = (permissions, checked) => {
            permissions.forEach((permission) => {
                if (inputs[permission] instanceof HTMLInputElement) {
                    inputs[permission].checked = checked;
                }
            });
        };
        const permission = String(changed.dataset.workspaceRestrictionPermission || '');

        if (changed.checked) {
            if (permission === 'can_manage') {
                setChecked(['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete'], true);
            } else if (permission === 'can_delete') {
                setChecked(['can_view', 'can_edit'], true);
            } else if (['can_add', 'can_edit', 'can_publish'].includes(permission)) {
                setChecked(['can_view'], true);
            }
            return;
        }

        if (permission === 'can_view') {
            setChecked(['can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'], false);
        } else if (permission === 'can_edit') {
            setChecked(['can_delete', 'can_manage'], false);
        } else if (['can_add', 'can_publish', 'can_delete'].includes(permission)) {
            setChecked(['can_manage'], false);
        }
    }

    /**
     * HR: Osvježava prazno stanje tablice izravnih korisničkih prava.
     * EN: Refreshes the direct-user-grant table's empty state.
     *
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function refreshDirectPermissionEmptyState(form) {
        const emptyRow = form.querySelector('[data-workspace-direct-permission-empty]');
        const assignedRows = form.querySelectorAll('[data-workspace-direct-permission-row]');
        if (emptyRow instanceof HTMLTableRowElement) {
            emptyRow.hidden = assignedRows.length > 0;
        }
    }

    /**
     * HR: Dodaje aktivnog korisnika u tablicu izravnih prava i zadano uključuje čitanje.
     * EN: Adds an active user to the direct-grant table and enables reading by default.
     *
     * @param {HTMLFormElement} form
     * @param {Object} subject
     * @returns {void}
     */
    function addDirectPermissionRow(form, subject) {
        const userId = String(subject.id || '');
        const label = String(subject.label || '');
        if (
            String(subject.type || '') !== 'user'
            || userId === ''
            || label === ''
            || form.querySelector('[data-workspace-direct-permission-row="' + CSS.escape(userId) + '"]')
        ) {
            return;
        }

        const body = form.querySelector('[data-workspace-direct-permission-rows]');
        if (!(body instanceof HTMLTableSectionElement)) {
            return;
        }

        const row = document.createElement('tr');
        row.dataset.workspaceDirectPermissionRow = userId;
        const heading = document.createElement('th');
        heading.scope = 'row';
        heading.textContent = label;
        row.append(heading);

        ['can_view', 'can_edit', 'can_publish'].forEach((permission) => {
            const cell = document.createElement('td');
            const checkbox = document.createElement('input');
            const permissionLabel = form.getAttribute(
                'data-workspace-permission-' + permission.replaceAll('_', '-') + '-label',
            ) || permission;
            cell.className = 'text-center';
            checkbox.className = 'form-check-input';
            checkbox.type = 'checkbox';
            checkbox.name = 'direct_permissions[' + userId + '][' + permission + ']';
            checkbox.value = '1';
            checkbox.checked = permission === 'can_view';
            checkbox.setAttribute('aria-label', permissionLabel + ': ' + label);
            cell.append(checkbox);
            row.append(cell);
        });

        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');
        const removeLabel = String(form.dataset.workspaceRemoveLabel || 'Remove');
        actionCell.className = 'text-end';
        removeButton.className = 'btn btn-sm btn-link text-danger workspace-acl-remove';
        removeButton.type = 'button';
        removeButton.title = removeLabel;
        removeButton.setAttribute('aria-label', removeLabel + ': ' + label);
        removeButton.dataset.workspaceDirectPermissionRemove = '';
        removeButton.append(aclRemoveIcon());
        actionCell.append(removeButton);
        row.append(actionCell);

        const emptyRow = body.querySelector('[data-workspace-direct-permission-empty]');
        body.insertBefore(row, emptyRow);
        refreshDirectPermissionEmptyState(form);
    }

    /**
     * HR: Zatvara jedan popis rezultata i vraća ispravno ARIA stanje comboboxa.
     * EN: Closes one result list and restores the correct combobox ARIA state.
     *
     * @param {HTMLInputElement} input
     * @param {HTMLElement} results
     * @returns {void}
     */
    function closeSubjectResults(input, results) {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    /**
     * HR: Ispisuje dohvaćene rezultate kao tipkovnicom dostupne gumbe odabira.
     * EN: Renders fetched results as keyboard-accessible selection buttons.
     *
     * @param {HTMLElement} picker
     * @param {HTMLInputElement} input
     * @param {HTMLElement} results
     * @param {Object[]} subjects
     * @returns {void}
     */
    function renderSubjectResults(picker, input, results, subjects) {
        const mode = String(picker.dataset.workspacePickerMode || 'acl');
        const form = picker.closest('form');
        results.replaceChildren();

        const visibleSubjects = subjects.filter((subject) => {
            if (!(form instanceof HTMLFormElement)) {
                return true;
            }

            if (mode === 'direct-permission') {
                const userId = String(subject.id || '');
                return !form.querySelector(
                    '[data-workspace-direct-permission-row="' + CSS.escape(userId) + '"]',
                );
            }
            if (mode === 'restriction') {
                const userId = String(subject.id || subject.subject_id || '');
                return !form.querySelector(
                    '[data-workspace-restriction-row="' + CSS.escape(userId) + '"]',
                );
            }
            if (mode === 'creator') {
                const key = String(subject.category || '') + ':' + String(subject.id || '');
                return !form.querySelector(
                    '[data-workspace-creator-row="' + CSS.escape(key) + '"]',
                );
            }
            if (mode !== 'acl') {
                return true;
            }

            const key = String(subject.type || '') + ':' + String(subject.id || '');
            return !form.querySelector('[data-workspace-acl-row="' + CSS.escape(key) + '"]');
        });

        if (visibleSubjects.length === 0) {
            const message = document.createElement('div');
            message.className = 'list-group-item text-body-secondary';
            message.textContent = String(picker.dataset.workspaceNoResults || 'No results.');
            results.append(message);
        } else {
            visibleSubjects.forEach((subject) => {
                const option = document.createElement('button');
                option.className = 'list-group-item list-group-item-action';
                option.type = 'button';
                option.role = 'option';
                option.textContent = String(subject.label || '');
                option.addEventListener('click', () => {
                    if (mode === 'direct-permission' && form instanceof HTMLFormElement) {
                        addDirectPermissionRow(form, subject);
                        input.value = '';
                    } else if (mode === 'restriction' && form instanceof HTMLFormElement) {
                        addRestrictionUserRow(form, subject);
                        input.value = '';
                    } else if (mode === 'creator' && form instanceof HTMLFormElement) {
                        addCreatorSubjectRow(form, subject);
                        input.value = '';
                    } else if (form instanceof HTMLFormElement) {
                        addAclSubjectRow(form, subject);
                        input.value = '';
                    }
                    closeSubjectResults(input, results);
                });
                results.append(option);
            });
        }

        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    /**
     * HR: Dohvaća ograničene rezultate imenika uz prekid prethodnog upita i
     *     zaštitu od odgovora koji stigne nakon novijeg unosa.
     * EN: Fetches bounded directory results while cancelling the previous
     *     request and preventing stale responses from replacing newer input.
     *
     * @param {HTMLElement} picker
     * @param {HTMLInputElement} input
     * @param {HTMLElement} results
     * @param {{controller: AbortController|null}} state
     * @returns {Promise<void>}
     */
    async function searchSubjects(picker, input, results, state) {
        if (state.controller instanceof AbortController) {
            state.controller.abort();
        }
        const minimumLength = Number.parseInt(
            String(picker.dataset.workspaceMinQueryLength || '0'),
            10,
        );
        if (input.value.trim().length < minimumLength) {
            results.replaceChildren();
            closeSubjectResults(input, results);
            return;
        }
        state.controller = new AbortController();

        const url = new URL(String(picker.dataset.workspaceSearchUrl || ''), window.location.href);
        url.searchParams.set('type', String(picker.dataset.workspaceSubjectType || ''));
        url.searchParams.set('q', input.value.trim());
        const workspaceId = String(picker.dataset.workspaceId || '');
        if (workspaceId !== '' && workspaceId !== '0') {
            url.searchParams.set('workspace_id', workspaceId);
        }
        const mode = String(picker.dataset.workspacePickerMode || '');
        if (mode !== '') {
            url.searchParams.set('mode', mode);
        }
        const nodeId = String(picker.dataset.workspaceNodeId || '');
        if (nodeId !== '' && nodeId !== '0') {
            url.searchParams.set('node_id', nodeId);
        }

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: state.controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || payload.ok !== true || !Array.isArray(payload.results)) {
                throw new Error(String(payload.error || 'Search failed.'));
            }
            renderSubjectResults(picker, input, results, payload.results);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            results.replaceChildren();
            const message = document.createElement('div');
            message.className = 'list-group-item text-danger';
            message.textContent = String(picker.dataset.workspaceSearchError || 'Search failed.');
            results.append(message);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
    }

    /**
     * HR: Povezuje imenik picker s odgođenom serverskom pretragom.
     * EN: Connects a directory picker to debounced server-side search.
     *
     * @param {HTMLElement} picker
     * @returns {void}
     */
    function initializeSubjectPicker(picker) {
        if (picker.dataset.workspaceSubjectPickerReady === '1') {
            return;
        }

        const input = picker.querySelector('[data-workspace-subject-search]');
        const results = picker.querySelector('[data-workspace-subject-results]');
        if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement)) {
            return;
        }
        picker.dataset.workspaceSubjectPickerReady = '1';

        const state = {controller: null};
        let timer = 0;
        const schedule = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                void searchSubjects(picker, input, results, state);
            }, 180);
        };

        input.addEventListener('focus', schedule);
        input.addEventListener('input', () => {
            schedule();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSubjectResults(input, results);
            }
        });

        document.addEventListener('click', (event) => {
            if (event.target instanceof Node && !picker.contains(event.target)) {
                closeSubjectResults(input, results);
            }
        });
    }

    /**
     * HR: Povezuje uklanjanje ACL redaka i sve asinkrone pickere na stranici.
     * EN: Connects ACL-row removal and every asynchronous picker on the page.
     *
     * @param {ParentNode} [root=document]
     * @returns {void}
     */
    function initializeAclControls(root = document) {
        root.querySelectorAll('[data-workspace-subject-picker]').forEach((picker) => {
            if (picker instanceof HTMLElement) {
                initializeSubjectPicker(picker);
            }
        });

        root.querySelectorAll('[data-workspace-acl-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.workspaceAclFormReady === '1') {
                return;
            }
            form.dataset.workspaceAclFormReady = '1';

            form.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                const button = target.closest('[data-workspace-acl-remove]');
                const row = button?.closest('[data-workspace-acl-row]');
                const section = row?.closest('[data-workspace-acl-section]');
                if (!(row instanceof HTMLTableRowElement) || !(section instanceof HTMLElement)) {
                    return;
                }

                row.remove();
                refreshAclEmptyState(section);
            });
        });

        root.querySelectorAll('[data-workspace-direct-permission-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.workspaceDirectPermissionFormReady === '1') {
                return;
            }
            form.dataset.workspaceDirectPermissionFormReady = '1';

            form.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                const button = target.closest('[data-workspace-direct-permission-remove]');
                const row = button?.closest('[data-workspace-direct-permission-row]');
                if (!(row instanceof HTMLTableRowElement)) {
                    return;
                }

                row.remove();
                refreshDirectPermissionEmptyState(form);
            });

            form.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') {
                    return;
                }

                const row = target.closest('[data-workspace-direct-permission-row]');
                if (!(row instanceof HTMLTableRowElement)) {
                    return;
                }

                const view = row.querySelector('input[name$="[can_view]"]');
                const edit = row.querySelector('input[name$="[can_edit]"]');
                const publish = row.querySelector('input[name$="[can_publish]"]');
                if (
                    view instanceof HTMLInputElement
                    && edit instanceof HTMLInputElement
                    && publish instanceof HTMLInputElement
                    && (edit.checked || publish.checked)
                ) {
                    view.checked = true;
                }
            });
        });

        root.querySelectorAll('[data-workspace-creator-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.workspaceCreatorFormReady === '1') {
                return;
            }
            form.dataset.workspaceCreatorFormReady = '1';
            form.addEventListener('click', (event) => {
                const target = event.target;
                const button = target instanceof Element
                    ? target.closest('[data-workspace-creator-remove]')
                    : null;
                const row = button?.closest('[data-workspace-creator-row]');
                const body = row?.closest('[data-workspace-creator-rows]');
                if (!(row instanceof HTMLTableRowElement) || !(body instanceof HTMLTableSectionElement)) {
                    return;
                }

                row.remove();
                const emptyRow = body.querySelector('[data-workspace-creator-empty]');
                if (emptyRow instanceof HTMLTableRowElement) {
                    emptyRow.hidden = body.querySelector('[data-workspace-creator-row]') !== null;
                }
            });
        });

        root.querySelectorAll('[data-workspace-restriction-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.workspaceRestrictionFormReady === '1') {
                return;
            }
            form.dataset.workspaceRestrictionFormReady = '1';

            form.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                const button = target.closest('[data-workspace-restriction-remove]');
                const row = button?.closest('[data-workspace-restriction-row]');
                if (!(row instanceof HTMLTableRowElement)) {
                    return;
                }

                row.remove();
                refreshRestrictionEmptyState(form);
            });

            form.addEventListener('change', (event) => {
                const target = event.target;
                const row = target instanceof Element
                    ? target.closest('[data-workspace-restriction-row]')
                    : null;
                if (
                    !(target instanceof HTMLInputElement)
                    || !(row instanceof HTMLTableRowElement)
                    || !target.matches('[data-workspace-restriction-permission]')
                ) {
                    return;
                }

                normalizeRestrictionRow(row, target);
            });
        });
    }

    /**
     * HR: Prikazuje postavke stabla i opcija samo kada je cilj naslovnice Shorts.
     * EN: Shows tree and display settings only when the homepage target is Shorts.
     *
     * @returns {void}
     */
    function initializeHomepageTargets() {
        document.querySelectorAll('[data-workspace-homepage-target]').forEach((control) => {
            if (!(control instanceof HTMLSelectElement)) {
                return;
            }

            const key = String(control.dataset.workspaceHomepageTarget || '');
            const options = document.querySelector(
                `[data-workspace-homepage-view-options="${CSS.escape(key)}"]`,
            );
            if (!(options instanceof HTMLElement)) {
                return;
            }

            const synchronize = () => {
                const visible = control.value.startsWith('shorts:');
                options.hidden = !visible;
                options.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.disabled = !visible;
                });
            };

            control.addEventListener('change', synchronize);
            synchronize();
        });
    }

    /**
     * HR: Pretvara stablo stranica u pristupačan lijevi panel na mobilnom
     * prikazu, dok postojeći sklopivi desktop stupac ostaje nepromijenjen.
     *
     * EN: Turns the page tree into an accessible left drawer on mobile while
     * leaving the existing collapsible desktop column unchanged.
     *
     * @returns {void}
     */
    function initializeMobilePanels() {
        const panel = document.querySelector('[data-workspace-mobile-panel="tree"]');
        const backdrop = document.querySelector('[data-workspace-mobile-panel-backdrop]');
        if (!(panel instanceof HTMLElement) || !(backdrop instanceof HTMLElement)) {
            return;
        }

        const mobileQuery = window.matchMedia('(max-width: 991.98px)');
        const openButtons = document.querySelectorAll('[data-workspace-mobile-panel-open="tree"]');
        const closeButtons = panel.querySelectorAll('[data-workspace-mobile-panel-close="tree"]');
        const shell = panel.parentElement;
        const originalNextSibling = panel.nextSibling;
        let returnFocus = null;

        /**
         * HR: Premješta mobilni panel pod body kako transformirana tema ne bi
         *     promijenila koordinatni sustav fiksnog elementa.
         * EN: Portals the mobile panel under body so a transformed theme cannot
         *     change the fixed element's coordinate system.
         *
         * @returns {void}
         */
        const synchronizePortal = () => {
            if (!(shell instanceof HTMLElement)) {
                return;
            }

            if (mobileQuery.matches) {
                openButtons.forEach((button) => document.body.appendChild(button));
                document.body.appendChild(backdrop);
                document.body.appendChild(panel);
                return;
            }

            if (originalNextSibling && originalNextSibling.parentNode === shell) {
                shell.insertBefore(panel, originalNextSibling);
            } else {
                shell.prepend(panel);
            }
            openButtons.forEach((button) => shell.appendChild(button));
            shell.appendChild(backdrop);
        };

        /**
         * HR: Sinkronizira fokus, inert stanje, pozadinu i opis svih gumba.
         * EN: Synchronizes focus, inert state, backdrop, and every button label.
         *
         * @param {boolean} open
         * @returns {void}
         */
        const setOpen = (open) => {
            synchronizePortal();
            const mobileOpen = mobileQuery.matches && open;
            panel.classList.toggle('workspace-mobile-panel-open', mobileOpen);
            panel.inert = mobileQuery.matches && !mobileOpen;
            panel.toggleAttribute('aria-hidden', mobileQuery.matches && !mobileOpen);
            backdrop.hidden = !mobileOpen;
            document.body.classList.toggle('workspace-mobile-panel-active', mobileOpen);
            openButtons.forEach((button) => {
                button.setAttribute('aria-expanded', mobileOpen ? 'true' : 'false');
            });

            if (mobileOpen) {
                panel.scrollTop = 0;
                const closeButton = panel.querySelector('[data-workspace-mobile-panel-close="tree"]');
                if (closeButton instanceof HTMLElement) {
                    closeButton.focus({ preventScroll: true });
                }
            }
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                returnFocus = button instanceof HTMLElement ? button : null;
                setOpen(true);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setOpen(false);
                if (returnFocus instanceof HTMLElement) {
                    returnFocus.focus({ preventScroll: true });
                }
            });
        });

        backdrop.addEventListener('click', () => {
            setOpen(false);
        });

        panel.querySelectorAll('a[href]').forEach((link) => {
            link.addEventListener('click', () => {
                if (mobileQuery.matches) {
                    setOpen(false);
                }
            });
        });

        /*
         * HR: Presreće postojeće Bootstrap collapse gumbe samo na mobitelu.
         * EN: Intercepts existing Bootstrap collapse buttons on mobile only.
         */
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('[data-bs-target="#workspace-page-tree"]')
                : null;
            if (!(target instanceof HTMLElement) || !mobileQuery.matches) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            returnFocus = target;
            setOpen(!panel.classList.contains('workspace-mobile-panel-open'));
        }, true);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && panel.classList.contains('workspace-mobile-panel-open')) {
                setOpen(false);
            }
        });

        mobileQuery.addEventListener('change', () => {
            setOpen(false);
            synchronizePortal();
        });
        synchronizePortal();
        setOpen(false);
    }

    /**
     * HR: Drži backlinkove u istom Bootstrap stupcu kao glavni Editorov sadržaj.
     *     Kada se sadržaj stranice sakrije, oba se bloka zajedno šire na punu
     *     raspoloživu širinu, uključujući rasporede sa stablom ili posebnim menijem.
     *
     * EN: Keeps backlinks in the same Bootstrap column as the main Editor content.
     *     When the table of contents is hidden, both blocks expand together to the
     *     full available width, including layouts with a tree or a custom menu.
     *
     * @returns {void}
     */
    function initializeBacklinkLayout() {
        const backlinkColumn = document.querySelector('[data-workspace-backlinks-column]');
        if (!(backlinkColumn instanceof HTMLElement)) {
            return;
        }

        const tocColumn = document.querySelector('[data-editor-html-toc-column]');
        const mobileQuery = window.matchMedia('(max-width: 991.98px)');

        /**
         * HR: Vraća stvarno desktop stanje stupca sadržaja.
         * EN: Returns the actual desktop state of the table-of-contents column.
         *
         * @returns {boolean}
         */
        const tableOfContentsIsVisible = () => tocColumn instanceof HTMLElement
            && tocColumn.classList.contains('show')
            && !tocColumn.hidden;

        /**
         * HR: Primjenjuje identičnu širinu koju Editor koristi za dokument.
         * EN: Applies the same width used by the Editor document column.
         *
         * @param {boolean} visible Je li desktop sadržaj prikazan. / Whether the desktop outline is visible.
         * @returns {void}
         */
        const synchronizeWidth = (visible) => {
            const useNarrowColumn = !mobileQuery.matches && visible;
            backlinkColumn.classList.toggle('col-lg-9', useNarrowColumn);
            backlinkColumn.classList.toggle('col-12', !useNarrowColumn);
        };

        synchronizeWidth(tableOfContentsIsVisible());

        if (tocColumn instanceof HTMLElement) {
            tocColumn.addEventListener('show.bs.collapse', () => {
                synchronizeWidth(true);
            });
            tocColumn.addEventListener('hidden.bs.collapse', () => {
                synchronizeWidth(false);
            });
        }

        mobileQuery.addEventListener('change', () => {
            synchronizeWidth(tableOfContentsIsVisible());
        });
    }

    /**
     * HR: Dinamički prikazuje ACL-filtrirane prijedloge ugrađene pretrage
     * područja bez napuštanja trenutačne stranice.
     * EN: Dynamically shows ACL-filtered suggestions for embedded Workspace
     * search without leaving the current page.
     *
     * @returns {void}
     */
    function initializeEmbeddedWorkspaceSearch() {
        document.querySelectorAll('[data-workspace-embedded-search="1"]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.workspaceSearchReady === '1') {
                return;
            }
            const input = form.querySelector('[data-workspace-embedded-search-input="1"]');
            const results = form.querySelector('[data-workspace-embedded-search-results="1"]');
            const suggestUrl = String(form.dataset.suggestUrl || '').trim();
            if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement) || suggestUrl === '') {
                return;
            }

            form.dataset.workspaceSearchReady = '1';
            let timer = 0;
            let request = null;

            const clear = () => {
                results.replaceChildren();
                results.hidden = true;
            };

            const render = (items) => {
                clear();
                if (!Array.isArray(items) || items.length === 0) {
                    return;
                }
                items.forEach((item) => {
                    const url = typeof item.url === 'string' ? item.url : '';
                    const title = typeof item.title === 'string' ? item.title : '';
                    if (url === '' || title === '') {
                        return;
                    }
                    const link = document.createElement('a');
                    link.className = 'list-group-item list-group-item-action';
                    link.href = url;
                    link.setAttribute('role', 'option');
                    const heading = document.createElement('span');
                    heading.className = 'd-block fw-semibold';
                    heading.textContent = title;
                    link.appendChild(heading);
                    if (typeof item.workspace === 'string' && item.workspace !== '') {
                        const workspace = document.createElement('small');
                        workspace.className = 'text-body-secondary';
                        workspace.textContent = item.workspace;
                        link.appendChild(workspace);
                    }
                    results.appendChild(link);
                });
                results.hidden = results.childElementCount === 0;
            };

            input.addEventListener('input', () => {
                window.clearTimeout(timer);
                if (request instanceof AbortController) {
                    request.abort();
                }
                const query = input.value.trim();
                if (query.length < 2) {
                    clear();
                    return;
                }
                timer = window.setTimeout(async () => {
                    request = new AbortController();
                    const url = new URL(suggestUrl, window.location.href);
                    url.searchParams.set('q', query);
                    url.searchParams.set('workspace', String(form.dataset.workspaceSlug || ''));
                    url.searchParams.set('lang', String(form.dataset.searchLanguage || ''));
                    try {
                        const response = await window.fetch(url.toString(), {
                            headers: { Accept: 'application/json' },
                            signal: request.signal,
                        });
                        if (!response.ok) {
                            clear();
                            return;
                        }
                        const payload = await response.json();
                        render(payload && Array.isArray(payload.data) ? payload.data : []);
                    } catch (error) {
                        if (!(error instanceof DOMException) || error.name !== 'AbortError') {
                            clear();
                        }
                    }
                }, 180);
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    clear();
                }
            });
        });
    }

    /**
     * HR: Inicijalizira sve Workspace kontrole nakon što je DOM spreman.
     * EN: Initializes every Workspace control after the DOM is ready.
     *
     * @returns {void}
     */
    function initializeWorkspaceControls() {
        initializeModalPortals();
        initializeNodeForms();
        initializeTreeOrganizers();
        initializeReadableTrees();
        initializeAdaptiveTreeWidths();
        initializeTreeEditModes();
        initializeNodeDialog();
        initializeAclControls();
        initializeHomepageTargets();
        initializeMobilePanels();
        initializeBacklinkLayout();
        initializeEmbeddedWorkspaceSearch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeWorkspaceControls, { once: true });
    } else {
        initializeWorkspaceControls();
    }
}());
