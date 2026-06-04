/* global wprestiData */
(function () {
    'use strict';

    var tabLinks = document.querySelectorAll('#pp-tabs .pp-tab-pill');

    function switchTab(target) {
        tabLinks.forEach(function (l) {
            l.classList.toggle('nav-tab-active', l.getAttribute('data-tab') === target);
        });
        document.querySelectorAll('.pp-tab-panel').forEach(function (panel) {
            panel.style.display = panel.id === 'pp-tab-' + target ? 'block' : 'none';
        });
        try {
            localStorage.setItem('wpresti_active_tab', target);
        } catch (e) { /* ignore */ }
    }

    tabLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            switchTab(this.getAttribute('data-tab'));
        });
    });

    try {
        var savedTab = localStorage.getItem('wpresti_active_tab');
        if (savedTab && document.getElementById('pp-tab-' + savedTab)) {
            switchTab(savedTab);
        }
    } catch (e) { /* ignore */ }

    var credsNotice  = document.getElementById('pp-creds-notice');
    var dismissBtn   = document.getElementById('pp-creds-notice-dismiss');
    if (credsNotice) {
        try {
            if (localStorage.getItem('wpresti_creds_dismissed') === '1') {
                credsNotice.style.display = 'none';
            }
        } catch (e) { /* ignore */ }
    }
    if (dismissBtn && credsNotice) {
        dismissBtn.addEventListener('click', function () {
            credsNotice.style.display = 'none';
            try {
                localStorage.setItem('wpresti_creds_dismissed', '1');
            } catch (e) { /* ignore */ }
        });
    }

    var form           = document.getElementById('wpresti-form');
    var submitBtn      = document.getElementById('pp-submit');
    var statusArea     = document.getElementById('pp-status-area');
    var importActions  = document.getElementById('pp-import-actions');
    var resumeBtn      = document.getElementById('pp-resume-import');
    var statusCard     = document.querySelector('.pp-status-card');
    var idleState      = document.getElementById('pp-idle-state');
    var importSummary  = document.getElementById('pp-import-summary');
    var progressBar    = document.getElementById('pp-progress-bar');
    var progressBarWrap = document.getElementById('pp-progress-bar-wrap');
    var progressPct    = document.getElementById('pp-progress-pct');
    var progressSpinner = document.getElementById('pp-progress-spinner');
    var progressTxt    = document.getElementById('pp-progress-text');
    var statDone       = document.getElementById('pp-stat-done');
    var statQueued     = document.getElementById('pp-stat-queued');
    var statSkipped    = document.getElementById('pp-stat-skipped');
    var elapsedTxt     = document.getElementById('pp-elapsed');
    var logEmptyRow    = document.getElementById('pp-log-empty');
    var logBody        = document.getElementById('pp-log-body');
    var logFooter      = document.getElementById('pp-log-footer');
    var logLoadMore    = document.getElementById('pp-log-load-more');
    var logCount       = document.getElementById('pp-log-count');
    var notice         = document.getElementById('pp-notice');
    var noticeTxt      = document.getElementById('pp-notice-text');
    var testBtn        = document.getElementById('pp-test-connection');
    var testResult     = document.getElementById('pp-test-result');
    var cancelBtn      = document.getElementById('pp-cancel-import');
    var clearBtn       = document.getElementById('pp-clear-session');
    var downloadBtn    = document.getElementById('pp-download-log');
    var settingsForm   = document.getElementById('wpresti-settings-form');
    var settingsNotice = document.getElementById('pp-settings-notice');

    var total        = 0;
    var done         = 0;
    var queued       = 0;
    var skipped      = 0;
    var logShown     = 0;
    var logTotal     = 0;
    var isRunning    = false;
    var backgroundPoll = null;

    function getImportFormBody() {
        var body = new FormData();
        body.append('nonce',               wprestiData.nonce);
        body.append('site_url',            document.getElementById('pp-site-url').value.trim());
        body.append('import_type',         document.getElementById('pp-import-type').value);
        body.append('target_post_type',    (document.getElementById('pp-target-post-type') || {}).value || '');
        body.append('import_mode',         document.getElementById('pp-import-mode').value);
        body.append('assign_author_id',    document.getElementById('pp-assign-author').value);
        var scopeEl = document.querySelector('input[name="pp_import_scope"]:checked');
        if (scopeEl) {
            body.append('import_scope', scopeEl.value);
        }
        var slugVal = (document.getElementById('pp-slug') || {}).value.trim();
        if (slugVal) {
            body.append('slug', slugVal);
        }
        if (!isSlugImportScope() && !slugVal) {
            body.append('date_after',    (document.getElementById('pp-date-after') || {}).value || '');
            body.append('date_before',   (document.getElementById('pp-date-before') || {}).value || '');
            body.append('category',      (document.getElementById('pp-category') || {}).value.trim());
            body.append('status_filter', (document.getElementById('pp-status-filter') || {}).value || '');
            body.append('cpt_rest_base', (document.getElementById('pp-cpt-rest-base') || {}).value.trim());
        }
        body.append('source_username',     ((document.getElementById('pp-source-username') || {}).value || '').trim());
        body.append('source_app_password', (document.getElementById('pp-source-app-password') || {}).value || '');
        if (document.getElementById('pp-run-background') && document.getElementById('pp-run-background').checked) {
            body.append('run_in_background', '1');
        }
        return body;
    }

    function setFormDisabled(disabled) {
        if (!form) return;
        form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
            if (el.id === 'pp-cancel-import' || el.id === 'pp-clear-session' || el.id === 'pp-download-log' || el.id === 'pp-resume-import') {
                return;
            }
            el.disabled = disabled;
        });
    }

    function showResumeButton(show) {
        if (resumeBtn) resumeBtn.style.display = show ? '' : 'none';
    }

    var slugInput       = document.getElementById('pp-slug');
    var slugSection     = document.getElementById('pp-slug-section');
    var fetchLimits     = document.getElementById('pp-fetch-limits');
    var scopeInputs     = document.querySelectorAll('input[name="pp_import_scope"]');
    var importTypeSelect = document.getElementById('pp-import-type');
    var segmentBtns     = document.querySelectorAll('.pp-segment-btn');
    var flowSourceUrl   = document.getElementById('pp-flow-source-url');
    var siteUrlInput    = document.getElementById('pp-site-url');

    function isSlugImportScope() {
        var checked = document.querySelector('input[name="pp_import_scope"]:checked');
        return checked && checked.value === 'slug';
    }

    function syncImportScopeUI() {
        var slugMode = isSlugImportScope();
        if (slugSection) {
            slugSection.classList.toggle('is-hidden', !slugMode);
        }
        if (fetchLimits) {
            fetchLimits.classList.toggle('is-hidden', slugMode);
        }
        if (slugInput) {
            slugInput.required = slugMode;
            slugInput.setAttribute('aria-required', slugMode ? 'true' : 'false');
        }
        updateImportSummary();
    }

    scopeInputs.forEach(function (input) {
        input.addEventListener('change', syncImportScopeUI);
    });

    if (slugInput) {
        slugInput.addEventListener('input', function () {
            if (slugInput.value.trim() && !isSlugImportScope()) {
                var slugRadio = document.querySelector('input[name="pp_import_scope"][value="slug"]');
                if (slugRadio) {
                    slugRadio.checked = true;
                }
            }
            syncImportScopeUI();
        });
    }

    ['pp-import-type', 'pp-target-post-type', 'pp-import-mode', 'pp-site-url'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', updateImportSummary);
            el.addEventListener('input', updateImportSummary);
        }
    });

    syncImportScopeUI();
    updateImportSummary();

    function setImportPanelActive(active) {
        if (statusCard) {
            statusCard.classList.toggle('pp-import-active', active);
        }
        if (statusArea) {
            statusArea.style.display = active ? 'block' : 'none';
        }
        if (importActions && active) {
            importActions.style.display = 'flex';
        }
    }

    function updateFlowPreview() {
        if (!flowSourceUrl || !siteUrlInput) return;
        var url = siteUrlInput.value.trim();
        flowSourceUrl.textContent = url || (wprestiData.i18n && wprestiData.i18n.flowPlaceholder) || 'Enter source URL…';
        flowSourceUrl.classList.toggle('is-placeholder', !url);
    }

    function updateImportSummary() {
        updateFlowPreview();
        if (!importSummary) return;
        var i18n = wprestiData.i18n || {};
        var chips = [];
        chips.push('<span class="pp-chip pp-chip-accent">' + escHtml(isSlugImportScope() ? (i18n.summarySlug || 'By slug') : (i18n.summaryFull || 'Full import')) + '</span>');
        var typeMap = {
            both: i18n.summaryTypeBoth || 'Posts & pages',
            posts: i18n.summaryTypePosts || 'Posts',
            pages: i18n.summaryTypePages || 'Pages'
        };
        var typeVal = importTypeSelect ? importTypeSelect.value : 'both';
        chips.push('<span class="pp-chip">' + escHtml(typeMap[typeVal] || '') + '</span>');
        var targetEl = document.getElementById('pp-target-post-type');
        var targetShort = targetEl && targetEl.value
            ? targetEl.options[targetEl.selectedIndex].text.split('(')[0].trim()
            : 'Same type';
        chips.push('<span class="pp-chip">' + escHtml(targetShort) + '</span>');
        if (isSlugImportScope() && slugInput && slugInput.value.trim()) {
            var slugs = slugInput.value.trim().split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            var slugLabel = slugs.length === 1 ? slugs[0] : slugs.length + ' slugs';
            chips.push('<span class="pp-chip pp-chip-slug">' + escHtml(slugLabel) + '</span>');
        }
        importSummary.innerHTML = chips.join('');
    }

    segmentBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-type');
            if (importTypeSelect) importTypeSelect.value = val;
            segmentBtns.forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            updateImportSummary();
        });
    });

    if (siteUrlInput) {
        siteUrlInput.addEventListener('input', updateFlowPreview);
        siteUrlInput.addEventListener('change', updateFlowPreview);
    }
    updateFlowPreview();

    function showProgressSpinner(show) {
        if (progressSpinner) {
            progressSpinner.style.display = show ? '' : 'none';
        }
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            startImport();
        });
    }

    if (testBtn) {
        testBtn.addEventListener('click', testConnection);
    }

    if (logLoadMore) {
        logLoadMore.addEventListener('click', loadMoreLog);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            if (!window.confirm(wprestiData.i18n.confirmCancel)) return;
            cancelImport();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!window.confirm(wprestiData.i18n.confirmClear)) return;
            clearSession();
        });
    }

    if (downloadBtn) {
        downloadBtn.addEventListener('click', downloadLog);
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            saveSettings();
        });
    }

    function testConnection() {
        var i18n = wprestiData.i18n || {};
        var body = getImportFormBody();
        body.append('action', 'wpresti_test_connection');
        testBtn.disabled = true;
        setButtonText(testBtn, ' ' + (i18n.testing || 'Testing…'));
        testResult.style.display = 'block';
        testResult.className = 'pp-test-result';
        testResult.textContent = i18n.testing || 'Testing…';

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                testBtn.disabled = false;
                setButtonText(testBtn, ' ' + (i18n.testConnection || 'Test Connection'));
                if (!resp.success) {
                    testResult.className = 'pp-test-result pp-test-error';
                    testResult.textContent = resp.data.message || 'Connection failed.';
                    return;
                }
                var msg = resp.data.message || 'OK';
                if (resp.data.auth_warning) {
                    msg += ' ' + resp.data.auth_warning;
                }
                if (resp.data.has_raw) {
                    msg += ' Raw block content available.';
                }
                testResult.className = 'pp-test-result pp-test-ok';
                testResult.textContent = msg;
            })
            .catch(function (err) {
                testBtn.disabled = false;
                setButtonText(testBtn, ' ' + (i18n.testConnection || 'Test Connection'));
                testResult.className = 'pp-test-result pp-test-error';
                testResult.textContent = err.message;
            });
    }

    function startImport() {
        if (!document.getElementById('pp-site-url').value.trim()) {
            showNotice('error', 'Please enter a source site URL.');
            return;
        }
        if (isSlugImportScope() && (!slugInput || !slugInput.value.trim())) {
            showNotice('error', 'Enter at least one slug for a slug import.');
            if (slugInput) slugInput.focus();
            return;
        }

        stopBackgroundPoll();
        showResumeButton(false);
        setSubmitState(true);
        setFormDisabled(true);
        resetUI();
        setImportPanelActive(true);
        showProgressSpinner(true);
        showNotice('info', 'Starting import…');

        var body = getImportFormBody();
        body.append('action', 'wpresti_start');

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    showNotice('error', resp.data.message || 'Failed to start import.');
                    setImportPanelActive(false);
                    endRun();
                    return;
                }

                total  = resp.data.total || 0;
                done   = 0;
                queued = resp.data.queued || 0;
                showNotice('info', resp.data.message);
                updateProgress();

                if (resp.data.background) {
                    showNotice('info', 'Import running in background. You may close this tab.');
                    startBackgroundPoll();
                } else {
                    runStep();
                }
            })
            .catch(function (err) {
                showNotice('error', 'Network error: ' + err.message);
                setImportPanelActive(false);
                endRun();
            });
    }

    function runStep() {
        showResumeButton(false);
        var body = getImportFormBody();
        body.append('action', 'wpresti_step');

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    showNotice('error', resp.data.message || 'Import step failed.');
                    endRun();
                    return;
                }

                applyStepResponse(resp.data);

                if (resp.data.complete) {
                    onComplete();
                } else {
                    runStep();
                }
            })
            .catch(function (err) {
                showNotice('error', 'Network error: ' + err.message);
                endRun();
            });
    }

    function applyStepResponse(data) {
        if (data.total) total = data.total;
        done     = data.done;
        queued   = data.queued || 0;
        skipped  = data.skipped || 0;
        if (data.log_total !== undefined) logTotal = data.log_total;
        if (data.message) showNotice('info', data.message);
        if (data.elapsed && elapsedTxt) elapsedTxt.textContent = data.elapsed;
        updateProgress();
        appendLogRows(data.log || [], 'prepend');
    }

    function startBackgroundPoll() {
        stopBackgroundPoll();
        pollProgress();
        backgroundPoll = window.setInterval(pollProgress, 5000);
    }

    function stopBackgroundPoll() {
        if (backgroundPoll) {
            window.clearInterval(backgroundPoll);
            backgroundPoll = null;
        }
    }

    function pollProgress() {
        var body = new FormData();
        body.append('action', 'wpresti_get_progress');
        body.append('nonce',  wprestiData.nonce);

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success || resp.data.phase === 'idle' || resp.data.phase === 'cancelled') {
                    stopBackgroundPoll();
                    endRun();
                    return;
                }

                total   = resp.data.total || 0;
                done    = resp.data.done || 0;
                queued  = resp.data.queued || 0;
                skipped = resp.data.skipped || 0;
                logTotal = resp.data.log_total || 0;
                updateProgress();

                if (resp.data.log && resp.data.log.length) {
                    logBody.innerHTML = '';
                    logShown = 0;
                    appendLogRows(resp.data.log.slice().reverse(), 'prepend');
                }

                if (resp.data.complete || resp.data.phase === 'complete') {
                    stopBackgroundPoll();
                    onComplete();
                }
            })
            .catch(function () { /* ignore transient poll errors */ });
    }

    function cancelImport() {
        var body = new FormData();
        body.append('action', 'wpresti_cancel');
        body.append('nonce',  wprestiData.nonce);
        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body }).then(function () {
            stopBackgroundPoll();
            showNotice('warning', 'Import cancelled.');
            endRun();
        });
    }

    function clearSession() {
        var body = new FormData();
        body.append('action', 'wpresti_clear_session');
        body.append('nonce',  wprestiData.nonce);
        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body }).then(function () {
            stopBackgroundPoll();
            resetUI();
            setImportPanelActive(false);
            if (importActions) importActions.style.display = 'none';
            if (notice) notice.style.display = 'none';
            endRun();
        });
    }

    function downloadLog() {
        var body = new FormData();
        body.append('action', 'wpresti_download_log');
        body.append('nonce',  wprestiData.nonce);
        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) return;
                var csv = atob(resp.data.content);
                var blob = new Blob([csv], { type: 'text/csv' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = resp.data.filename;
                a.click();
            });
    }

    function saveSettings() {
        var body = new FormData(settingsForm);
        body.append('action', 'wpresti_save_settings');
        body.append('nonce',  wprestiData.nonce);
        if (!settingsForm.querySelector('[name="ssl_verify"]').checked) {
            body.delete('ssl_verify');
        }
        if (!settingsForm.querySelector('[name="email_on_complete"]').checked) {
            body.delete('email_on_complete');
        }

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!settingsNotice) return;
                settingsNotice.style.display = 'block';
                settingsNotice.className = 'pp-settings-notice ' + (resp.success ? 'notice-success' : 'notice-error');
                settingsNotice.textContent = resp.success ? resp.data.message : (resp.data.message || 'Error');
                if (resp.success && resp.data.settings) {
                    wprestiData.settings = resp.data.settings;
                }
            });
    }

    function resumeImport() {
        var body = new FormData();
        body.append('action', 'wpresti_get_progress');
        body.append('nonce',  wprestiData.nonce);

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success || resp.data.phase === 'idle' || resp.data.phase === 'complete' || resp.data.phase === 'cancelled') {
                    return;
                }

                total    = resp.data.total || 0;
                done     = resp.data.done || 0;
                queued   = resp.data.queued || 0;
                skipped  = resp.data.skipped || 0;
                logTotal = resp.data.log_total || 0;

                setSubmitState(true);
                setFormDisabled(true);
                setImportPanelActive(true);
                showProgressSpinner(!resp.data.complete);
                if (importActions) importActions.style.display = 'flex';
                updateProgress();
                logBody.innerHTML = '';
                logShown = 0;
                ensureLogEmptyRow();
                if (resp.data.log && resp.data.log.length) {
                    appendLogRows(resp.data.log.slice().reverse(), 'prepend');
                }

                if (resp.data.background) {
                    showNotice('info', 'Resuming background import…');
                    startBackgroundPoll();
                } else {
                    showNotice('info', 'Import paused. Click Resume to continue, or Cancel / Clear session.');
                    showResumeButton(true);
                }
            });
    }

    if (resumeBtn) {
        resumeBtn.addEventListener('click', function () {
            showResumeButton(false);
            setSubmitState(true);
            setFormDisabled(true);
            showProgressSpinner(true);
            showNotice('info', 'Resuming import…');
            runStep();
        });
    }

    resumeImport();

    function loadMoreLog() {
        if (!logLoadMore || logShown >= logTotal) return;
        logLoadMore.disabled = true;
        var body = new FormData();
        body.append('action', 'wpresti_get_log');
        body.append('nonce',  wprestiData.nonce);
        body.append('offset', String(logShown));

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                logLoadMore.disabled = false;
                if (!resp.success) return;
                logTotal = resp.data.log_total || logTotal;
                appendLogRows(resp.data.log || [], 'append');
                updateLogFooter();
            })
            .catch(function () { logLoadMore.disabled = false; });
    }

    function ensureLogEmptyRow() {
        if (!logBody || !logEmptyRow) return;
        if (!logBody.contains(logEmptyRow)) {
            logBody.appendChild(logEmptyRow);
        }
        logEmptyRow.classList.remove('is-hidden');
    }

    function hideLogEmptyRow() {
        if (logEmptyRow) {
            logEmptyRow.classList.add('is-hidden');
        }
    }

    function updateProgress() {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        progressBar.style.width = pct + '%';
        if (progressPct) progressPct.textContent = pct + '%';
        if (progressBarWrap) progressBarWrap.setAttribute('aria-valuenow', String(pct));
        if (statDone) statDone.textContent = String(done);
        if (statQueued) statQueued.textContent = String(queued);
        if (statSkipped) statSkipped.textContent = String(skipped);
        var label = done + ' of ' + total + ' imported';
        if (queued > 0 && !isRunning) {
            label += ' — ' + queued + ' in queue';
        }
        progressTxt.textContent = label;
    }

    function appendLogRows(rows, mode) {
        if (rows.length) hideLogEmptyRow();
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            var allowedActions = ['created', 'updated', 'error', 'skipped'];
            var rawAction = (row.action || 'error').toLowerCase();
            var statusClass = 'pp-status-' + (allowedActions.indexOf(rawAction) !== -1 ? rawAction : 'error');
            var formatBadge = '';
            if (row.format === 'gutenberg-raw') {
                formatBadge = '<span class="pp-format-badge pp-format-gutenberg">Gutenberg</span>';
            } else if (row.format === 'gutenberg-reconstructed') {
                formatBadge = '<span class="pp-format-badge pp-format-gutenberg-reconstructed">Gutenberg</span>';
            } else if (row.format === 'classic') {
                formatBadge = '<span class="pp-format-badge pp-format-classic">Classic</span>';
            }
            tr.innerHTML =
                '<td>' + escHtml(row.title  || '') + '</td>' +
                '<td>' + escHtml(row.type   || '') + '</td>' +
                '<td>' + formatBadge + '</td>' +
                '<td class="' + statusClass + '">' + escHtml(row.status || '') + '</td>' +
                '<td>' + escHtml(row.time   || '') + '</td>';
            if (mode === 'append') {
                logBody.appendChild(tr);
            } else {
                logBody.insertBefore(tr, logBody.firstChild);
            }
        });
        logShown += rows.length;
        updateLogFooter();
    }

    function updateLogFooter() {
        if (!logFooter || !logCount) return;
        if (logTotal > 0) {
            logFooter.style.display = 'flex';
            logCount.textContent = 'Showing ' + Math.min(logShown, logTotal) + ' of ' + logTotal + ' log entries';
            logLoadMore.style.display = logShown < logTotal ? 'inline-flex' : 'none';
        } else {
            logFooter.style.display = 'none';
        }
    }

    function onComplete() {
        queued = 0;
        showProgressSpinner(false);
        progressBar.classList.add('pp-complete');
        progressBar.style.width = '100%';
        if (progressPct) progressPct.textContent = '100%';
        if (progressBarWrap) progressBarWrap.setAttribute('aria-valuenow', '100');
        progressTxt.textContent = done + ' of ' + total + ' imported — Complete!';
        showNotice('success', 'Import complete! ' + done + ' imported, ' + skipped + ' skipped.');
        endRun();
        updateProgress();
        updateLogFooter();
    }

    function endRun() {
        showResumeButton(false);
        setSubmitState(false);
        setFormDisabled(false);
        isRunning = false;
    }

    function resetUI() {
        if (logBody) {
            logBody.innerHTML = '';
            ensureLogEmptyRow();
        }
        progressBar.style.width = '0%';
        progressBar.classList.remove('pp-complete');
        if (progressPct) progressPct.textContent = '0%';
        if (progressBarWrap) progressBarWrap.setAttribute('aria-valuenow', '0');
        progressTxt.textContent = '0 of 0 imported';
        if (elapsedTxt) elapsedTxt.textContent = '';
        done = 0; total = 0; queued = 0; skipped = 0; logShown = 0; logTotal = 0;
        if (logFooter) logFooter.style.display = 'none';
        updateProgress();
    }

    function setButtonText(btn, text) {
        var nodes = btn.childNodes;
        for (var i = nodes.length - 1; i >= 0; i--) {
            if (nodes[i].nodeType === 3) { nodes[i].nodeValue = text; break; }
        }
    }

    function setSubmitState(disabled) {
        var i18n = wprestiData.i18n || {};
        isRunning = disabled;
        submitBtn.disabled = disabled;
        setButtonText(submitBtn, disabled ? (' ' + (i18n.importing || 'Importing…')) : (' ' + (i18n.startImport || 'Start Import')));
    }

    function showNotice(type, message) {
        notice.className = 'notice notice-' + type;
        noticeTxt.textContent = message;
        notice.style.display = 'block';
    }

    /* Reassign tab — unchanged handlers */
    var scanBtn = document.getElementById('pp-scan-btn');
    var reassignResults = document.getElementById('pp-reassign-results');
    var reassignBody = document.getElementById('pp-reassign-body');
    var runReassignBtn = document.getElementById('pp-run-reassign-btn');
    var reassignNotice = document.getElementById('pp-reassign-notice');
    var reassignNoticeTxt = document.getElementById('pp-reassign-notice-text');

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            scanBtn.disabled = true;
            setButtonText(scanBtn, ' Scanning…');
            var body = new FormData();
            body.append('action', 'wpresti_reassign_scan');
            body.append('nonce', wprestiData.nonce);
            fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    scanBtn.disabled = false;
                    setButtonText(scanBtn, ' Scan Imported Posts');
                    if (!resp.success) {
                        showReassignNotice('error', resp.data.message || 'Scan failed.');
                        return;
                    }
                    var groups = resp.data.groups || [];
                    if (!groups.length) {
                        showReassignNotice('info', 'No imported posts with original author data found.');
                        return;
                    }
                    reassignBody.innerHTML = '';
                    groups.forEach(function (g) {
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td>' + escHtml(g.login || '(empty)') + '</td>' +
                            '<td>' + escHtml(g.name || '') + '</td>' +
                            '<td>' + escHtml(String(g.post_count)) + '</td>' +
                            '<td>' + (g.matched ? escHtml(g.wp_user) : '<em>No user</em>') + '</td>' +
                            '<td>' + (g.matched ? '<span class="pp-status-created">Ready</span>' : '<span class="pp-status-error">No user</span>') + '</td>';
                        reassignBody.appendChild(tr);
                    });
                    reassignResults.style.display = 'block';
                    reassignNotice.style.display = 'none';
                });
        });
    }

    if (runReassignBtn) {
        runReassignBtn.addEventListener('click', function () {
            runReassignBtn.disabled = true;
            var body = new FormData();
            body.append('action', 'wpresti_reassign_run');
            body.append('nonce', wprestiData.nonce);
            fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    runReassignBtn.disabled = false;
                    if (!resp.success) {
                        showReassignNotice('error', resp.data.message || 'Failed.');
                        return;
                    }
                    showReassignNotice('success', resp.data.reassigned + ' reassigned, ' + resp.data.unmatched + ' unmatched.');
                    scanBtn.click();
                });
        });
    }

    function showReassignNotice(type, message) {
        reassignNotice.className = 'notice notice-' + type;
        reassignNoticeTxt.textContent = message;
        reassignNotice.style.display = 'block';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}());
