<?php
/**
 * RxCore Sync Agent — Production Web Console
 * เชื่อมต่อ HOSxP / HOSxP_PCU แบบ Read-only และซิงค์ข้อมูลการจ่ายยาขึ้นระบบกลาง (Supabase Cloud)
 */
header('X-Content-Type-Options: nosniff');
$hasCentralWeb = file_exists(dirname(__DIR__) . '/index.php');
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="th" data-rx-theme="dim">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RxCore Sync Agent — ระบบซิงค์ข้อมูลการจ่ายยาผู้ป่วยนอก (OPD)</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=4.0">
    <link rel="shortcut icon" href="assets/favicon.svg?v=4.0">
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('rxcore_theme');
                if (saved === 'soft' || saved === 'dim') {
                    document.documentElement.setAttribute('data-rx-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/agent.css?v=4.0">
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="agent-topbar">
        <div class="topbar-inner">
            <div class="brand-group">
                <div class="brand-icon"><i class="bi bi-arrow-repeat"></i></div>
                <div>
                    <h1 class="brand-title">RxCore Sync Agent</h1>
                    <p class="brand-subtitle" id="lblFacilitySubtitle">ตัวแทนซิงค์ข้อมูลการใช้ยาผู้ป่วยนอก (OPD) แบบ Read-only (ปราศจากข้อมูลส่วนบุคคลผู้ป่วย)</p>
                </div>
            </div>

            <div class="topbar-right">
                <span class="status-chip" id="chipHosxp">
                    <span class="status-dot"></span>
                    <span id="txtChipHosxp">HOSxP (OPD): รอตรวจสอบ</span>
                </span>
                <span class="status-chip" id="chipCloud">
                    <span class="status-dot"></span>
                    <span id="txtChipCloud">Cloud: รอตรวจสอบ</span>
                </span>

                <button type="button" class="rx-btn rx-btn-ghost rx-btn-sm" id="btnToggleTheme" title="สลับโหมดสีสบายตา">
                    <i class="bi bi-moon-stars" id="icoTheme"></i>
                    <span id="lblTheme">โหมดมืดถนอมสายตา</span>
                </button>

                <?php if ($hasCentralWeb): ?>
                <a href="../index.php?page=sync_status" class="rx-btn rx-btn-outline rx-btn-sm">
                    <i class="bi bi-box-arrow-up-right"></i> เว็บระบบกลาง
                </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="agent-shell">

        <!-- 4 Clean Workflow Tabs -->
        <nav class="agent-tabs" role="tablist">
            <button type="button" class="agent-tab-btn active" data-tab="tabSync">
                <i class="bi bi-cloud-arrow-up"></i> ซิงค์ข้อมูลการจ่ายยาผู้ป่วยนอก (OPD)
            </button>
            <button type="button" class="agent-tab-btn" data-tab="tabSettings">
                <i class="bi bi-sliders"></i> ตั้งค่าการเชื่อมต่อ
            </button>
            <button type="button" class="agent-tab-btn" data-tab="tabDrugs">
                <i class="bi bi-capsule"></i> ทะเบียนยาที่ซิงค์ <span class="tab-count mono" id="badgeDrugCount">0</span>
            </button>
            <button type="button" class="agent-tab-btn" data-tab="tabHistory">
                <i class="bi bi-clock-history"></i> ประวัติ &amp; บันทึกระบบ
            </button>
        </nav>

        <!-- ================================================================
             TAB 1: SYNC & PREVIEW
             ================================================================ -->
        <section id="tabSync" class="tab-pane active">

            <!-- First-Time Setup Guide (auto-hidden when configured) -->
            <div class="setup-notice" id="boxSetupNotice" style="display: none;">
                <div class="setup-notice-text">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>เริ่มต้นใช้งานจริง:</strong> กรุณากำหนดรหัสหน่วยบริการ 5 หลัก (HCODE), ข้อมูลฐานข้อมูล HOSxP Read-only และ Sync API Key ก่อนเริ่มซิงค์ครั้งแรก
                </div>
                <button type="button" class="rx-btn rx-btn-primary rx-btn-sm" id="btnGoSetup">
                    <i class="bi bi-gear"></i> ไปหน้าตั้งค่า
                </button>
            </div>

            <!-- Date Range & Action Controls -->
            <div class="rx-card">
                <div class="rx-card-header">
                    <h2 class="rx-card-title"><i class="bi bi-calendar-range"></i> เลือกช่วงวันที่รับบริการผู้ป่วยนอก (vstdate)</h2>
                    <span class="badge-soft mono" id="lblCurrentHcode">HCODE: ยังไม่ระบุ</span>
                </div>
                <div class="rx-card-body">
                    <div class="preset-bar">
                        <button type="button" class="preset-chip active" data-preset="today">วันนี้</button>
                        <button type="button" class="preset-chip" data-preset="yesterday">เมื่อวาน</button>
                        <button type="button" class="preset-chip" data-preset="7d">7 วันย้อนหลัง</button>
                        <button type="button" class="preset-chip" data-preset="30d">กระทบยอด 30 วัน</button>
                    </div>

                    <div class="sync-controls">
                        <div class="date-inputs">
                            <div>
                                <label class="field-label" for="inpStartDate">ตั้งแต่วันที่</label>
                                <input type="date" id="inpStartDate" class="rx-input mono" value="<?= htmlspecialchars($today) ?>">
                            </div>
                            <div>
                                <label class="field-label" for="inpEndDate">ถึงวันที่</label>
                                <input type="date" id="inpEndDate" class="rx-input mono" value="<?= htmlspecialchars($today) ?>">
                            </div>
                        </div>

                        <div class="sync-actions">
                            <button type="button" class="rx-btn rx-btn-outline" id="btnPreview">
                                <i class="bi bi-search"></i> ดึงข้อมูลตรวจสอบ (Preview)
                            </button>
                            <button type="button" class="rx-btn rx-btn-primary" id="btnSyncNow">
                                <i class="bi bi-cloud-check"></i> ซิงค์ขึ้นระบบกลางทันที
                            </button>
                        </div>
                    </div>

                    <label class="check-inline">
                        <input type="checkbox" id="chkReconcile">
                        <span>กระทบยอดรายการที่ถูกยกเลิกใน HOSxP อัตโนมัติ (Soft Delete Reconciliation สำหรับช่วงวันที่เลือก)</span>
                    </label>

                    <div class="diag-banner" id="syncBanner"></div>
                </div>
            </div>

            <!-- 4 Summary KPIs -->
            <div class="kpi-grid">
                <div class="kpi-box">
                    <div class="kpi-label">อ่านจาก HOSxP (OPD)</div>
                    <div class="kpi-value mono" id="kpiRead">0</div>
                    <div class="kpi-sub">เฉพาะรหัสยาในทะเบียน</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-label">ผ่านเกณฑ์พร้อมส่ง</div>
                    <div class="kpi-value mono" id="kpiValid">0</div>
                    <div class="kpi-sub">6 ฟิลด์มาตรฐาน ไร้ข้อมูลผู้ป่วย</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-label">ซิงค์สำเร็จ (Upsert)</div>
                    <div class="kpi-value mono" id="kpiSynced">0</div>
                    <div class="kpi-sub" id="kpiSyncedSub">รอการสั่งซิงค์</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-label">ตัดซ้ำ / ปรับยกเลิก</div>
                    <div class="kpi-value mono" id="kpiSkipped">0</div>
                    <div class="kpi-sub" id="kpiSkippedSub">ป้องกันรายการซ้ำอัตโนมัติ</div>
                </div>
            </div>

            <!-- Preview / Result Data Table -->
            <div class="rx-card">
                <div class="rx-card-header">
                    <h2 class="rx-card-title">
                        <i class="bi bi-table"></i> รายการจ่ายยาผู้ป่วยนอกที่ตรวจสอบแล้ว (OPD Dispense)
                        <span class="badge-soft mono" id="lblPreviewCount">0 รายการ</span>
                    </h2>
                    <div style="min-width: 240px;">
                        <input type="search" id="inpFilterPreview" class="rx-input rx-btn-sm" placeholder="ค้นหาชื่อยา, รหัส TPU, VN...">
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="rx-table">
                        <thead>
                            <tr>
                                <th>วันที่รับบริการ</th>
                                <th>VN</th>
                                <th>HN</th>
                                <th>รหัส TPU</th>
                                <th>ชื่อรายการยา</th>
                                <th class="text-end">จำนวนจ่าย</th>
                                <th>หน่วย</th>
                                <th>HOS_GUID</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPreview">
                            <tr>
                                <td colspan="8" class="empty-state">
                                    เลือกช่วงวันที่แล้วกด <strong>ดึงข้อมูลตรวจสอบ (Preview)</strong> หรือ <strong>ซิงค์ขึ้นระบบกลางทันที</strong> (เฉพาะข้อมูลผู้ป่วยนอก OPD)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================
             TAB 2: CONNECTION SETTINGS
             ================================================================ -->
        <section id="tabSettings" class="tab-pane">
            <form id="formSettings" autocomplete="off">
                <div class="grid-2">

                    <!-- Panel 1: HOSxP Read-only MySQL -->
                    <div class="rx-card">
                        <div class="rx-card-header">
                            <h2 class="rx-card-title"><i class="bi bi-database-lock"></i> 1. ฐานข้อมูลต้นทาง HOSxP / HOSxP_PCU (Read-only)</h2>
                            <span class="badge-soft" id="badgeSavedPass">รหัสผ่าน: ยังไม่บันทึก</span>
                        </div>
                        <div class="rx-card-body">

                            <div class="field-group" id="wrapQuickSelectHc" style="display: none;">
                                <label class="field-label" for="selHealthCenter">เลือกหน่วยบริการที่ลงทะเบียนในระบบกลาง</label>
                                <select id="selHealthCenter" class="rx-select">
                                    <option value="">-- เลือกเพื่อเติมรหัส HCODE อัตโนมัติ --</option>
                                </select>
                            </div>

                            <div class="form-row-2">
                                <div class="field-group">
                                    <label class="field-label" for="cfgHcode">รหัสหน่วยบริการ 5 หลัก (HCODE) *</label>
                                    <input type="text" id="cfgHcode" class="rx-input mono" maxlength="5" placeholder="เช่น 10968 หรือรหัส รพ.สต.">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="cfgFacilityName">ชื่อหน่วยบริการ</label>
                                    <input type="text" id="cfgFacilityName" class="rx-input" placeholder="เช่น รพ.สต. ในเครือข่าย">
                                </div>
                            </div>

                            <div class="form-row-3">
                                <div class="field-group">
                                    <label class="field-label" for="cfgHost">MySQL Host / IP *</label>
                                    <input type="text" id="cfgHost" class="rx-input mono" placeholder="127.0.0.1">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="cfgPort">Port *</label>
                                    <input type="number" id="cfgPort" class="rx-input mono" value="3306" min="1" max="65535">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="cfgDatabase">ชื่อฐานข้อมูล *</label>
                                    <input type="text" id="cfgDatabase" class="rx-input mono" placeholder="hosxp_pcu หรือ hos">
                                </div>
                            </div>

                            <div class="form-row-2">
                                <div class="field-group">
                                    <label class="field-label" for="cfgUsername">ชื่อผู้ใช้ฐานข้อมูล MySQL *</label>
                                    <input type="text" id="cfgUsername" class="rx-input mono" placeholder="เช่น sa, hos, root หรือ rxcore_ro">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="cfgPassword">รหัสผ่านฐานข้อมูล</label>
                                    <input type="password" id="cfgPassword" class="rx-input mono" placeholder="เว้นว่างไว้หากใช้รหัสผ่านเดิม">
                                </div>
                            </div>

                            <div class="field-hint">
                                เชื่อมต่อได้กับทุกบัญชีผู้ใช้ (เช่น <code>sa</code>, <code>root</code>, <code>hos</code> หรือ <code>rxcore_ro</code>) โดยตัวระบบจะส่งเฉพาะคำสั่ง <code>SELECT</code> เพื่ออ่านข้อมูลเท่านั้น
                            </div>

                            <div style="margin-top: 0.9rem;">
                                <button type="button" class="rx-btn rx-btn-outline" id="btnTestHosxp">
                                    <i class="bi bi-shield-check"></i> ทดสอบเชื่อมต่อฐานข้อมูล HOSxP
                                </button>
                            </div>

                            <div class="diag-banner" id="hosxpDiagBanner"></div>

                            <details class="sql-helper">
                                <summary>ตัวอย่างคำสั่งสร้างบัญชี Read-only สำหรับ HOSxP MySQL</summary>
                                <pre class="mono">CREATE USER 'rxcore_ro'@'%' IDENTIFIED BY 'รหัสผ่านที่ปลอดภัย';
GRANT SELECT ON hosxp_pcu.opitemrece TO 'rxcore_ro'@'%';
GRANT SELECT ON hosxp_pcu.drugitems TO 'rxcore_ro'@'%';
GRANT SELECT ON hosxp_pcu.vn_stat TO 'rxcore_ro'@'%';
FLUSH PRIVILEGES;</pre>
                            </details>
                        </div>
                    </div>

                    <!-- Panel 2: Supabase Cloud & Auto-Sync -->
                    <div class="rx-card">
                        <div class="rx-card-header">
                            <h2 class="rx-card-title"><i class="bi bi-cloud-lock"></i> 2. ระบบกลาง (Supabase Cloud) &amp; รอบซิงค์</h2>
                            <span class="badge-soft mono" id="badgeSavedSyncKey">API Key: ยังไม่บันทึก</span>
                        </div>
                        <div class="rx-card-body">

                            <div class="field-group">
                                <label class="field-label" for="cfgSupabaseUrl">Supabase Project URL *</label>
                                <input type="url" id="cfgSupabaseUrl" class="rx-input mono" placeholder="https://xxxx.supabase.co">
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="cfgSupabaseAnonKey">
                                    <span>Supabase Anon Key</span>
                                    <span id="lblAnonHint" class="mono" style="font-size: 0.73rem;"></span>
                                </label>
                                <input type="password" id="cfgSupabaseAnonKey" class="rx-input mono" placeholder="เว้นว่างไว้หากใช้ค่าที่บันทึกแล้ว หรือค่าจาก .env แม่ข่าย">
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="cfgSyncApiKey">Sync API Key ของหน่วยบริการ (สร้างจากหน้าเว็บหลัก) *</label>
                                <input type="password" id="cfgSyncApiKey" class="rx-input mono" placeholder="rxk_live_xxxxx_... (เว้นว่างไว้หากใช้คีย์เดิม)">
                                <div class="field-hint">
                                    สร้างคีย์ได้ที่เว็บหลัก เมนู <strong>หน่วยบริการ รพ.สต.</strong> &rarr; ปุ่มสร้าง Sync Key (เก็บเข้ารหัส AES-256-GCM ในเครื่องนี้)
                                </div>
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="cfgCentralWebUrl">URL เว็บแม่ข่าย (ตัวเลือกเสริมสำหรับดึงทะเบียนยาจากเครื่องลูกข่าย)</label>
                                <input type="url" id="cfgCentralWebUrl" class="rx-input mono" placeholder="เช่น https://rxcore.hospital.go.th/rxcore">
                            </div>

                            <div class="field-group">
                                <label class="field-label">รอบเวลาซิงค์อัตโนมัติ (เมื่อเปิด Service Host)</label>
                                <div class="slot-group">
                                    <label class="slot-pill mono"><input type="checkbox" class="chk-slot" value="09:00" checked> 09:00</label>
                                    <label class="slot-pill mono"><input type="checkbox" class="chk-slot" value="12:00"> 12:00</label>
                                    <label class="slot-pill mono"><input type="checkbox" class="chk-slot" value="14:00" checked> 14:00</label>
                                    <label class="slot-pill mono"><input type="checkbox" class="chk-slot" value="17:00" checked> 17:00</label>
                                    <label class="slot-pill mono"><input type="checkbox" class="chk-slot" value="20:00"> 20:00</label>
                                </div>
                            </div>

                            <div style="margin-top: 0.9rem;">
                                <button type="button" class="rx-btn rx-btn-outline" id="btnTestCloud">
                                    <i class="bi bi-wifi"></i> ทดสอบเชื่อมต่อ Supabase &amp; ตรวจ Sync Key
                                </button>
                            </div>

                            <div class="diag-banner" id="cloudDiagBanner"></div>
                        </div>
                    </div>

                </div>

                <!-- Panel 3: MOPH Notify / หมอพร้อม (ส่งแจ้งเตือนทันทีจากเครื่อง รพ.สต.) -->
                <div class="rx-card" style="margin-bottom: 1.15rem;">
                    <div class="rx-card-header">
                        <h2 class="rx-card-title"><i class="bi bi-bell"></i> 3. แจ้งเตือน MOPH Notify (หมอพร้อม / LINE)</h2>
                        <span class="badge-soft mono" id="badgeMophStatus">MOPH Notify: ปิดใช้งาน</span>
                    </div>
                    <div class="rx-card-body">
                        <div class="form-row-2" style="margin-bottom: 0.75rem;">
                            <label class="check-inline" style="margin: 0;">
                                <input type="checkbox" id="cfgMophEnabled">
                                <span>เปิดแจ้งเตือนทันทีหลังรัน <strong>Auto-Sync (.bat)</strong></span>
                            </label>
                            <label class="check-inline" style="margin: 0;">
                                <input type="checkbox" id="cfgMophManual">
                                <span>แจ้งเตือนด้วยเมื่อกดซิงค์มือจากหน้าเว็บนี้</span>
                            </label>
                        </div>

                        <div class="form-row-2">
                            <div class="field-group">
                                <label class="field-label" for="cfgMophClientKey">
                                    <span>MOPH Client Key</span>
                                    <span id="lblMophClientMask" class="mono" style="font-size: 0.73rem;"></span>
                                </label>
                                <input type="password" id="cfgMophClientKey" class="rx-input mono" placeholder="เว้นว่างไว้หากใช้ Client Key เดิม">
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="cfgMophSecretKey">
                                    <span>MOPH Secret Key</span>
                                    <span id="lblMophSecretMask" class="mono" style="font-size: 0.73rem;"></span>
                                </label>
                                <input type="password" id="cfgMophSecretKey" class="rx-input mono" placeholder="เว้นว่างไว้หากใช้ Secret Key เดิม">
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                            <button type="button" class="rx-btn rx-btn-outline" id="btnTestMoph">
                                <i class="bi bi-send"></i> ทดสอบส่งข้อความ MOPH Notify
                            </button>
                            <span class="field-hint" style="margin: 0;">
                                ข้อความสรุปเฉพาะชื่อหน่วยบริการและจำนวนรายการ (ไม่ส่งข้อมูลผู้ป่วย)
                            </span>
                        </div>

                        <div class="diag-banner" id="mophDiagBanner"></div>
                    </div>
                </div>

                <!-- Save Bar -->
                <div class="rx-card">
                    <div class="rx-card-body" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                        <div class="field-hint" style="margin: 0;">
                            <i class="bi bi-lock-fill me-1"></i> รหัสผ่านฐานข้อมูลและ Sync API Key ทุกตัวถูกเข้ารหัสด้วย <strong>AES-256-GCM</strong> ผูกกับเครื่องนี้เท่านั้น
                        </div>
                        <div style="display: flex; gap: 0.65rem;">
                            <button type="button" class="rx-btn rx-btn-danger-ghost" id="btnClearConfig">
                                <i class="bi bi-trash3"></i> ล้างค่าตั้งค่า
                            </button>
                            <button type="submit" class="rx-btn rx-btn-primary" id="btnSaveSettings">
                                <i class="bi bi-check2-circle"></i> บันทึกการตั้งค่าทั้งหมด
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- ================================================================
             TAB 3: ACTIVE DRUG REGISTRY
             ================================================================ -->
        <section id="tabDrugs" class="tab-pane">
            <div class="rx-card">
                <div class="rx-card-header">
                    <h2 class="rx-card-title">
                        <i class="bi bi-capsule-pill"></i> ทะเบียนยาที่อนุญาตให้ซิงค์ข้อมูล
                        <span class="badge-soft mono" id="lblRegistrySummary">0 รายการ</span>
                    </h2>
                    <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center;">
                        <select id="selDrugGroup" class="rx-select rx-btn-sm" style="width: auto; min-width: 180px;">
                            <option value="">ทุกหมวดยา</option>
                        </select>
                        <input type="search" id="inpSearchDrug" class="rx-input rx-btn-sm" style="width: 220px;" placeholder="ค้นหาชื่อยา, icode, TPU...">
                        <button type="button" class="rx-btn rx-btn-outline rx-btn-sm" id="btnRefreshDrugs">
                            <i class="bi bi-arrow-clockwise"></i> อัปเดตจากระบบกลาง
                        </button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="rx-table">
                        <thead>
                            <tr>
                                <th>ICODE</th>
                                <th>ชื่อรายการยา</th>
                                <th>รหัส TPU</th>
                                <th>หมวดยา</th>
                                <th>หน่วยนับ</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyDrugs">
                            <tr><td colspan="5" class="empty-state">กำลังโหลดทะเบียนยา...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================
             TAB 4: SYNC HISTORY & LOGS
             ================================================================ -->
        <section id="tabHistory" class="tab-pane">

            <div class="rx-card">
                <div class="rx-card-header">
                    <h2 class="rx-card-title"><i class="bi bi-journal-check"></i> ประวัติการซิงค์ข้อมูลผู้ป่วยนอกของเครื่องนี้</h2>
                    <button type="button" class="rx-btn rx-btn-ghost rx-btn-sm" id="btnClearHistory">
                        <i class="bi bi-eraser"></i> ล้างประวัติ
                    </button>
                </div>
                <div class="table-wrap" style="max-height: 300px;">
                    <table class="rx-table">
                        <thead>
                            <tr>
                                <th>วัน-เวลา</th>
                                <th>HCODE</th>
                                <th>ช่วงข้อมูล</th>
                                <th>โหมด</th>
                                <th class="text-end">อ่านได้</th>
                                <th class="text-end">ซิงค์สำเร็จ</th>
                                <th>สถานะ</th>
                                <th>รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyHistory">
                            <tr><td colspan="8" class="empty-state">ยังไม่มีประวัติการซิงค์ข้อมูล</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rx-card">
                <div class="rx-card-header">
                    <h2 class="rx-card-title"><i class="bi bi-terminal"></i> บันทึกการทำงานของระบบวันนี้ (Agent Log)</h2>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="rx-btn rx-btn-ghost rx-btn-sm" id="btnRefreshLogs">
                            <i class="bi bi-arrow-clockwise"></i> รีเฟรช
                        </button>
                        <button type="button" class="rx-btn rx-btn-ghost rx-btn-sm" id="btnClearLogs">
                            <i class="bi bi-trash"></i> ล้าง Log
                        </button>
                    </div>
                </div>
                <div class="rx-card-body">
                    <div class="log-viewer mono" id="boxAgentLogs">ยังไม่มีบันทึกการทำงานในวันนี้</div>
                </div>
            </div>

        </section>

    </main>

    <footer style="max-width: 1320px; margin: 0 auto 1.5rem; padding: 0.85rem 1.25rem; font-size: 0.78rem; color: var(--ag-text-muted); border-top: 1px solid var(--ag-border); display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem;">
        <div><strong>RxCore Sync Agent</strong> &middot; กลุ่มงานสุขภาพดิจิทัล โรงพยาบาลค้อวัง</div>
        <div>พัฒนาโดย กฤษนัฐ สีมารัตนกูล (นักวิชาการคอมพิวเตอร์ปฏิบัติการ) &middot; โทร. <span class="mono">045797161</span> &middot; <span class="mono">kwhosp@gmail.com</span></div>
    </footer>

    <div class="rx-toast" id="rxToast"></div>

    <script src="assets/agent.js?v=4.0"></script>
</body>
</html>
