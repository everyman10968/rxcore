/**
 * RxCore Sync Agent — Production UI Controller
 */
(function () {
    'use strict';

    const state = {
        config: {},
        drugs: [],
        healthCenters: [],
        previewRows: [],
        history: [],
    };

    const $ = (id) => document.getElementById(id);

    function esc(val) {
        if (val === null || val === undefined) return '';
        return String(val)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtNum(n) {
        const num = Number(n || 0);
        return Number.isFinite(num) ? num.toLocaleString('th-TH') : '0';
    }

    let toastTimer = null;
    function showToast(msg, ok = true) {
        const el = $('rxToast');
        if (!el) return;
        el.className = 'rx-toast show ' + (ok ? 'ok' : 'err');
        el.innerHTML = `<i class="bi ${ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i><span>${esc(msg)}</span>`;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove('show'), 4200);
    }

    function showBanner(id, msg, ok = true) {
        const el = $(id);
        if (!el) return;
        if (!msg) {
            el.className = 'diag-banner';
            el.textContent = '';
            return;
        }
        el.className = 'diag-banner show ' + (ok ? 'ok' : 'err');
        el.innerHTML = `<i class="bi ${ok ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'} me-1"></i> ${esc(msg)}`;
    }

    async function apiCall(action, payload = {}) {
        const res = await fetch('agent_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload)),
        });
        const data = await res.json();
        return data;
    }

    // -------------------------------------------------------------------------
    // Theme Switcher (Unified with RxCore Main Web App via rxcore_theme)
    // -------------------------------------------------------------------------
    function syncThemeUI() {
        const cur = document.documentElement.getAttribute('data-rx-theme') || 'dim';
        const ico = $('icoTheme');
        const lbl = $('lblTheme');
        if (cur === 'soft') {
            if (ico) ico.className = 'bi bi-sun';
            if (lbl) lbl.textContent = 'โหมดสว่างนุ่มตา';
        } else {
            if (ico) ico.className = 'bi bi-moon-stars';
            if (lbl) lbl.textContent = 'โหมดมืดถนอมสายตา';
        }
    }

    function toggleTheme() {
        const cur = document.documentElement.getAttribute('data-rx-theme') || 'dim';
        const next = cur === 'dim' ? 'soft' : 'dim';
        document.documentElement.setAttribute('data-rx-theme', next);
        try { localStorage.setItem('rxcore_theme', next); } catch (e) {}
        syncThemeUI();
    }

    // -------------------------------------------------------------------------
    // Tabs Navigation
    // -------------------------------------------------------------------------
    function switchTab(tabId) {
        document.querySelectorAll('.agent-tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        document.querySelectorAll('.tab-pane').forEach((pane) => {
            pane.classList.toggle('active', pane.id === tabId);
        });
        if (tabId === 'tabHistory') {
            loadAgentLogs();
        }
    }

    // -------------------------------------------------------------------------
    // Date Range Presets
    // -------------------------------------------------------------------------
    function formatDateLocal(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function applyDatePreset(preset) {
        const now = new Date();
        const end = formatDateLocal(now);
        let start = end;
        let autoReconcile = false;

        if (preset === 'yesterday') {
            const y = new Date(now);
            y.setDate(y.getDate() - 1);
            start = formatDateLocal(y);
            $('inpEndDate').value = start;
            $('inpStartDate').value = start;
        } else if (preset === '7d') {
            const d7 = new Date(now);
            d7.setDate(d7.getDate() - 6);
            start = formatDateLocal(d7);
            $('inpStartDate').value = start;
            $('inpEndDate').value = end;
        } else if (preset === '30d') {
            const d30 = new Date(now);
            d30.setDate(d30.getDate() - 29);
            start = formatDateLocal(d30);
            $('inpStartDate').value = start;
            $('inpEndDate').value = end;
            autoReconcile = true;
        } else {
            $('inpStartDate').value = end;
            $('inpEndDate').value = end;
        }

        $('chkReconcile').checked = autoReconcile;
        document.querySelectorAll('.preset-chip').forEach((c) => {
            c.classList.toggle('active', c.dataset.preset === preset);
        });
    }

    // -------------------------------------------------------------------------
    // Populate UI from Bootstrap Data
    // -------------------------------------------------------------------------
    function renderTopStatus() {
        const cfg = state.config || {};
        const hasHosxp = Boolean(cfg.hcode && cfg.hosxp_host && cfg.hosxp_database && cfg.hosxp_username);
        const hasCloud = Boolean(cfg.supabase_url && cfg.has_effective_anon && cfg.has_saved_sync_key);

        const chipHosxp = $('chipHosxp');
        const txtHosxp = $('txtChipHosxp');
        if (hasHosxp) {
            chipHosxp.className = 'status-chip ok';
            txtHosxp.textContent = `HOSxP: ${cfg.hosxp_host}/${cfg.hosxp_database}`;
        } else {
            chipHosxp.className = 'status-chip warn';
            txtHosxp.textContent = 'HOSxP: ยังไม่ตั้งค่า';
        }

        const chipCloud = $('chipCloud');
        const txtCloud = $('txtChipCloud');
        if (hasCloud) {
            chipCloud.className = 'status-chip ok';
            txtCloud.textContent = `Cloud: พร้อมซิงค์ (${cfg.hcode})`;
        } else if (cfg.supabase_url) {
            chipCloud.className = 'status-chip warn';
            txtCloud.textContent = 'Cloud: รอใส่ Sync Key';
        } else {
            chipCloud.className = 'status-chip warn';
            txtCloud.textContent = 'Cloud: ยังไม่ตั้งค่า';
        }

        $('lblCurrentHcode').textContent = cfg.hcode
            ? `HCODE: ${cfg.hcode}${cfg.facility_name ? ' • ' + cfg.facility_name : ''}`
            : 'HCODE: ยังไม่ระบุ';

        if (cfg.facility_name || cfg.hcode) {
            $('lblFacilitySubtitle').textContent = `${cfg.facility_name || 'หน่วยบริการ'} (HCODE ${cfg.hcode || '-'}) • เชื่อมต่อแบบ Read-only`;
        }

        $('boxSetupNotice').style.display = (hasHosxp && hasCloud) ? 'none' : 'flex';
    }

    function populateSettingsForm() {
        const cfg = state.config || {};
        $('cfgHcode').value = cfg.hcode || '';
        $('cfgFacilityName').value = cfg.facility_name || '';
        $('cfgHost').value = cfg.hosxp_host || '127.0.0.1';
        $('cfgPort').value = cfg.hosxp_port || 3306;
        $('cfgDatabase').value = cfg.hosxp_database || 'hosxp_pcu';
        $('cfgUsername').value = cfg.hosxp_username || 'rxcore_ro';
        $('cfgPassword').value = '';

        $('cfgSupabaseUrl').value = cfg.supabase_url || '';
        $('cfgSupabaseAnonKey').value = '';
        $('cfgSyncApiKey').value = '';
        $('cfgCentralWebUrl').value = cfg.central_web_url || '';

        $('cfgMophEnabled').checked = Boolean(cfg.moph_notify_enabled);
        $('cfgMophManual').checked = Boolean(cfg.moph_notify_on_manual);
        $('cfgMophClientKey').value = '';
        $('cfgMophSecretKey').value = '';

        const mophBadge = $('badgeMophStatus');
        if (cfg.moph_notify_enabled && cfg.has_saved_moph_client && cfg.has_saved_moph_secret) {
            mophBadge.className = 'badge-soft ok';
            mophBadge.textContent = 'MOPH Notify: เปิดใช้งาน';
        } else if (cfg.has_saved_moph_client && cfg.has_saved_moph_secret) {
            mophBadge.className = 'badge-soft warn';
            mophBadge.textContent = 'MOPH Notify: บันทึกคีย์แล้ว (ปิดอยู่)';
        } else {
            mophBadge.className = 'badge-soft warn';
            mophBadge.textContent = 'MOPH Notify: ยังไม่ตั้งค่า';
        }

        $('lblMophClientMask').textContent = cfg.has_saved_moph_client
            ? `(บันทึกแล้ว: ${cfg.moph_client_key_masked})`
            : '';
        $('lblMophSecretMask').textContent = cfg.has_saved_moph_secret
            ? `(บันทึกแล้ว: ${cfg.moph_secret_key_masked})`
            : '';

        const passBadge = $('badgeSavedPass');
        if (cfg.has_saved_password) {
            passBadge.className = 'badge-soft ok';
            passBadge.textContent = 'เข้ารหัสบันทึกแล้ว';
        } else {
            passBadge.className = 'badge-soft warn';
            passBadge.textContent = 'ยังไม่บันทึกรหัสผ่าน';
        }

        const keyBadge = $('badgeSavedSyncKey');
        if (cfg.has_saved_sync_key) {
            keyBadge.className = 'badge-soft ok mono';
            keyBadge.textContent = cfg.sync_api_key_masked || 'บันทึกคีย์แล้ว';
        } else {
            keyBadge.className = 'badge-soft warn';
            keyBadge.textContent = 'ยังไม่บันทึก Sync Key';
        }

        $('lblAnonHint').textContent = cfg.has_saved_anon_key
            ? '(บันทึกเข้ารหัสแล้ว)'
            : (cfg.has_parent_env ? '(ใช้ค่าจาก .env แม่ข่ายอัตโนมัติ)' : '');

        const activeSlots = Array.isArray(cfg.sync_slots) ? cfg.sync_slots : ['09:00', '14:00', '17:00'];
        document.querySelectorAll('.chk-slot').forEach((chk) => {
            chk.checked = activeSlots.includes(chk.value);
        });

        // Populate Registered Health Centers Quick Selector (if available on central server)
        const wrapHc = $('wrapQuickSelectHc');
        const selHc = $('selHealthCenter');
        if (Array.isArray(state.healthCenters) && state.healthCenters.length > 0) {
            wrapHc.style.display = 'block';
            selHc.innerHTML = '<option value="">-- เลือกหน่วยบริการเพื่อเติม HCODE อัตโนมัติ --</option>' +
                state.healthCenters.map((hc) =>
                    `<option value="${esc(hc.hcode)}" data-name="${esc(hc.name)}">${esc(hc.hcode)} — ${esc(hc.name)}</option>`
                ).join('');
            if (cfg.hcode) {
                selHc.value = cfg.hcode;
            }
        } else {
            wrapHc.style.display = 'none';
        }
    }

    // -------------------------------------------------------------------------
    // Render Drug Registry Table
    // -------------------------------------------------------------------------
    function populateDrugGroups(groupsObj) {
        const sel = $('selDrugGroup');
        const current = sel.value;
        const keys = Object.keys(groupsObj || {});
        sel.innerHTML = '<option value="">ทุกหมวดยา (' + state.drugs.length + ')</option>' +
            keys.map((g) => `<option value="${esc(g)}">${esc(g)} (${groupsObj[g]})</option>`).join('');
        sel.value = current;
    }

    function renderDrugsTable() {
        const q = ($('inpSearchDrug').value || '').trim().toLowerCase();
        const grp = $('selDrugGroup').value || '';

        const filtered = state.drugs.filter((d) => {
            if (grp && d.drug_type_name !== grp) return false;
            if (!q) return true;
            return (
                String(d.icode || '').toLowerCase().includes(q) ||
                String(d.tpu_code_list || '').toLowerCase().includes(q) ||
                String(d.drugname || '').toLowerCase().includes(q) ||
                String(d.drug_type_name || '').toLowerCase().includes(q)
            );
        });

        $('badgeDrugCount').textContent = fmtNum(state.drugs.length);
        $('lblRegistrySummary').textContent = `${fmtNum(filtered.length)} / ${fmtNum(state.drugs.length)} รายการ`;

        const tbody = $('tbodyDrugs');
        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty-state">ไม่พบรายการยาตามเงื่อนไขที่ค้นหา</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map((d) => `
            <tr>
                <td class="mono">${esc(d.icode ?? '-')}</td>
                <td><strong>${esc(d.drugname)}</strong></td>
                <td class="mono"><span class="badge-soft mono">${esc(d.tpu_code_list)}</span></td>
                <td>${esc(d.drug_type_name || '-')}</td>
                <td>${esc(d.units || '-')}</td>
            </tr>
        `).join('');
    }

    // -------------------------------------------------------------------------
    // Render Preview / Sync Result Table
    // -------------------------------------------------------------------------
    function renderPreviewTable() {
        const q = ($('inpFilterPreview').value || '').trim().toLowerCase();
        const rows = state.previewRows.filter((r) => {
            if (!q) return true;
            return (
                String(r.drugname || '').toLowerCase().includes(q) ||
                String(r.tpu_code_list || '').toLowerCase().includes(q) ||
                String(r.vn || '').toLowerCase().includes(q) ||
                String(r.hn || '').toLowerCase().includes(q) ||
                String(r.hos_guid || '').toLowerCase().includes(q)
            );
        });

        $('lblPreviewCount').textContent = `${fmtNum(rows.length)} รายการตัวอย่าง`;
        const tbody = $('tbodyPreview');
        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">ไม่มีรายการแสดงผล</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map((r) => `
            <tr>
                <td class="mono">${esc(r.vstdate)}</td>
                <td class="mono">${esc(r.vn)}</td>
                <td class="mono"><strong>${esc(r.hn || '-')}</strong></td>
                <td class="mono"><span class="badge-soft mono">${esc(r.tpu_code_list)}</span></td>
                <td>${esc(r.drugname)}</td>
                <td class="text-end mono"><strong>${fmtNum(r.qty)}</strong></td>
                <td>${esc(r.units || '-')}</td>
                <td class="mono" style="font-size: 0.76rem; color: var(--rx-text-muted);">${esc(r.hos_guid)}</td>
            </tr>
        `).join('');
    }

    // -------------------------------------------------------------------------
    // Render Sync History & Agent Logs
    // -------------------------------------------------------------------------
    function renderHistoryTable() {
        const tbody = $('tbodyHistory');
        if (!Array.isArray(state.history) || state.history.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">ยังไม่มีประวัติการซิงค์ข้อมูลในเครื่องนี้</td></tr>';
            return;
        }

        tbody.innerHTML = state.history.map((h) => {
            const st = h.status === 'success' ? 'ok' : (h.status === 'partial' ? 'warn' : 'err');
            const stLabel = h.status === 'success' ? 'สำเร็จ' : (h.status === 'partial' ? 'บางส่วน' : 'ล้มเหลว');
            return `
                <tr>
                    <td class="mono">${esc(h.timestamp)}</td>
                    <td class="mono">${esc(h.hcode)}</td>
                    <td class="mono">${esc(h.range)}</td>
                    <td><span class="badge-soft mono">${esc(h.mode)}</span></td>
                    <td class="text-end mono">${fmtNum(h.read_count)}</td>
                    <td class="text-end mono"><strong>${fmtNum(h.synced_count)}</strong></td>
                    <td><span class="badge-soft ${st}">${stLabel}</span></td>
                    <td>${esc(h.message || '')}</td>
                </tr>
            `;
        }).join('');
    }

    async function loadAgentLogs() {
        try {
            const res = await apiCall('get_logs');
            const box = $('boxAgentLogs');
            if (res.success && Array.isArray(res.logs) && res.logs.length > 0) {
                box.textContent = res.logs.join('\n');
            } else {
                box.textContent = 'ยังไม่มีบันทึกการทำงานในวันนี้';
            }
        } catch (e) {
            $('boxAgentLogs').textContent = 'ไม่สามารถอ่านบันทึกการทำงานได้';
        }
    }

    // -------------------------------------------------------------------------
    // Core Actions: Bootstrap, Preview, Sync Now, Diagnostics, Save
    // -------------------------------------------------------------------------
    async function bootstrap() {
        syncThemeUI();
        try {
            const res = await apiCall('prod_bootstrap');
            if (res.success && res.data) {
                state.config = res.data.config || {};
                state.drugs = res.data.drugs || [];
                state.healthCenters = res.data.health_centers || [];
                state.history = res.data.history || [];

                renderTopStatus();
                populateSettingsForm();
                populateDrugGroups(res.data.drug_groups || {});
                renderDrugsTable();
                renderHistoryTable();
            }
        } catch (err) {
            showToast('โหลดข้อมูลเริ่มต้นไม่สำเร็จ', false);
        }
    }

    async function handlePreview() {
        const btn = $('btnPreview');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังดึงข้อมูล...';
        showBanner('syncBanner', '');

        try {
            const res = await apiCall('prod_preview', {
                start_date: $('inpStartDate').value,
                end_date: $('inpEndDate').value,
            });
            if (!res.success) {
                showBanner('syncBanner', res.error || 'ดึงข้อมูลไม่สำเร็จ', false);
                showToast(res.error || 'ดึงข้อมูลไม่สำเร็จ', false);
                return;
            }

            $('kpiRead').textContent = fmtNum(res.total_read);
            $('kpiValid').textContent = fmtNum(res.total_passed);
            $('kpiSynced').textContent = '—';
            $('kpiSyncedSub').textContent = 'โหมดตรวจสอบก่อนส่ง (Preview)';
            $('kpiSkipped').textContent = fmtNum(res.duplicates_pruned);
            $('kpiSkippedSub').textContent = `ใช้เวลา ${res.execution_time_ms} ms`;

            state.previewRows = res.display_rows || [];
            renderPreviewTable();

            const msg = `ตรวจสอบสำเร็จ: พบรายการพร้อมซิงค์ ${fmtNum(res.total_passed)} รายการ (อ่านจาก HOSxP ${fmtNum(res.total_read)} รายการ)`;
            showBanner('syncBanner', msg, true);
            showToast('ดึงข้อมูลตรวจสอบสำเร็จ', true);
        } catch (e) {
            showBanner('syncBanner', 'เกิดข้อผิดพลาดในการเชื่อมต่อ Agent API', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleSyncNow() {
        const btn = $('btnSyncNow');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> กำลังซิงค์ขึ้น Cloud...';
        showBanner('syncBanner', '');

        try {
            const res = await apiCall('prod_sync_now', {
                start_date: $('inpStartDate').value,
                end_date: $('inpEndDate').value,
                reconcile: $('chkReconcile').checked,
            });

            if (Array.isArray(res.history)) {
                state.history = res.history;
                renderHistoryTable();
            }

            if (!res.success) {
                showBanner('syncBanner', res.error || 'ซิงค์ข้อมูลไม่สำเร็จ', false);
                showToast(res.error || 'ซิงค์ข้อมูลไม่สำเร็จ', false);
                return;
            }

            $('kpiRead').textContent = fmtNum(res.total_read);
            $('kpiValid').textContent = fmtNum(res.total_passed);
            $('kpiSynced').textContent = fmtNum(res.inserted_or_updated);
            $('kpiSyncedSub').textContent = `ส่งสำเร็จ (${fmtNum(res.duration_ms)} ms)`;
            $('kpiSkipped').textContent = fmtNum((res.duplicates_pruned || 0) + (res.deleted || 0));
            $('kpiSkippedSub').textContent = res.deleted > 0
                ? `ตัดซ้ำ ${fmtNum(res.duplicates_pruned || 0)} • ยกเลิก ${fmtNum(res.deleted)}`
                : 'ป้องกันรายการซ้ำอัตโนมัติ';

            state.previewRows = res.display_rows || [];
            renderPreviewTable();

            showBanner('syncBanner', res.message || 'ซิงค์ข้อมูลขึ้นระบบกลางสำเร็จ', true);
            showToast(res.message || 'ซิงค์ข้อมูลสำเร็จ', true);
        } catch (e) {
            showBanner('syncBanner', 'เกิดข้อผิดพลาดระหว่างส่งข้อมูล', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleSaveSettings(ev) {
        ev.preventDefault();
        const btn = $('btnSaveSettings');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';

        const slots = [];
        document.querySelectorAll('.chk-slot:checked').forEach((c) => slots.push(c.value));

        try {
            const res = await apiCall('prod_save_settings', {
                hcode: $('cfgHcode').value.trim(),
                facility_name: $('cfgFacilityName').value.trim(),
                hosxp_host: $('cfgHost').value.trim(),
                hosxp_port: Number($('cfgPort').value || 3306),
                hosxp_database: $('cfgDatabase').value.trim(),
                hosxp_username: $('cfgUsername').value.trim(),
                hosxp_password: $('cfgPassword').value,
                supabase_url: $('cfgSupabaseUrl').value.trim(),
                supabase_anon_key: $('cfgSupabaseAnonKey').value.trim(),
                sync_api_key: $('cfgSyncApiKey').value.trim(),
                central_web_url: $('cfgCentralWebUrl').value.trim(),
                sync_slots: slots,
                moph_notify_enabled: $('cfgMophEnabled').checked,
                moph_notify_on_manual: $('cfgMophManual').checked,
                moph_client_key: $('cfgMophClientKey').value.trim(),
                moph_secret_key: $('cfgMophSecretKey').value.trim(),
            });

            if (!res.success) {
                showToast(res.error || 'บันทึกไม่สำเร็จ', false);
                return;
            }

            if (res.config) {
                state.config = res.config;
                renderTopStatus();
                populateSettingsForm();
            }
            showToast(res.message || 'บันทึกการตั้งค่าเรียบร้อยแล้ว', true);
        } catch (e) {
            showToast('เกิดข้อผิดพลาดในการบันทึกการตั้งค่า', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleTestHosxp() {
        const btn = $('btnTestHosxp');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังตรวจสอบ...';
        showBanner('hosxpDiagBanner', '');

        try {
            const res = await apiCall('prod_test_hosxp', {
                hcode: $('cfgHcode').value.trim(),
                hosxp_host: $('cfgHost').value.trim(),
                hosxp_port: Number($('cfgPort').value || 3306),
                hosxp_database: $('cfgDatabase').value.trim(),
                hosxp_username: $('cfgUsername').value.trim(),
                hosxp_password: $('cfgPassword').value,
            });

            if (res.success) {
                showBanner('hosxpDiagBanner', res.message, true);
                $('chipHosxp').className = 'status-chip ok';
                $('txtChipHosxp').textContent = `HOSxP: Read-only ผ่าน (${res.latency_ms} ms)`;
            } else {
                showBanner('hosxpDiagBanner', res.error || 'ตรวจสอบไม่ผ่าน', false);
                $('chipHosxp').className = 'status-chip err';
                $('txtChipHosxp').textContent = 'HOSxP: เชื่อมต่อไม่ผ่าน';
            }
        } catch (e) {
            showBanner('hosxpDiagBanner', 'ไม่สามารถเรียกใช้ตัวตรวจสอบได้', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleTestCloud() {
        const btn = $('btnTestCloud');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังตรวจสอบ...';
        showBanner('cloudDiagBanner', '');

        try {
            const res = await apiCall('prod_test_cloud', {
                hcode: $('cfgHcode').value.trim(),
                supabase_url: $('cfgSupabaseUrl').value.trim(),
                supabase_anon_key: $('cfgSupabaseAnonKey').value.trim(),
                sync_api_key: $('cfgSyncApiKey').value.trim(),
            });

            if (res.success) {
                showBanner('cloudDiagBanner', res.message, true);
                $('chipCloud').className = res.key_verified ? 'status-chip ok' : 'status-chip warn';
                $('txtChipCloud').textContent = res.key_verified ? 'Cloud: ยืนยันคีย์ผ่านแล้ว' : 'Cloud: เชื่อมต่อสำเร็จ';
            } else {
                showBanner('cloudDiagBanner', res.error || 'เชื่อมต่อไม่ผ่าน', false);
                $('chipCloud').className = 'status-chip err';
                $('txtChipCloud').textContent = 'Cloud: ตรวจสอบไม่ผ่าน';
            }
        } catch (e) {
            showBanner('cloudDiagBanner', 'เกิดข้อผิดพลาดในการทดสอบระบบกลาง', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleTestMoph() {
        const btn = $('btnTestMoph');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังส่งทดสอบ...';
        showBanner('mophDiagBanner', '');

        try {
            const res = await apiCall('prod_test_moph_notify', {
                hcode: $('cfgHcode').value.trim(),
                facility_name: $('cfgFacilityName').value.trim(),
                moph_client_key: $('cfgMophClientKey').value.trim(),
                moph_secret_key: $('cfgMophSecretKey').value.trim(),
            });
            showBanner('mophDiagBanner', res.message || res.error || 'ทดสอบไม่สำเร็จ', Boolean(res.success));
        } catch (e) {
            showBanner('mophDiagBanner', 'เกิดข้อผิดพลาดในการเชื่อมต่อ MOPH Notify', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function handleRefreshDrugs() {
        const btn = $('btnRefreshDrugs');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังอัปเดต...';
        try {
            const res = await apiCall('prod_refresh_drugs');
            if (res.success && Array.isArray(res.drugs)) {
                state.drugs = res.drugs;
                const groups = {};
                state.drugs.forEach((d) => {
                    if (d.drug_type_name) groups[d.drug_type_name] = (groups[d.drug_type_name] || 0) + 1;
                });
                populateDrugGroups(groups);
                renderDrugsTable();
                showToast(res.message || 'อัปเดตทะเบียนยาเรียบร้อย', true);
            } else {
                showToast(res.message || 'อัปเดตทะเบียนยาไม่สำเร็จ', false);
            }
        } catch (e) {
            showToast('เกิดข้อผิดพลาดในการอัปเดตทะเบียนยา', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    // -------------------------------------------------------------------------
    // Bind Event Listeners
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        $('btnToggleTheme').addEventListener('click', toggleTheme);
        $('btnGoSetup').addEventListener('click', () => switchTab('tabSettings'));

        document.querySelectorAll('.agent-tab-btn').forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });

        document.querySelectorAll('.preset-chip').forEach((chip) => {
            chip.addEventListener('click', () => applyDatePreset(chip.dataset.preset));
        });

        $('inpStartDate').addEventListener('change', () => {
            document.querySelectorAll('.preset-chip').forEach((c) => c.classList.remove('active'));
        });
        $('inpEndDate').addEventListener('change', () => {
            document.querySelectorAll('.preset-chip').forEach((c) => c.classList.remove('active'));
        });

        $('btnPreview').addEventListener('click', handlePreview);
        $('btnSyncNow').addEventListener('click', handleSyncNow);
        $('inpFilterPreview').addEventListener('input', renderPreviewTable);

        $('selHealthCenter').addEventListener('change', (ev) => {
            const opt = ev.target.selectedOptions[0];
            if (opt && opt.value) {
                $('cfgHcode').value = opt.value;
                $('cfgFacilityName').value = opt.getAttribute('data-name') || '';
            }
        });

        $('formSettings').addEventListener('submit', handleSaveSettings);
        $('btnTestHosxp').addEventListener('click', handleTestHosxp);
        $('btnTestCloud').addEventListener('click', handleTestCloud);
        $('btnTestMoph').addEventListener('click', handleTestMoph);

        $('btnClearConfig').addEventListener('click', async () => {
            if (!confirm('ยืนยันการล้างค่าตั้งค่าทั้งหมดของ Sync Agent ในเครื่องนี้?')) return;
            const res = await apiCall('clear_config');
            if (res.success) {
                showToast('ล้างค่าตั้งค่าเรียบร้อยแล้ว', true);
                await bootstrap();
            }
        });

        $('inpSearchDrug').addEventListener('input', renderDrugsTable);
        $('selDrugGroup').addEventListener('change', renderDrugsTable);
        $('btnRefreshDrugs').addEventListener('click', handleRefreshDrugs);

        $('btnClearHistory').addEventListener('click', async () => {
            if (!confirm('ต้องการล้างประวัติการซิงค์ในเครื่องนี้ใช่หรือไม่?')) return;
            await apiCall('prod_clear_history');
            state.history = [];
            renderHistoryTable();
            showToast('ล้างประวัติการซิงค์แล้ว', true);
        });

        $('btnRefreshLogs').addEventListener('click', loadAgentLogs);
        $('btnClearLogs').addEventListener('click', async () => {
            await apiCall('prod_clear_logs');
            await loadAgentLogs();
            showToast('ล้างบันทึกการทำงานเรียบร้อย', true);
        });

        bootstrap();
    });
})();
