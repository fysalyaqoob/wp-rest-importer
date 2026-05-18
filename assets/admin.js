/* global wprestiData */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Tab switching
    // -------------------------------------------------------------------------
    var tabLinks = document.querySelectorAll('#pp-tabs .nav-tab');
    tabLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var target = this.getAttribute('data-tab');
            tabLinks.forEach(function (l) { l.classList.remove('nav-tab-active'); });
            this.classList.add('nav-tab-active');
            document.querySelectorAll('.pp-tab-panel').forEach(function (panel) {
                panel.style.display = panel.id === 'pp-tab-' + target ? 'block' : 'none';
            });
        });
    });

    // -------------------------------------------------------------------------
    // Credentials notice dismiss
    // -------------------------------------------------------------------------
    var credsNotice  = document.getElementById('pp-creds-notice');
    var dismissBtn   = document.getElementById('pp-creds-notice-dismiss');
    if (dismissBtn && credsNotice) {
        dismissBtn.addEventListener('click', function () {
            credsNotice.style.display = 'none';
        });
    }

    // -------------------------------------------------------------------------
    // Import tab
    // -------------------------------------------------------------------------
    var form        = document.getElementById('wpresti-form');
    var submitBtn   = document.getElementById('pp-submit');
    var statusArea  = document.getElementById('pp-status-area');
    var progressBar = document.getElementById('pp-progress-bar');
    var progressTxt = document.getElementById('pp-progress-text');
    var logBody     = document.getElementById('pp-log-body');
    var notice      = document.getElementById('pp-notice');
    var noticeTxt   = document.getElementById('pp-notice-text');

    var total = 0;
    var done  = 0;

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            startImport();
        });
    }

    function startImport() {
        var siteUrl          = document.getElementById('pp-site-url').value.trim();
        var importType       = document.getElementById('pp-import-type').value;
        var assignAuthor     = document.getElementById('pp-assign-author').value;
        var sourceUsername   = (document.getElementById('pp-source-username') || {}).value || '';
        var sourceAppPass    = (document.getElementById('pp-source-app-password') || {}).value || '';

        if (!siteUrl) {
            showNotice('error', 'Please enter a source site URL.');
            return;
        }

        setSubmitState(true);
        resetUI();
        statusArea.style.display = 'block';
        showNotice('info', 'Fetching items from remote site…');

        var body = new FormData();
        body.append('action',              'wpresti_start');
        body.append('nonce',               wprestiData.nonce);
        body.append('site_url',            siteUrl);
        body.append('import_type',         importType);
        body.append('assign_author_id',    assignAuthor);
        body.append('source_username',     sourceUsername.trim());
        body.append('source_app_password', sourceAppPass);

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    showNotice('error', resp.data.message || 'Failed to start import.');
                    setSubmitState(false);
                    return;
                }
                total = resp.data.total;
                done  = 0;
                showNotice('info', resp.data.message);
                updateProgress();
                runBatch();
            })
            .catch(function (err) {
                showNotice('error', 'Network error: ' + err.message);
                setSubmitState(false);
            });
    }

    function runBatch() {
        var body = new FormData();
        body.append('action', 'wpresti_batch');
        body.append('nonce',  wprestiData.nonce);

        fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    showNotice('error', resp.data.message || 'Batch error.');
                    setSubmitState(false);
                    return;
                }

                done  = resp.data.done;
                total = resp.data.total;
                updateProgress();
                appendLogRows(resp.data.log || []);

                if (resp.data.complete) {
                    onComplete();
                } else {
                    runBatch();
                }
            })
            .catch(function (err) {
                showNotice('error', 'Network error during batch: ' + err.message);
                setSubmitState(false);
            });
    }

    function updateProgress() {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        progressBar.style.width = pct + '%';
        progressTxt.textContent = done + ' of ' + total + ' imported';
    }

    function appendLogRows(rows) {
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            var statusClass = 'pp-status-' + (row.action || 'error').toLowerCase();
            var formatBadge = '';
            if (row.format === 'gutenberg-raw') {
                formatBadge = '<span class="pp-format-badge pp-format-gutenberg">Gutenberg</span>';
            } else if (row.format === 'gutenberg-rendered') {
                formatBadge = '<span class="pp-format-badge pp-format-gutenberg-rendered">Gutenberg</span>';
            } else if (row.format === 'classic') {
                formatBadge = '<span class="pp-format-badge pp-format-classic">Classic</span>';
            }
            tr.innerHTML =
                '<td>' + escHtml(row.title  || '') + '</td>' +
                '<td>' + escHtml(row.type   || '') + '</td>' +
                '<td>' + formatBadge + '</td>' +
                '<td class="' + statusClass + '">' + escHtml(row.status || '') + '</td>' +
                '<td>' + escHtml(row.time   || '') + '</td>';
            logBody.insertBefore(tr, logBody.firstChild);
        });
    }

    function onComplete() {
        progressBar.classList.add('pp-complete');
        progressBar.style.width = '100%';
        progressTxt.textContent = done + ' of ' + total + ' imported — Complete!';
        showNotice('success', 'Import complete! ' + done + ' items processed.');
        setSubmitState(false);
    }

    function resetUI() {
        logBody.innerHTML = '';
        progressBar.style.width = '0%';
        progressBar.classList.remove('pp-complete');
        progressTxt.textContent = '0 of 0 imported';
        done  = 0;
        total = 0;
    }

    function setSubmitState(disabled) {
        submitBtn.disabled    = disabled;
        submitBtn.textContent = disabled ? 'Importing…' : 'Start Import';
    }

    function showNotice(type, message) {
        notice.className      = 'notice notice-' + type;
        noticeTxt.textContent = message;
        notice.style.display  = 'block';
    }

    // -------------------------------------------------------------------------
    // Reassign Authors tab
    // -------------------------------------------------------------------------
    var scanBtn           = document.getElementById('pp-scan-btn');
    var reassignResults   = document.getElementById('pp-reassign-results');
    var reassignBody      = document.getElementById('pp-reassign-body');
    var runReassignBtn    = document.getElementById('pp-run-reassign-btn');
    var reassignNotice    = document.getElementById('pp-reassign-notice');
    var reassignNoticeTxt = document.getElementById('pp-reassign-notice-text');

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            scanBtn.disabled    = true;
            scanBtn.textContent = 'Scanning…';
            showReassignNotice('info', 'Scanning imported posts…');
            reassignResults.style.display = 'none';

            var body = new FormData();
            body.append('action', 'wpresti_reassign_scan');
            body.append('nonce',  wprestiData.nonce);

            fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    scanBtn.disabled    = false;
                    scanBtn.textContent = 'Scan Imported Posts';

                    if (!resp.success) {
                        showReassignNotice('error', resp.data.message || 'Scan failed.');
                        return;
                    }

                    var groups = resp.data.groups || [];
                    if (!groups.length) {
                        showReassignNotice('info', 'No imported posts with original author data found.');
                        return;
                    }

                    renderReassignTable(groups);
                    reassignResults.style.display = 'block';
                    reassignNotice.style.display  = 'none';
                })
                .catch(function (err) {
                    scanBtn.disabled    = false;
                    scanBtn.textContent = 'Scan Imported Posts';
                    showReassignNotice('error', 'Network error: ' + err.message);
                });
        });
    }

    if (runReassignBtn) {
        runReassignBtn.addEventListener('click', function () {
            runReassignBtn.disabled    = true;
            runReassignBtn.textContent = 'Reassigning…';

            var body = new FormData();
            body.append('action', 'wpresti_reassign_run');
            body.append('nonce',  wprestiData.nonce);

            fetch(wprestiData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    runReassignBtn.disabled    = false;
                    runReassignBtn.textContent = 'Run Reassignment';

                    if (!resp.success) {
                        showReassignNotice('error', resp.data.message || 'Reassignment failed.');
                        return;
                    }

                    var msg = resp.data.reassigned + ' post' + (resp.data.reassigned === 1 ? '' : 's') + ' reassigned.';
                    if (resp.data.unmatched > 0) {
                        msg += ' ' + resp.data.unmatched + ' post' + (resp.data.unmatched === 1 ? '' : 's') +
                               ' still unmatched (create users with matching login to resolve).';
                        showReassignNotice('warning', msg);
                    } else {
                        showReassignNotice('success', msg);
                    }

                    // Re-scan to refresh the table.
                    scanBtn.click();
                })
                .catch(function (err) {
                    runReassignBtn.disabled    = false;
                    runReassignBtn.textContent = 'Run Reassignment';
                    showReassignNotice('error', 'Network error: ' + err.message);
                });
        });
    }

    function renderReassignTable(groups) {
        reassignBody.innerHTML = '';
        groups.forEach(function (g) {
            var tr = document.createElement('tr');
            var matchedCell = g.matched
                ? escHtml(g.wp_user)
                : '<em style="color:#999">No user found</em>';
            var statusCell = g.matched
                ? '<span class="pp-status-created">✓ Ready</span>'
                : '<span class="pp-status-error">✗ No user found</span>';

            tr.innerHTML =
                '<td>' + escHtml(g.login || '(empty)') + '</td>' +
                '<td>' + escHtml(g.name  || '') + '</td>' +
                '<td>' + escHtml(String(g.post_count)) + '</td>' +
                '<td>' + matchedCell + '</td>' +
                '<td>' + statusCell + '</td>';
            reassignBody.appendChild(tr);
        });
    }

    function showReassignNotice(type, message) {
        reassignNotice.className      = 'notice notice-' + type;
        reassignNoticeTxt.textContent = message;
        reassignNotice.style.display  = 'block';
    }

    // -------------------------------------------------------------------------
    // Shared utility
    // -------------------------------------------------------------------------
    function escHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#039;');
    }
}());
