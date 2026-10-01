/* MUDRAVA admin controller. Vanilla JS, no build step, no external deps.
 * Drives: preflight render, export tick loop, chunked upload, restore,
 * live progress rail (phase / rate / safe-to-close), restore-point gate,
 * archive cards. All requests carry the WP REST nonce; passwords are sent
 * per-request and never stored in this page's state beyond the tick call. */
(function () {
    'use strict';

    var cfg = window.mudravaAdmin || {};
    // Restore-scoped token: keeps ticks authenticated after the restore
    // replaces wp_usermeta (and thus the operator's session cookie). Null
    // outside a restore, so normal requests never send the header.
    var restoreToken = null;
    var restoreTokenJob = '';
    function saveRestoreToken(token, archiveId) {
        restoreToken = token || null;
        restoreTokenJob = archiveId || '';
        try {
            if (restoreToken) {
                window.sessionStorage.setItem('mudrava-restore-token', JSON.stringify({
                    token: restoreToken, archive_id: restoreTokenJob
                }));
            } else {
                window.sessionStorage.removeItem('mudrava-restore-token');
            }
        } catch (e) { /* Storage may be disabled. */ }
    }
    var api = function (path, opts) {
        opts = opts || {};
        opts.headers = opts.headers || {};
        opts.headers['X-WP-Nonce'] = cfg.nonce;
        if (restoreToken && path === '/job/tick' && opts.method === 'POST') {
            opts.headers['X-Mudrava-Restore'] = restoreToken;
        }
        opts.credentials = 'same-origin';
        return fetch(cfg.root + path, opts).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok) {
                    var err = new Error(body && body.code ? body.code : 'HTTP ' + res.status);
                    err.body = body;
                    err.status = res.status;
                    throw err;
                }
                return body;
            });
        });
    };

    var $ = function (id) { return document.getElementById(id); };
    var statusEl = $('mudrava-status');
    var barFill = $('mudrava-bar-fill');
    var progress = $('mudrava-progress');
    var phaseEl = $('mudrava-phase');
    var rateEl = $('mudrava-rate');
    var closeNote = $('mudrava-close-note');
    // Every user-visible string comes from the localized cfg.i18n map
    // (wp_localize_script). Fallbacks exist only so a mis-registered
    // script degrades to English instead of "undefined".
    var T = function (key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) || fallback;
    };

    function setStatus(text) { if (statusEl) { statusEl.textContent = text; } }

    // The progress dialog is a blocking modal: while a job runs it sits
    // above everything (the page behind cannot be scrolled or clicked),
    // tab switching is frozen, and beforeunload guards accidental reloads.
    // "Close" only appears on terminal states; exports also get Cancel.
    var modalCancelBtn = $('mudrava-cancel-job');
    var modalCloseBtn = $('mudrava-close-modal');
    var resumeControls = $('mudrava-resume-controls');
    var resumePassword = $('mudrava-resume-password');
    var resumeButton = $('mudrava-resume-job');
    var jobRunning = false;
    var jobKind = '';
    var pendingFailureKey = null;
    function modalOpen() { return !!progress && !progress.hidden; }
    // While the dialog is visible the page behind is inert: tabs are
    // disabled and activateTab refuses to switch. The lock is lifted only
    // when the dialog closes (Close appears on terminal states).
    function syncModalLock() {
        var open = modalOpen();
        Array.prototype.forEach.call(tabs, function (t) {
            t.disabled = open;
            t.classList.toggle('is-locked', open);
            if (open) { t.setAttribute('aria-disabled', 'true'); }
            else { t.removeAttribute('aria-disabled'); }
        });
        document.body.classList.toggle('mudrava-modal-open', open);
        // Move focus into the dialog as it opens; the card itself is
        // tabbable so the trap always has a landing spot.
        if (open && progress && !progress.contains(document.activeElement)) {
            var card = progress.querySelector('.mudrava-modal-card');
            if (card) { card.focus(); }
        }
    }
    function setJobRunning(running, kind) {
        jobRunning = running;
        jobKind = kind || '';
        if (modalCloseBtn) { modalCloseBtn.hidden = running; }
        // Cancel exists only for exports: an import half-applied must not
        // be abandoned with a button that implies a clean rollback.
        if (modalCancelBtn) { modalCancelBtn.hidden = !(running && jobKind === 'export'); }
        if (running) {
            window.addEventListener('beforeunload', guardUnload);
        } else {
            window.removeEventListener('beforeunload', guardUnload);
        }
    }
    function guardUnload(e) {
        e.preventDefault();
        // Chrome requires returnValue to be set for the native prompt.
        e.returnValue = '';
    }
    function closeModal() {
        if (resumeControls) { resumeControls.hidden = true; }
        if (resumePassword) { resumePassword.value = ''; }
        if (pendingFailureKey) {
            try { window.sessionStorage.setItem(pendingFailureKey, 'dismissed'); } catch (e) { /* storage may be disabled */ }
            pendingFailureKey = null;
        }
        if (progress) { progress.hidden = true; }
        setJobRunning(false, '');
        syncModalLock();
        if (startExport) { startExport.disabled = false; }
        armRestore();
    }
    if (modalCloseBtn) { modalCloseBtn.addEventListener('click', closeModal); }
    if (modalCancelBtn) {
        modalCancelBtn.addEventListener('click', function () {
            if (cancelRequested) { return; }
            cancelRequested = true;
            if (exportTimer) { clearTimeout(exportTimer); exportTimer = null; }
            modalCancelBtn.disabled = true;
            setStatus(T('cancelling', 'Cancelling…'));
            function attemptCancel(tries) {
                api('/job', { method: 'DELETE' }).then(function () {
                    cancelRequested = false;
                    modalCancelBtn.disabled = false;
                    setStatus(T('cancelled', 'Cancelled.'));
                    closeModal();
                }).catch(function (e) {
                    // A tick owns the job lock for up to a few seconds. Stop
                    // scheduling ticks and retry when that worker releases it.
                    if (e.message === 'MUDRAVA_JOB_RUNNING' && tries < 60) {
                        window.setTimeout(function () { attemptCancel(tries + 1); }, 500);
                        return;
                    }
                    cancelRequested = false;
                    modalCancelBtn.disabled = false;
                    setStatus(T('failed', 'Failed') + ': ' + e.message);
                });
            }
            attemptCancel(0);
        });
    }
    // While the modal is up, keep focus inside it (simple tab trap) - the
    // backdrop already blocks pointer access to the page behind, and the
    // keyboard must not leak past it either.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !modalOpen()) { return; }
        var focusables = progress.querySelectorAll(
            'button:not([hidden]):not([disabled]), a[href], input:not([type="hidden"])'
        );
        if (!focusables.length) {
            // Nothing interactive yet (running import): keep focus parked
            // on the dialog itself instead of leaking to the page behind.
            e.preventDefault();
            var card = progress.querySelector('.mudrava-modal-card');
            if (card) { card.focus(); }
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        } else if (!progress.contains(document.activeElement)) {
            e.preventDefault(); first.focus();
        }
    });

    function setPercent(p) {
        if (progress && progress.hidden) { progress.hidden = false; syncModalLock(); }
        var bar = barFill ? barFill.parentNode : null;
        if (p === null) {
            if (bar) {
                bar.classList.add('is-indeterminate');
                bar.removeAttribute('aria-valuenow');
            }
            return;
        }
        var clamped = Math.max(0, Math.min(100, p));
        if (bar) { bar.classList.remove('is-indeterminate'); }
        if (barFill) { barFill.style.width = clamped + '%'; }
        // Keep the accessible value in sync with the visual bar.
        if (bar && bar.getAttribute('role') === 'progressbar') {
            bar.setAttribute('aria-valuenow', String(Math.round(clamped)));
        }
    }
    function setJobPercent(job) {
        // The exporter does not know the total size of the file inventory.
        // Its 60% phase marker is not a measured fraction of the work.
        setPercent(job.kind === 'export' && job.state === 'running' && job.phase === 'files'
            ? null : (job.percent || 0));
    }
    // Human size ladder: every value climbs to the largest unit it fits
    // (1536 B -> 1.5 KB, 54563 KB -> 53.3 MB), so GB really shows as GB.
    function humanBytes(n) {
        if (!n || n < 0) { return '0 B'; }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? String(Math.round(n)) : n.toFixed(1)) + ' ' + units[i];
    }

    // Live progress rail: phase label, processed bytes, honest rate
    // measured from logical_bytes deltas between ticks.
    var lastBytes = null;
    var lastAt = null;
    function renderProgress(job) {
        var phase = job.phase || job.kind || '';
        if (job.state === 'done') { phase = 'done'; }
        if (job.state === 'rolling_back' && phase !== 'rollback_verifying' && phase !== 'rollback_cleaning') {
            phase = 'rollback_restoring';
        }
        if (phaseEl) {
            phaseEl.textContent = cfg.i18n && cfg.i18n['phase_' + phase]
                ? cfg.i18n['phase_' + phase] : phase;
        }
        if (typeof job.logical_bytes === 'number' && job.logical_bytes > 0) {
            var now = Date.now();
            if (lastBytes !== null && now > lastAt) {
                var rate = (job.logical_bytes - lastBytes) / ((now - lastAt) / 1000);
                if (rate > 0 && rateEl) {
                    rateEl.textContent = humanBytes(job.logical_bytes) + ', ' +
                        humanBytes(Math.round(rate)) + '/s';
                }
            }
            lastBytes = job.logical_bytes;
            lastAt = now;
        }
    }
    function resetProgress() {
        if (phaseEl) { phaseEl.textContent = T('working', 'Working'); }
        if (resumeControls) { resumeControls.hidden = true; }
        if (resumePassword) { resumePassword.value = ''; }
        lastBytes = null;
        lastAt = null;
        if (rateEl) { rateEl.textContent = ''; }
        if (closeNote) { closeNote.hidden = true; }
        // The rewrite line belongs to a restore only (source -> this site).
        // A previous import could otherwise leave it rendered with stale or
        // empty endpoints under a later export.
        if (rewriteLine) { rewriteLine.hidden = true; }
        removeModalDownload();
    }
    function removeModalDownload() {
        var dl = $('mudrava-modal-download');
        if (dl) { dl.remove(); }
    }
    // A finished export turns the dialog into the handoff it should be:
    // the fresh archive is downloadable right here - one click for a
    // single file, one click for every part of a split set. The part
    // count is read back from /archives (the export that just ran is the
    // first row), so the button can never promise a wrong count.
    function offerExportDownload(id) {
        var foot = progress ? progress.querySelector('.mudrava-modal-foot') : null;
        if (!foot) { return; }
        function build(parts) {
            removeModalDownload();
            var btn;
            if (parts > 1) {
                btn = el('button', 'mudrava-btn mudrava-btn-primary');
                btn.type = 'button';
                btn.appendChild(icon(ICON_DOWNLOAD));
                btn.appendChild(document.createTextNode(
                    T('downloadAll', 'Download all') + ' (' + parts + ')'));
                btn.addEventListener('click', function () { downloadAllParts(btn, id, parts); });
            } else {
                btn = el('a', 'mudrava-btn mudrava-btn-primary');
                btn.href = cfg.root + '/archives/' + id + '/download?part=1&_wpnonce=' + cfg.nonce;
                btn.appendChild(icon(ICON_DOWNLOAD));
                btn.appendChild(document.createTextNode(T('download', 'Download')));
            }
            btn.id = 'mudrava-modal-download';
            foot.insertBefore(btn, foot.firstChild);
        }
        api('/archives').then(function (data) {
            var list = data.archives || [];
            for (var i = 0; i < list.length; i++) {
                if (list[i].archive_id === id) { build(list[i].parts); return; }
            }
            build(1);
        }).catch(function () { build(1); });
    }

    // Tabs: click + roving-tabindex keyboard navigation (Left/Right/Home/End),
    // per the WAI-ARIA tabs pattern.
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.mudrava-tab'));
    // Any [data-goto-tab] control (e.g. the failed-checks notice) jumps to
    // the matching tab panel.
    Array.prototype.forEach.call(document.querySelectorAll('[data-goto-tab]'), function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector('.mudrava-tab[data-tab="' + btn.getAttribute('data-goto-tab') + '"]');
            if (target) { activateTab(target); }
        });
    });
    function activateTab(tab) {
        // While the progress modal is up, navigation is frozen - the job
        // owns the page until it finishes or the operator closes it.
        if (modalOpen()) { return; }
        tabs.forEach(function (t) {
            var on = t === tab;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        document.querySelectorAll('.mudrava-panel').forEach(function (p) { p.hidden = true; });
        var panel = $('mudrava-panel-' + tab.getAttribute('data-tab'));
        if (panel) { panel.hidden = false; }
        // Keep the active tab in the URL (?tab=…) so a reload or a shared
        // link lands on the same tab. replaceState: switching tabs should
        // not flood the back button.
        if (window.history && window.history.replaceState) {
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', tab.getAttribute('data-tab'));
                window.history.replaceState(null, '', url.toString());
            } catch (e) { /* old browsers: tab state stays visual only */ }
        }
    }
    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { activateTab(tab); });
        tab.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight') { next = tabs[(i + 1) % tabs.length]; }
            if (e.key === 'ArrowLeft') { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
            if (e.key === 'Home') { next = tabs[0]; }
            if (e.key === 'End') { next = tabs[tabs.length - 1]; }
            if (next) {
                e.preventDefault();
                activateTab(next);
                next.focus();
            }
        });
    });

    function csvList(id) {
        var el = $(id);
        if (!el) { return []; }
        return (el.value || '').split(',')
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s !== ''; });
    }

    // Archives list: one card per logical archive (a split set is ONE
    // backup with N parts, and the card says so). Row one is identity on
    // the left and the single action on the right, so "download" sits
    // next to the name instead of floating under the metadata. A split
    // set puts its numbered part links into a collapsed panel below
    // (native <details>), so a 23-part set stays a tidy two-line card
    // instead of a wall of buttons.
    // Paging is client-side over the single AJAX payload: the endpoint
    // already returns every archive (mtime desc), so the pager just
    // re-renders the current page without another request.
    var ARCHIVE_PAGE = 5;
    var archiveData = [];
    var archivePage = 0;
    var pagerEl = $('mudrava-archives-pager');
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    // Inline SVG icons (same language as the eye toggles in the markup):
    // font glyphs like "⇩" or "▾" shift between fonts and look wrong at
    // button size, so every icon is a real vector, aria-hidden.
    var ICON_DOWNLOAD = [
        'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4',
        'M7 10l5 5 5-5',
        'M12 15V3'
    ];
    var ICON_CHEVRON = ['M6 9l6 6 6-6'];
    // "Download all" for a split set: same-origin part links are clicked
    // one after another (the browser asks once to allow multiple files,
    // then saves them all). The button goes inert for the duration so a
    // second click cannot start a second race of requests.
    function downloadAllParts(btn, id, parts) {
        if (btn.disabled) { return; }
        btn.disabled = true;
        var p = 1;
        function step() {
            if (p > parts) { btn.disabled = false; return; }
            var a = document.createElement('a');
            a.href = cfg.root + '/archives/' + id +
                '/download?part=' + p + '&_wpnonce=' + cfg.nonce;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            a.remove();
            p++;
            setTimeout(step, 600);
        }
        step();
    }
    function icon(paths, cls) {
        var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        s.setAttribute('viewBox', '0 0 24 24');
        s.setAttribute('width', '14');
        s.setAttribute('height', '14');
        s.setAttribute('fill', 'none');
        s.setAttribute('stroke', 'currentColor');
        s.setAttribute('stroke-width', '2');
        s.setAttribute('stroke-linecap', 'round');
        s.setAttribute('stroke-linejoin', 'round');
        s.setAttribute('aria-hidden', 'true');
        s.setAttribute('focusable', 'false');
        if (cls) { s.setAttribute('class', cls); }
        for (var i = 0; i < paths.length; i++) {
            var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            p.setAttribute('d', paths[i]);
            s.appendChild(p);
        }
        return s;
    }
    function renderArchiveCard(a, list) {
        var card = el('div', 'mudrava-arc');
        var top = el('div', 'mudrava-arc-top');
        top.appendChild(el('span', 'mudrava-arc-icon', '.MUD'));

        var main = el('div', 'mudrava-arc-main');
        main.appendChild(el('div', 'mudrava-arc-name', a.archive_id));

        var meta = el('div', 'mudrava-arc-meta');
        if (a.parts > 1) {
            meta.appendChild(el('span', 'mudrava-tag mudrava-tag-parts',
                a.parts + ' ' + T('parts', 'parts')));
        }
        if (a.encrypted) {
            meta.appendChild(el('span', 'mudrava-tag mudrava-tag-lock',
                T('encrypted', 'encrypted')));
        }
        if (a.source_url) {
            meta.appendChild(el('span', 'mudrava-tag mudrava-tag-src', a.source_url));
        }
        if (a.missing && a.missing.length) {
            meta.appendChild(el('span', 'mudrava-tag mudrava-tag-warn',
                T('missingParts', 'missing parts') + ': ' + a.missing.join(', ')));
        }
        meta.appendChild(el('span', 'mudrava-arc-info', humanBytes(a.bytes)));
        if (a.mtime) {
            meta.appendChild(el('span', 'mudrava-arc-dot', '\u00b7'));
            meta.appendChild(el('span', 'mudrava-arc-info',
                new Date(a.mtime * 1000).toLocaleString()));
        }
        main.appendChild(meta);
        if (a.hint) {
            main.appendChild(el('p', 'mudrava-arc-hint', T('hint', 'hint') + ': ' + a.hint));
        }
        top.appendChild(main);

        // A complete archive always has its primary action in the same
        // spot on the right: "Download" for one file, "Download all" for
        // a split set (the whole set, parts clicked one by one). The
        // per-part panel below is then only the fallback for picking a
        // single part - no action verbs there, just the part count.
        var complete = !(a.missing && a.missing.length);
        if (complete) {
            var actions = el('div', 'mudrava-arc-actions');
            if (a.parts === 1) {
                var one = el('a', 'mudrava-dl mudrava-dl-full');
                one.appendChild(icon(ICON_DOWNLOAD));
                one.appendChild(document.createTextNode(T('download', 'Download')));
                one.href = cfg.root + '/archives/' + a.archive_id +
                    '/download?part=1&_wpnonce=' + cfg.nonce;
                actions.appendChild(one);
            } else {
                var all = el('button', 'mudrava-dl mudrava-dl-full');
                all.type = 'button';
                all.appendChild(icon(ICON_DOWNLOAD));
                all.appendChild(document.createTextNode(
                    T('downloadAll', 'Download all') + ' (' + a.parts + ')'));
                all.addEventListener('click', function () {
                    downloadAllParts(all, a.archive_id, a.parts);
                });
                actions.appendChild(all);
            }
            top.appendChild(actions);
        }
        card.appendChild(top);

        if (complete && a.parts > 1) {
            var det = document.createElement('details');
            det.className = 'mudrava-parts';
            var sum = el('summary');
            sum.appendChild(document.createTextNode(
                T('individualParts', 'Individual parts') + ': 1-' + a.parts));
            sum.appendChild(icon(ICON_CHEVRON, 'mudrava-caret'));
            det.appendChild(sum);
            var grid = el('div', 'mudrava-parts-grid');
            for (var p = 1; p <= a.parts; p++) {
                var link = el('a', 'mudrava-dl', String(p));
                link.href = cfg.root + '/archives/' + a.archive_id +
                    '/download?part=' + p + '&_wpnonce=' + cfg.nonce;
                link.setAttribute('aria-label',
                    T('downloadPart', 'Download part') + ' ' + p);
                grid.appendChild(link);
            }
            det.appendChild(grid);
            card.appendChild(det);
        }
        list.appendChild(card);
    }
    function archivePages() {
        return Math.max(1, Math.ceil(archiveData.length / ARCHIVE_PAGE));
    }
    function renderArchivePage() {
        var list = $('mudrava-archive-list');
        if (!list) { return; }
        var pages = archivePages();
        archivePage = Math.max(0, Math.min(archivePage, pages - 1));
        list.innerHTML = '';
        var from = archivePage * ARCHIVE_PAGE;
        var stop = Math.min(from + ARCHIVE_PAGE, archiveData.length);
        for (var i = from; i < stop; i++) { renderArchiveCard(archiveData[i], list); }
        renderPager(pages);
    }
    // Pager: prev / numbers / next. The current page is marked with
    // aria-current and is not a button you can press again; prev/next
    // disable at the ends instead of vanishing, so the control never
    // jumps around between clicks. With many pages the numbers are
    // windowed (first, last, and the neighbours of the current page).
    // A gap of a single hidden page is rendered as that page - an
    // ellipsis only stands for two or more hidden pages, and a real
    // ellipsis never sits right after "prev" while page 1 is visible.
    function renderPager(pages) {
        if (!pagerEl) { return; }
        pagerEl.innerHTML = '';
        if (pages <= 1) { pagerEl.hidden = true; return; }
        pagerEl.hidden = false;
        var nums = el('div', 'mudrava-pager-nums');
        function pageBtn(label, page, opts) {
            opts = opts || {};
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mudrava-page' + (opts.current ? ' is-current' : '');
            b.textContent = label;
            if (opts.label) { b.setAttribute('aria-label', opts.label); }
            if (opts.current) { b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) {
                b.disabled = true;
            } else {
                b.addEventListener('click', function () {
                    archivePage = page;
                    renderArchivePage();
                });
            }
            nums.appendChild(b);
        }
        function ellipsis() {
            var s = el('span', 'mudrava-page-gap', '\u2026');
            s.setAttribute('aria-hidden', 'true');
            nums.appendChild(s);
        }
        pageBtn('\u2039', archivePage - 1, {
            label: T('prevPage', 'Previous page'),
            disabled: archivePage === 0
        });
        var WINDOW = 2; // neighbours on each side of the current page
        var shown = {};
        var i;
        if (pages <= 1 + WINDOW * 2 + 2) {
            for (i = 0; i < pages; i++) { shown[i] = true; }
        } else {
            shown[0] = true;
            shown[pages - 1] = true;
            for (i = archivePage - WINDOW; i <= archivePage + WINDOW; i++) {
                if (i >= 0 && i < pages) { shown[i] = true; }
            }
        }
        var prev = -1;
        for (i = 0; i < pages; i++) {
            if (!shown[i]) { continue; }
            if (i - prev === 2) {
                pageBtn(String(prev + 2), prev + 1, {
                    label: T('pageWord', 'Page') + ' ' + (prev + 2)
                });
            } else if (i - prev > 2) {
                ellipsis();
            }
            pageBtn(String(i + 1), i, {
                current: i === archivePage,
                label: T('pageWord', 'Page') + ' ' + (i + 1)
            });
            prev = i;
        }
        pageBtn('\u203A', archivePage + 1, {
            label: T('nextPage', 'Next page'),
            disabled: archivePage === pages - 1
        });
        pagerEl.appendChild(nums);
    }
    function loadArchives(reset) {
        if (reset !== false) {
            archivePage = 0;
            var list0 = $('mudrava-archive-list');
            if (list0) { list0.innerHTML = ''; }
            if (pagerEl) { pagerEl.hidden = true; }
        }
        api('/archives').then(function (data) {
            var list = $('mudrava-archive-list');
            if (!list) { return; }
            archiveData = data.archives || [];
            if (!archiveData.length) {
                var empty = document.createElement('p');
                empty.className = 'mudrava-empty';
                empty.textContent = T('noArchives', 'No archives on this site yet.');
                list.appendChild(empty);
                return;
            }
            renderArchivePage();
        }).catch(function () { /* list is best-effort */ });
    }
    loadArchives();

    // Export splitting: Auto / Off / 2 GB / 4 GB / Custom. Auto = 7 GiB,
    // which keeps every part under the common 8 GB per-file host limit.
    // Custom shows a number plus an explicit unit selector (MB default,
    // GB available) so the typed value can never be ambiguous. Splitting
    // exists to survive host file caps and FTP/email transfer limits, not
    // to make "small chunks".
    var AUTO_SPLIT_BYTES = 7516192768; // 7 GiB
    var splitInput = $('mudrava-split-size');
    var splitUnit = $('mudrava-split-unit');
    var splitWrap = $('mudrava-split-custom-wrap');
    var chips = Array.prototype.slice.call(document.querySelectorAll('.mudrava-chip'));
    var splitMode = 'auto';
    function applySplitMode() {
        chips.forEach(function (c) {
            c.classList.toggle('is-active', c.getAttribute('data-split') === splitMode);
        });
        if (splitWrap) { splitWrap.hidden = splitMode !== 'custom'; }
    }
    chips.forEach(function (c) {
        c.addEventListener('click', function () {
            splitMode = c.getAttribute('data-split');
            applySplitMode();
        });
    });
    applySplitMode();
    function currentSplitBytes() {
        if (splitMode === 'auto') { return AUTO_SPLIT_BYTES; }
        if (splitMode === '0') { return 0; }
        if (splitMode === 'custom') {
            var n = parseInt((splitInput || {}).value || '0', 10);
            var unit = parseInt((splitUnit || {}).value || '1048576', 10);
            return n > 0 ? n * unit : 0;
        }
        return parseInt(splitMode, 10) * 1048576; // '2048' / '4096'
    }

    // Content pickers: every real table and every real wp-content folder,
    // measured live from /inventory. Everything is checked by default -
    // the operator unchecks only what they are sure this site does not
    // need. The payload is the exclude side (tables/dirs), which is
    // exactly what the export engine honors; core tables are locked on
    // because a restored site cannot run without them.
    var pickers = {
        db: {
            list: $('mudrava-db-list'), all: $('mudrava-db-all'),
            filter: $('mudrava-db-filter'), count: $('mudrava-db-count'),
            items: [], off: {}
        },
        files: {
            list: $('mudrava-files-list'), all: $('mudrava-files-all'),
            filter: $('mudrava-files-filter'), count: $('mudrava-files-count'),
            items: [], off: {}
        }
    };
    function pickerKey(kind, item) { return kind === 'db' ? item.name : item.path; }

    function renderPicker(kind) {
        var p = pickers[kind];
        if (!p.list) { return; }
        p.list.innerHTML = '';
        p.items.forEach(function (item) {
            var key = pickerKey(kind, item);
            var row = document.createElement('label');
            row.className = 'mudrava-prow' + (item.locked ? ' is-locked' : '');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !p.off[key];
            cb.disabled = !!item.locked;
            cb.setAttribute('data-key', key);
            var name = document.createElement('span');
            name.className = 'mudrava-prow-name';
            name.textContent = kind === 'db' ? item.name : (item.label || item.path);
            if (item.locked) {
                var badge = document.createElement('span');
                badge.className = 'mudrava-tag-core';
                badge.title = T('pickerCore', 'WordPress core table. Always included');
                badge.textContent = 'core';
                name.appendChild(badge);
            }
            var meta = document.createElement('span');
            meta.className = 'mudrava-prow-meta';
            // TABLE_ROWS is an InnoDB estimate, so row counts always carry
            // the "~" - the UI never promises an exact number it lacks.
            meta.textContent = kind === 'db'
                ? '~' + item.rows + ' ' + T('rowsWord', 'rows') + ', ' + humanBytes(item.bytes)
                : humanBytes(item.bytes) + ', ' + item.files + ' ' + T('filesWord', 'files');
            row.appendChild(cb);
            row.appendChild(name);
            row.appendChild(meta);
            row.setAttribute('data-search', (key + ' ' + name.textContent).toLowerCase());
            cb.addEventListener('change', function () {
                if (cb.checked) { delete p.off[key]; } else { p.off[key] = true; }
                syncPicker(kind);
            });
            p.list.appendChild(row);
        });
        applyFilter(kind);
        syncPicker(kind);
    }

    function applyFilter(kind) {
        var p = pickers[kind];
        var q = ((p.filter || {}).value || '').trim().toLowerCase();
        Array.prototype.forEach.call(p.list.children, function (row) {
            row.hidden = q !== '' && (row.getAttribute('data-search') || '').indexOf(q) === -1;
        });
    }

    function syncPicker(kind) {
        var p = pickers[kind];
        var boxes = Array.prototype.filter.call(
            p.list.querySelectorAll('input[type="checkbox"]'),
            function (cb) { return !cb.disabled; }
        );
        var on = boxes.filter(function (cb) { return cb.checked; }).length;
        if (p.all) {
            p.all.checked = on === boxes.length;
            p.all.indeterminate = on > 0 && on < boxes.length;
        }
        if (p.count) {
            p.count.textContent = boxes.length === 0 ? ''
                : (on === boxes.length ? T('pickerAll', 'all included') : on + ' / ' + boxes.length);
        }
        updateContentSummary();
    }

    function pickerPlaceholder(kind, text) {
        var p = pickers[kind];
        if (!p.list) { return; }
        p.list.innerHTML = '';
        var el = document.createElement('p');
        el.className = 'mudrava-picker-loading';
        el.textContent = text;
        p.list.appendChild(el);
    }

    function loadInventory() {
        if (!pickers.db.list && !pickers.files.list) { return; }
        if (!pickers.db.items.length) {
            pickerPlaceholder('db', T('pickerLoading', 'Measuring this site…'));
            pickerPlaceholder('files', T('pickerLoading', 'Measuring this site…'));
        }
        api('/inventory').then(function (data) {
            pickers.db.items = data.tables || [];
            pickers.files.items = data.files || [];
            renderPicker('db');
            renderPicker('files');
            var foot = document.querySelector('.mudrava-picker-foot');
            if (foot && data.approx && !foot.getAttribute('data-approx')) {
                foot.setAttribute('data-approx', '1');
                foot.textContent = foot.textContent + ' ' +
                    T('pickerApprox', 'Sizes are approximate on very large sites.');
            }
        }).catch(function () {
            var msg = T('pickerFailed', 'Could not load the live inventory. Reload the page to try again.');
            pickerPlaceholder('db', msg);
            pickerPlaceholder('files', msg);
        });
    }
    loadInventory();
    Array.prototype.forEach.call(document.querySelectorAll('.mudrava-picker-refresh'), function (btn) {
        btn.addEventListener('click', loadInventory);
    });
    ['db', 'files'].forEach(function (kind) {
        var p = pickers[kind];
        if (p.all) {
            p.all.addEventListener('change', function () {
                Array.prototype.forEach.call(p.list.querySelectorAll('input[type="checkbox"]'), function (cb) {
                    if (cb.disabled) { return; }
                    cb.checked = p.all.checked;
                    var key = cb.getAttribute('data-key');
                    if (cb.checked) { delete p.off[key]; } else { p.off[key] = true; }
                });
                syncPicker(kind);
            });
        }
        if (p.filter) { p.filter.addEventListener('input', function () { applyFilter(kind); }); }
    });

    // The pickers stay collapsed until asked for: 90% of operators back
    // the whole site up and never touch this. The header line carries a
    // live summary so the closed state still tells the truth about what
    // the archive will contain.
    var contentToggle = $('mudrava-content-toggle');
    var contentBody = $('mudrava-content-body');
    var contentSummary = $('mudrava-content-summary');
    if (contentToggle && contentBody) {
        contentToggle.addEventListener('click', function () {
            var open = contentBody.hidden;
            contentBody.hidden = !open;
            contentToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            contentToggle.textContent = open
                ? T('contentEditClose', 'Done editing')
                : T('contentEdit', 'Change what is included');
        });
    }
    function updateContentSummary() {
        if (!contentSummary) { return; }
        var off = Object.keys(pickers.db.off).length + Object.keys(pickers.files.off).length;
        var extra = csvList('mudrava-exclude-dirs').length;
        if (off === 0 && extra === 0) {
            contentSummary.textContent = T('contentAll', 'Everything included');
        } else {
            contentSummary.textContent = T('contentExcluded', 'Excluded') + ': ' + (off + extra);
        }
    }
    var extraDirsInput = $('mudrava-exclude-dirs');
    if (extraDirsInput) { extraDirsInput.addEventListener('input', updateContentSummary); }

    // Export loop.
    var exportTimer = null;
    var cancelRequested = false;
    function showFailedJob(job) {
        var recoveryNote = '';
        if (job.rollback_state === 'done') {
            recoveryNote = '. ' + T('rollbackDone', 'Restore point reapplied; verify the site');
        } else if (job.rollback_state === 'failed') {
            recoveryNote = '. ' + T('rollbackFailed', 'Automatic recovery failed; inspect the restore point')
                + ': ' + (job.rollback_error || T('unknown', 'unknown'))
                + ' (' + job.restore_point_archive_id + ')';
        } else if (job.restore_point_archive_id) {
            recoveryNote = '. ' + T('restorePointSaved', 'Restore point saved')
                + ': ' + job.restore_point_archive_id;
        }
        setStatus(T('failed', 'Failed') + ': ' + (job.error || T('unknown', 'unknown'))
            + recoveryNote);
        if (phaseEl) { phaseEl.textContent = T('failed', 'Failed'); }
        pendingFailureKey = 'mudrava-failure-' + job.archive_id + '-' + job.revision;
        setJobRunning(false, job.kind);
    }
    function exportTick(password) {
        if (cancelRequested) { return; }
        api('/job/tick', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(password ? { password: password } : {})
        }).then(function (job) {
            if (cancelRequested) { return; }
            setJobPercent(job);
            renderProgress(job);
            if (job.state === 'done') {
                saveRestoreToken(null, '');
                resetProgress();
                if (phaseEl) { phaseEl.textContent = T('done', 'Done'); }
                setStatus(T('done', 'Done') + ': ' + job.archive_id
                    + (job.kind === 'import' && job.restore_point_archive_id
                        ? '. ' + T('restorePointSaved', 'Restore point saved') + ': ' + job.restore_point_archive_id
                        : ''));
                setJobRunning(false, job.kind);
                loadArchives();
                if (job.kind === 'export') { offerExportDownload(job.archive_id); }
                return;
            }
            if (job.state === 'failed') {
                saveRestoreToken(null, '');
                resetProgress();
                showFailedJob(job);
                return;
            }
            setJobRunning(true, job.kind);
            if (job.note === 'password_required') {
                requireResumePassword();
                return;
            }
            if (closeNote) {
                // Never promise background progress that this host cannot
                // deliver: imports only advance while this page ticks;
                // exports continue on their own only where cron is alive.
                if (job.kind === 'import') {
                    closeNote.textContent = T('closeNoteImport',
                        'Keep this tab open until the restore finishes. The restore only advances while this page is open.');
                } else if (job.cron_alive) {
                    closeNote.textContent = T('closeNote',
                        'Safe to close this tab. The job continues on the server and resumes when you return.');
                } else {
                    closeNote.textContent = T('closeNoteNoCron',
                        'You can close this tab, but site cron is not waking up here: without visitors the job '
                        + 'may pause until cron returns, then it resumes on its own. Keep this tab open to be sure.');
                }
                closeNote.hidden = false;
            }
            setStatus(job.state === 'rolling_back'
                ? T('rollingBack', 'Restoring the previous site after an import error')
                : (job.kind || T('job', 'job')) + ', ' + (job.state || T('running', 'running')) +
                    (job.current_part > 1 ? ' (' + T('part', 'part') + ' ' + job.current_part + ')' : ''));
            exportTimer = setTimeout(function () { exportTick(password); }, 1500);
        }).catch(function (e) {
            if (cancelRequested) { return; }
            // The tick request failed (network, session). The job itself
            // may still be alive server-side; the dialog stays open with
            // Close so the state stays honest instead of vanishing.
            setStatus(T('failed', 'Failed') + ': ' + e.message);
            setJobRunning(false, jobKind);
        });
    }

    // Encryption is opt-in (founding doc: password + hint are explicit,
    // never silent defaults). The fields exist only while the box is on;
    // while it is off the archive is unencrypted and no password is read.
    var encryptOn = $('mudrava-encrypt-on');
    var encryptFields = $('mudrava-encrypt-fields');
    var pwInput = $('mudrava-export-password');
    var pw2Input = $('mudrava-export-password2');
    var hintInput = $('mudrava-hint');
    var pwError = $('mudrava-pw-error');
    var pw2Error = $('mudrava-pw2-error');
    var hintError = $('mudrava-hint-error');

    // Show/hide password: the standard eye toggle. The button swaps the
    // input type and its own accessible name; the icon swap is CSS on
    // aria-pressed.
    Array.prototype.forEach.call(document.querySelectorAll('.mudrava-eye'), function (btn) {
        btn.addEventListener('click', function () {
            var input = $(btn.getAttribute('data-eye'));
            if (!input) { return; }
            var shown = input.type === 'text';
            input.type = shown ? 'password' : 'text';
            btn.setAttribute('aria-pressed', shown ? 'false' : 'true');
            btn.setAttribute('aria-label', shown
                ? T('showPw', 'Show password')
                : T('hidePw', 'Hide password'));
            input.focus();
        });
    });
    function fieldError(el, box, message) {
        if (!box) { return; }
        box.textContent = message || '';
        box.hidden = !message;
        if (el) { el.setAttribute('aria-invalid', message ? 'true' : 'false'); }
    }
    // The hint is public metadata (spec §9): it must not be, contain, or
    // sit inside the password - that would hand the key to anyone holding
    // the archive.
    function hintRejected(hint, pw) {
        if (hint === '' || pw === '') { return ''; }
        var h = hint.toLowerCase();
        var p = pw.toLowerCase();
        if (h === p || h.indexOf(p) !== -1 || p.indexOf(h) !== -1) {
            return T('hintIsPassword',
                'The hint cannot be the password or part of it. Describe where it is kept instead, e.g. "company vault".');
        }
        return '';
    }
    function validateHint() {
        if (!encryptOn || !encryptOn.checked) { return ''; }
        var msg = hintRejected((hintInput || {}).value || '', (pwInput || {}).value || '');
        fieldError(hintInput, hintError, msg);
        return msg;
    }
    function validatePw2() {
        if (!encryptOn || !encryptOn.checked) { return ''; }
        var pw = (pwInput || {}).value || '';
        var pw2 = (pw2Input || {}).value || '';
        var msg = pw2 !== '' && pw2 !== pw
            ? T('pwMismatch', 'Passwords do not match.')
            : '';
        fieldError(pw2Input, pw2Error, msg);
        return msg;
    }
    if (encryptOn && encryptFields) {
        encryptOn.addEventListener('change', function () {
            encryptFields.hidden = !encryptOn.checked;
            if (encryptOn.checked) {
                // The user just opted in: put them in the first field,
                // not on the checkbox they already pressed.
                if (pwInput) { pwInput.focus(); }
            } else {
                fieldError(pwInput, pwError, '');
                fieldError(pw2Input, pw2Error, '');
                fieldError(hintInput, hintError, '');
            }
        });
    }
    if (hintInput) {
        hintInput.addEventListener('input', validateHint);
    }
    if (pw2Input) {
        pw2Input.addEventListener('input', validatePw2);
    }
    if (pwInput) {
        pwInput.addEventListener('input', function () {
            fieldError(pwInput, pwError, '');
            validatePw2();
            validateHint();
        });
    }

    var startExport = $('mudrava-start-export');
    if (startExport) {
        startExport.addEventListener('click', function () {
            var encrypt = !!(encryptOn && encryptOn.checked);
            var pw = encrypt ? ((pwInput || {}).value || '') : '';
            var pw2 = encrypt ? ((pw2Input || {}).value || '') : '';
            var hint = encrypt ? ((hintInput || {}).value || '') : '';
            if (encrypt && pw === '') {
                fieldError(pwInput, pwError, T('pwRequired',
                    'Choose a password, or turn off encryption to export unencrypted.'));
                if (pwInput) { pwInput.focus(); }
                return;
            }
            if (encrypt && pw2 !== pw) {
                fieldError(pw2Input, pw2Error, T('pwMismatch', 'Passwords do not match.'));
                if (pw2Input) { pw2Input.focus(); }
                return;
            }
            if (validateHint() !== '') { return; }
            // Excludes come from the pickers (unchecked items) plus the
            // free-text field for paths the folder list cannot show.
            var dirs = Object.keys(pickers.files.off);
            csvList('mudrava-exclude-dirs').forEach(function (d) {
                if (dirs.indexOf(d) === -1) { dirs.push(d); }
            });
            var body = {
                excludes: { tables: Object.keys(pickers.db.off), dirs: dirs },
                encrypted: !!encrypt
            };
            var splitBytes = currentSplitBytes();
            if (splitBytes > 0) { body.split_bytes = splitBytes; }
            if (hint !== '') { body.password_hint = hint; }
            startExport.disabled = true;
            resetProgress();
            // Open the blocking dialog before the first request: the
            // operator never has to scroll to find the progress, and the
            // page behind is frozen for the whole job.
            setPercent(0);
            setJobRunning(true, 'export');
            setStatus(T('working', 'Working'));
            api('/job/export', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function () {
                exportTick(pw || null);
            }).catch(function (e) {
                setStatus(T('failed', 'Failed') + ': ' + e.message);
                setJobRunning(false, 'export');
                startExport.disabled = false;
            });
        });
    }

    // Restore-point gate: honest headroom check before anything destructive.
    // safe -> recommended; unsafe -> the higher-risk checkbox arms the button.
    var rpEl = $('mudrava-restore-point');
    var unsafeBox = $('mudrava-proceed-unsafe');
    var rpState = 'unknown'; // unknown | safe | unsafe
    function applyRestorePoint() {
        if (!rpEl) { return; }
        if (rpState === 'safe') {
            rpEl.hidden = false;
            rpEl.className = 'mudrava-notice mudrava-notice-ok';
            rpEl.textContent = T(
                'rpSafe',
                'Restore point: recommended. There is enough free disk to keep a safety copy of this site before overwriting.'
            );
        } else if (rpState === 'unsafe') {
            rpEl.hidden = false;
            rpEl.className = 'mudrava-notice mudrava-notice-danger';
            rpEl.textContent = T('rpUnsafe', 'Not enough free disk for a safe restore point. Without one, a failed restore cannot be rolled back automatically. Check the box below to accept the higher risk.');
        } else {
            rpEl.hidden = true;
        }
        armRestore();
    }
    function loadRestorePoint() {
        api('/restore-point').then(function (r) {
            rpState = r && r.safe ? 'safe' : 'unsafe';
            applyRestorePoint();
        }).catch(function () {
            rpState = 'unknown';
            applyRestorePoint();
        });
    }
    loadRestorePoint();

    // Chunked upload + restore.
    var fileInput = $('mudrava-file');
    var dropzone = $('mudrava-dropzone');
    var fileNames = $('mudrava-file-names');
    var startRestore = $('mudrava-start-restore');
    var restoreGate = $('mudrava-restore-gate');
    var unsafeRow = $('mudrava-unsafe-row');
    var uploadedId = null;

    // The whole danger block (acknowledgement + "Restore site") is hidden
    // until a COMPLETE, byte-verified archive set is picked - an empty
    // drop zone or a lone part 1 of nineteen never offers to overwrite the
    // site. Inside the gate, the unsafe-acknowledgement row appears only
    // when the disk state really demands it; on safe/unknown disk the
    // button is the only step left.
    function armRestore() {
        if (!startRestore) { return; }
        var hasFiles = !!(fileInput && fileInput.files && fileInput.files.length);
        var setOk = !!(importSet && importSet.ok && hasFiles);
        if (restoreGate) { restoreGate.hidden = !setOk; }
        if (unsafeRow) { unsafeRow.hidden = !(setOk && rpState === 'unsafe'); }
        // Unsafe disk state requires the explicit acknowledgement; an
        // unknown state (fetch failed) must not silently block the operator.
        var acked = rpState === 'safe' || (rpState === 'unsafe' && unsafeBox && unsafeBox.checked);
        startRestore.disabled = !(setOk && acked);
    }

    function showFiles() {
        if (!fileNames) { return; }
        var files = fileInput.files;
        if (!files || !files.length) { fileNames.textContent = ''; return; }
        var total = 0;
        for (var i = 0; i < files.length; i++) { total += files[i].size; }
        var label = files.length === 1
            ? files[0].name + ' (' + humanBytes(total) + ')'
            : files.length + ' ' + T('partsTag', 'parts') + ', ' + humanBytes(total);
        if (importSet && importSet.ok && files.length === importSet.list.length) {
            label += ', ' + T('importSetComplete', 'set complete');
        }
        fileNames.textContent = label;
        if (dropzone) { dropzone.classList.add('is-armed'); }
    }

    // ---- split-set validation ------------------------------------------
    // An archive may only restore this site when its FULL part set is
    // picked. Names alone are not trusted (browsers can reorder, OSes
    // create "file (1).mudrava"), so the gate parses slots from names and
    // then verifies bytes: part-1 magic + flags + UUID, each part's own
    // continuation header (UUID + the slot it claims), and the end-of-
    // archive marker at the tail of the highest slot. The server mirrors
    // every check before the first destructive tick
    // (SplitSetSource::assertSetReadable), so this is the early honest
    // "why is Restore off?" - never the only line of defense.
    var importHold = $('mudrava-import-hold');
    var MAGIC_PART1 = 'MUDRAVA\0';
    var MAGIC_CONT = 'MUDRAVAP';
    var MAGIC_TAIL = 'MUDRAVAE';
    var FLAG_ENCRYPTED = 1;
    var FLAG_SPLIT = 2;
    var importSet = null; // {ok, list:[{slot,file}], expected, encrypted}

    function pad4(n) { var s = String(n); while (s.length < 4) { s = '0' + s; } return s; }
    function ascii(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
        return s;
    }
    function fmt(key, fallback, arg) {
        return T(key, fallback).replace('%s', arg);
    }
    function showHold(msg) {
        if (!importHold) { return; }
        if (msg) { importHold.textContent = msg; importHold.hidden = false; }
        else { importHold.hidden = true; }
    }

    function parseSlots(files) {
        var slots = {};
        var base = null;
        var unknown = [];
        var dup = [];
        var mixed = [];
        for (var i = 0; i < files.length; i++) {
            var name = files[i].name;
            var m = /^(.+\.mudrava)(?:\.part(\d{4,}))?$/i.exec(name);
            if (!m) { unknown.push(name); continue; }
            if (base === null) { base = m[1]; }
            else if (m[1].toLowerCase() !== base.toLowerCase()) { mixed.push(name); continue; }
            var n = m[2] ? parseInt(m[2], 10) : 1;
            if (slots[n]) { dup.push(name); continue; }
            slots[n] = files[i];
        }
        var nums = Object.keys(slots).map(Number).sort(function (a, b) { return a - b; });
        var list = [];
        for (var j = 0; j < nums.length; j++) { list.push({ slot: nums[j], file: slots[nums[j]] }); }
        var missing = [];
        if (list.length && list[0].slot === 1) {
            for (var k = 2; k <= list[list.length - 1].slot; k++) {
                if (!slots[k]) { missing.push(k); }
            }
        }
        return {
            list: list,
            missing: missing,
            unknown: unknown,
            dup: dup,
            mixed: mixed,
            top: list.length ? list[list.length - 1].slot : 0,
            hasFirst: list.length > 0 && list[0].slot === 1
        };
    }

    function headBytes(file, n) {
        return file.slice(0, n).arrayBuffer().then(function (b) { return new Uint8Array(b); });
    }
    function tailBytes(file, n) {
        return file.slice(Math.max(0, file.size - n)).arrayBuffer().then(function (b) { return new Uint8Array(b); });
    }
    function uuidHex(bytes, at) {
        var s = '';
        for (var i = at; i < at + 16; i++) { s += ('0' + bytes[i].toString(16)).slice(-2); }
        return s;
    }

    function validateSet(files) {
        var p = parseSlots(files);
        if (p.unknown.length) {
            return Promise.resolve({ ok: false, message: fmt('importHoldBadNames', 'Unrecognized files (must be *.mudrava or *.mudrava.partNNNN): %s', p.unknown.join(', ')) });
        }
        if (p.mixed.length) {
            return Promise.resolve({ ok: false, message: T('importHoldMixed', 'Files from different archives are selected. Pick the parts of ONE archive.') });
        }
        if (p.dup.length) {
            return Promise.resolve({ ok: false, message: fmt('importHoldDup', 'Duplicate parts selected: %s', p.dup.join(', ')) });
        }
        if (!p.hasFirst) {
            return Promise.resolve({ ok: false, message: T('importHoldNoFirst', 'Part 1 of the set is missing: the plain .mudrava file (no .partNNNN suffix).') });
        }
        if (p.missing.length) {
            var holes = p.missing.map(pad4).join(', ');
            return Promise.resolve({ ok: false, message: fmt('importHoldMissing', 'The set is incomplete. Missing parts: %s', holes) });
        }
        var last = p.list[p.list.length - 1];
        var first = p.list[0];
        var uuid = null;
        var encrypted = false;
        var SPLIT_MSG = T('importHoldSplit1', 'This is part 1 of a split set, not a complete archive. Select the .mudrava file AND every .mudrava.partNNNN file of this set.');
        return tailBytes(last.file, 8).then(function (tail) {
            if (last.file.size < 8 || ascii(tail) !== MAGIC_TAIL) {
                throw new Error(p.top === 1 ? SPLIT_MSG
                    : fmt('importHoldNoTail', 'The last selected file is not the end of the archive. Part %s is still missing.', pad4(p.top + 1)));
            }
            return headBytes(first.file, 4096);
        }).then(function (h) {
            if (h.length < 66 || ascii(h.subarray(0, 8)) !== MAGIC_PART1) {
                throw new Error(T('importHoldNoHeader', 'The first file is not a valid .mudrava archive (bad header).'));
            }
            var flags = h[12] | (h[13] << 8) | (h[14] << 16) | (h[15] << 24);
            encrypted = (flags & FLAG_ENCRYPTED) !== 0;
            // A split-capable export may still fit in one file. The tail
            // marker above proves this file is complete even when the
            // header records split mode.
            uuid = uuidHex(h, 16);
            var chain = Promise.resolve();
            p.list.forEach(function (item) {
                if (item.slot === 1) { return; }
                chain = chain.then(function () {
                    return headBytes(item.file, 28).then(function (c) {
                        if (c.length < 28 || ascii(c.subarray(0, 8)) !== MAGIC_CONT) {
                            throw new Error(fmt('importHoldBadSlot', 'Part %s is not a valid continuation part (renamed or wrong file).', pad4(item.slot)));
                        }
                        var num = (c[24] | (c[25] << 8) | (c[26] << 16) | (c[27] << 24)) >>> 0;
                        if (uuidHex(c, 8) !== uuid || num !== item.slot) {
                            throw new Error(fmt('importHoldWrongSlot', 'Slot %s holds a file that belongs to a different archive or a different part.', pad4(item.slot)));
                        }
                    });
                });
            });
            return chain;
        }).then(function () {
            return { ok: true, list: p.list, expected: p.top, encrypted: encrypted };
        }).catch(function (e) {
            return { ok: false, message: (e && e.message) ? e.message : T('importHoldUnreadable', 'Could not read the selected files. Select them again.') };
        });
    }

    // The destination of every restore is this site - never retyped, so
    // there is no URL field. Encryption state is read from the local
    // part-1 header during validateSet (flags at a fixed byte offset, no
    // upload needed) to decide whether a password is required; the server
    // independently refuses to run an encrypted set without one, and the
    // rewrite pair is derived from SITE_METADATA during import.
    var importPwBox = $('mudrava-import-pw');
    var importPwInput = $('mudrava-import-password');
    var rewriteLine = $('mudrava-rewrite-line');
    var rewriteFromLabel = $('mudrava-rewrite-from-label');
    var rewriteToLabel = $('mudrava-rewrite-to-label');

    function resetImportReveal() {
        uploadedId = null;
        importSet = null;
        showHold('');
        if (importPwBox) { importPwBox.hidden = true; }
        if (importPwInput) { importPwInput.value = ''; }
        if (rewriteLine) { rewriteLine.hidden = true; }
    }

    // Shared by the file picker and the dropzone: reset any previous
    // reveal, show the names, then byte-verify the picked set. The gate
    // opens only on the honest answer; every refusal names its reason.
    function onFilesChosen() {
        resetImportReveal();
        showFiles();
        armRestore();
        var files = fileInput.files;
        if (!files || !files.length) { return; }
        validateSet(files).then(function (v) {
            if (!v.ok) { showHold(v.message); armRestore(); return; }
            importSet = v;
            if (importPwBox) { importPwBox.hidden = !v.encrypted; }
            if (v.encrypted && importPwInput) { importPwInput.focus(); }
            showFiles();
            armRestore();
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', onFilesChosen);
    }
    if (unsafeBox) { unsafeBox.addEventListener('change', armRestore); }

    // Dropzone: click opens the picker; drops assign real File objects to
    // the hidden input so the upload path stays identical either way.
    if (dropzone && fileInput) {
        dropzone.addEventListener('click', function () { fileInput.click(); });
        dropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            dropzone.addEventListener(ev, function (e) {
                e.preventDefault();
                dropzone.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dropzone.addEventListener(ev, function (e) {
                e.preventDefault();
                dropzone.classList.remove('is-over');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            if (!dt || !dt.files || !dt.files.length) { return; }
            fileInput.files = dt.files;
            onFilesChosen();
        });
    }

    function uploadFiles(list) {
        // Chunk size is computed server-side from this host's real limits
        // (75% of min(upload_max_filesize, post_max_size), up to 16 MiB)
        // and localized as cfg.chunkBytes. Healthy hosts upload ~16x faster
        // than the old fixed 1 MiB; tiny-limit hosts still work because
        // chunking bypasses the single-request cap entirely. The set must
        // already be validated (validateSet): each entry carries the slot
        // number its own header claims, so parts are tagged by slot - not
        // by whatever order the OS file dialog happened to produce.
        var CHUNK = cfg.chunkBytes || 1048576;
        var storageKey = 'mudrava-upload-resume-v1';
        var signature = JSON.stringify(list.map(function (item) {
            return [item.slot, item.file.name, item.file.size, item.file.lastModified];
        }));
        var saved = null;
        try { saved = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null'); } catch (e) { /* storage disabled */ }
        var uploadId = saved && saved.signature === signature && /^up-[a-z0-9-]{3,61}$/.test(saved.id)
            ? saved.id
            : 'up-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
        function remember() {
            try { window.sessionStorage.setItem(storageKey, JSON.stringify({ id: uploadId, signature: signature })); }
            catch (e) { /* storage disabled */ }
        }
        function forget() {
            try { window.sessionStorage.removeItem(storageKey); } catch (e) { /* storage disabled */ }
        }
        function requestWithRetry(path, opts, attempt) {
            return api(path, opts).catch(function (e) {
                if (attempt >= 4 || (e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429)) { throw e; }
                return new Promise(function (resolve) {
                    setTimeout(resolve, Math.min(8000, 500 * Math.pow(2, attempt)));
                }).then(function () { return requestWithRetry(path, opts, attempt + 1); });
            });
        }
        var parts = list[list.length - 1].slot;
        var sizes = [];
        var grandTotal = 0;
        var i;
        for (i = 0; i < list.length; i++) {
            var t = Math.ceil(list[i].file.size / CHUNK) || 1;
            sizes.push(t);
            grandTotal += t;
        }
        var doneChunks = 0;
        var offsets = [];
        function uploadItem(i, idx) {
            if (idx >= sizes[i]) { return Promise.resolve(); }
            var item = list[i];
            var slice = item.file.slice(idx * CHUNK, (idx + 1) * CHUNK);
            var fd = new FormData();
            fd.append('file', slice, 'chunk');
            fd.append('part', String(item.slot));
            fd.append('parts', String(parts));
            fd.append('index', String(idx));
            fd.append('total', String(sizes[i]));
            return requestWithRetry('/upload/' + uploadId, { method: 'POST', body: fd }, 0).then(function (r) {
                doneChunks++;
                var pct = Math.round((doneChunks / grandTotal) * 100);
                setPercent(pct);
                setStatus(T('uploading', 'Uploading') + ' ' + pct + '%');
                if (r.done) { uploadedId = r.archive_id; }
                return uploadItem(i, idx + 1);
            });
        }
        function nextItem(i) {
            if (i >= list.length) { return Promise.resolve(); }
            return uploadItem(i, offsets[i]).then(function () { return nextItem(i + 1); });
        }
        function finalize() {
            return requestWithRetry('/upload/' + uploadId + '/finalize', { method: 'POST' }, 0).then(function (r) {
                if (r.done) {
                    uploadedId = r.archive_id;
                    forget();
                    return;
                }
                var pct = Math.round(100 * (r.assembled || 0) / (r.total || 1));
                setStatus(T('assembling', 'Assembling archive') + ' ' + pct + '%');
                return finalize();
            });
        }
        function begin(state) {
            var incompatible = (saved && saved.id === uploadId && !state.found)
                || (state.found && state.parts !== parts);
            for (var j = 0; j < list.length && !incompatible; j++) {
                var slot = list[j].slot;
                var expected = Number((state.expected || {})[slot] || 0);
                var received = Number((state.received || {})[slot] || 0);
                if ((expected && expected !== sizes[j]) || received > sizes[j]) { incompatible = true; }
            }
            if (incompatible) {
                uploadId = 'up-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
                state = { found: false };
            }
            remember();
            if (state.done) {
                uploadedId = state.archive_id;
                forget();
                return Promise.resolve();
            }
            for (var i = 0; i < list.length; i++) {
                offsets[i] = Number((state.received || {})[list[i].slot] || 0);
                doneChunks += offsets[i];
            }
            var pct = Math.round((doneChunks / grandTotal) * 100);
            setPercent(pct);
            setStatus(T('uploading', 'Uploading') + ' ' + pct + '%');
            return (state.assembling ? Promise.resolve() : nextItem(0)).then(finalize);
        }
        if (saved && saved.id === uploadId) {
            return requestWithRetry('/upload/' + uploadId + '/status', { method: 'GET' }, 0).then(begin);
        }
        return begin({ found: false });
    }

    // The destination of every restore is this site - never retyped, so
    // there is no URL field anywhere in the UI. The source is read from
    // the uploaded archive (SITE_METADATA) purely to show the detected
    // pair inside the progress dialog; the engine derives the same pair
    // server-side even when this peek fails (sealed archive, etc.).
    function detectSourceUrl(archiveId, password) {
        return api('/archive-info', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ archive_id: archiveId, password: password || '' })
        }).then(function (info) {
            if (info && info.source_url && rewriteLine) {
                if (rewriteFromLabel) { rewriteFromLabel.textContent = info.source_url; }
                if (rewriteToLabel) { rewriteToLabel.textContent = cfg.homeUrl || ''; }
                rewriteLine.hidden = false;
            }
        }).catch(function () { /* the engine auto-derives; nothing to show */ });
    }

    function createRestorePoint() {
        setStatus(T('rpCreating', 'Saving a restore point of this site'));
        return api('/job/restore-point', { method: 'POST' }).then(function (started) {
            function next() {
                return api('/job/tick', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: '{}'
                }).then(function (job) {
                    setJobPercent(job);
                    renderProgress(job);
                    if (job.state === 'failed') {
                        throw new Error(job.error || T('unknown', 'unknown'));
                    }
                    if (job.state === 'done') { return started.archive_id; }
                    return new Promise(function (resolve) { setTimeout(resolve, 1500); }).then(next);
                });
            }
            return next();
        });
    }

    if (startRestore) {
        startRestore.addEventListener('click', function () {
            var set = importSet;
            if (!set || !set.ok || !set.list.length) { return; }
            if (!window.confirm(T('confirm', 'This will overwrite the current site. Are you sure?'))) { return; }
            startRestore.disabled = true;
            var pw = (importPwInput || {}).value || null;
            var restorePointId = '';
            // The dialog opens before the first byte moves: upload and
            // import both run behind it, so the operator always sees the
            // live job no matter where the page is scrolled.
            resetProgress();
            setPercent(0);
            setJobRunning(true, 'import');
            if (phaseEl) { phaseEl.textContent = T('uploading', 'Uploading'); }
            setStatus(T('uploading', 'Uploading') + ' 0%');
            uploadFiles(set.list).then(function () {
                if (!uploadedId) { throw new Error(T('uploadIncomplete', 'upload incomplete')); }
                return detectSourceUrl(uploadedId, pw).then(function () {
                    if (unsafeBox && unsafeBox.checked) { return null; }
                    return createRestorePoint().then(function (id) { restorePointId = id; });
                }).then(function () {
                    return api('/job/import', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            archive_id: uploadedId,
                            expected_parts: set.expected,
                            // Empty pair on purpose: the engine derives
                            // source -> this site from the archive itself.
                            url_rewrite: { search: '', replace: '' },
                            restore_point_archive_id: restorePointId,
                            proceed_unsafe: !!(unsafeBox && unsafeBox.checked)
                        })
                    });
                });
            }).then(function (job) {
                saveRestoreToken((job && job.restore_token) || null, job && job.archive_id);
                setStatus(T('working', 'Working'));
                exportTick(pw);
            }).catch(function (e) {
                // The server pre-flight answers part details in `message`;
                // show the honest sentence, not just the bare code.
                var why = (e && e.body && e.body.message) ? e.body.message : (e && e.message) || '';
                setStatus(T('failed', 'Failed') + ': ' + why);
                setJobRunning(false, 'import');
                armRestore();
            });
        });
    }

    function requireResumePassword() {
        if (exportTimer) { clearTimeout(exportTimer); exportTimer = null; }
        if (resumeControls) { resumeControls.hidden = false; }
        if (resumePassword) { resumePassword.value = ''; resumePassword.focus(); }
        setStatus(T('resumePassword', 'Enter the archive password to continue.'));
    }
    if (resumeButton) {
        resumeButton.addEventListener('click', function () {
            var password = resumePassword ? resumePassword.value : '';
            if (!password) { if (resumePassword) { resumePassword.focus(); } return; }
            if (resumeControls) { resumeControls.hidden = true; }
            if (resumePassword) { resumePassword.value = ''; }
            setStatus(T('working', 'Working…'));
            exportTick(password);
        });
    }
    if (resumePassword) {
        resumePassword.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && resumeButton) { e.preventDefault(); resumeButton.click(); }
        });
    }

    // A crash or closed tab may leave an active job or completed recovery.
    // Reopen its dialog and continue without saving the encryption password.
    api('/job').then(function (job) {
        if (jobRunning) { return; }
        if (job.state === 'done' || job.state === 'failed') {
            saveRestoreToken(null, '');
        }
        if (job.state === 'running' || job.state === 'rolling_back') {
            try {
                var saved = JSON.parse(window.sessionStorage.getItem('mudrava-restore-token') || 'null');
                if (saved && saved.archive_id === job.archive_id) {
                    restoreToken = saved.token;
                    restoreTokenJob = saved.archive_id;
                }
            } catch (e) { /* Storage may be disabled. */ }
            resetProgress();
            setJobPercent(job);
            renderProgress(job);
            setJobRunning(true, job.kind);
            if (job.state === 'running' && (job.encrypted === true || job.note === 'password_required')) {
                requireResumePassword();
            } else {
                setStatus(T('resuming', 'Resuming the interrupted job…'));
                exportTick(null);
            }
            return;
        }
        if (job.state !== 'failed') { return; }
        var key = 'mudrava-failure-' + job.archive_id + '-' + job.revision;
        try {
            if (window.sessionStorage.getItem(key) === 'dismissed') { return; }
        } catch (e) { /* storage may be disabled */ }
        resetProgress();
        setPercent(job.percent || 100);
        renderProgress(job);
        showFailedJob(job);
    }).catch(function () { /* WordPress may require a fresh login. */ });
})();
