<?php
/**
 * RxCore — ทะเบียนยา (Drug Master Catalog)
 * เชื่อมต่อและจัดการข้อมูลจริงบน Supabase Cloud (ตาราง rx_drug_lookup & rx_drug_cost_history)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';

$current_page = 'drugs';
$page_title = 'ทะเบียนยา';

$flashMsg = '';
$flashType = 'success';

// Export CSV (UTF-8 BOM สำหรับเปิดใน Excel ภาษาไทยได้ทันที)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = SupabaseService::select('rx_drug_lookup', 'select=icode,drug_name,tpu_code_list,drug_type_name,unitcost,unitprice,unit,is_active&order=icode.asc');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rxcore_drug_catalog_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['icode', 'drugname', 'tpu_code_list', 'drug_type_name', 'unitcost', 'unitprice', 'units', 'status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['icode'] ?? '',
            $r['drug_name'] ?? '',
            $r['tpu_code_list'] ?? '',
            $r['drug_type_name'] ?? '',
            number_format((float)($r['unitcost'] ?? 0), 2, '.', ''),
            number_format((float)($r['unitprice'] ?? 0), 2, '.', ''),
            $r['unit'] ?? '',
            !empty($r['is_active']) ? 'active' : 'inactive',
        ]);
    }
    fclose($out);
    exit;
}

// จัดการเพิ่ม / แก้ไข / เปิด-ปิดใช้งานยา (เฉพาะ Admin)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_admin()) {
    if (!verify_csrf()) {
        $flashMsg = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
        $flashType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_drug') {
            $editId = (int)($_POST['id'] ?? 0);
            $icode = trim($_POST['icode'] ?? '') !== '' ? (int)$_POST['icode'] : null;
            $drugName = trim($_POST['drug_name'] ?? '');
            $tpuCode = trim($_POST['tpu_code_list'] ?? '');
            $drugType = trim($_POST['drug_type_name'] ?? '');
            $unitCost = max(0, (float)($_POST['unitcost'] ?? 0));
            $unitPrice = max(0, (float)($_POST['unitprice'] ?? 0));
            $unit = trim($_POST['unit'] ?? '');
            $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';

            if ($drugName === '' || $tpuCode === '' || $unit === '') {
                $flashMsg = 'กรุณากรอกชื่อยา, รหัส TPU และหน่วยนับให้ครบถ้วน';
                $flashType = 'danger';
            } else {
                $payload = [
                    'icode'          => $icode,
                    'drug_name'      => $drugName,
                    'tpu_code_list'  => $tpuCode,
                    'drug_type_name' => $drugType !== '' ? $drugType : null,
                    'unitcost'       => $unitCost,
                    'unitprice'      => $unitPrice,
                    'unit'           => $unit,
                    'is_active'      => $isActive,
                    'updated_at'     => gmdate('c'),
                ];

                if ($editId > 0) {
                    $res = SupabaseService::update('rx_drug_lookup', 'id=eq.' . $editId, $payload);
                    if ($res['ok']) {
                        // บันทึกประวัติต้นทุน ณ วันปัจจุบันหากมีการปรับเปลี่ยน
                        SupabaseService::insert('rx_drug_cost_history', [
                            'drug_id'        => $editId,
                            'effective_date' => date('Y-m-d'),
                            'unit_cost'      => $unitCost,
                            'note'           => 'อัปเดตผ่านหน้าทะเบียนยา',
                        ], 'drug_id,effective_date');
                        $flashMsg = "อัปเดตข้อมูลยา {$drugName} เรียบร้อยแล้ว";
                    } else {
                        $flashMsg = 'บันทึกไม่สำเร็จ: ' . $res['error'];
                        $flashType = 'danger';
                    }
                } else {
                    $res = SupabaseService::insert('rx_drug_lookup', $payload);
                    if ($res['ok'] && !empty($res['data'][0]['id'])) {
                        $newDrugId = (int)$res['data'][0]['id'];
                        SupabaseService::insert('rx_drug_cost_history', [
                            'drug_id'        => $newDrugId,
                            'effective_date' => date('Y-m-d'),
                            'unit_cost'      => $unitCost,
                            'note'           => 'ต้นทุนเริ่มต้นเมื่อเพิ่มรายการยา',
                        ], 'drug_id,effective_date');
                        $flashMsg = "เพิ่มรายการยา {$drugName} เรียบร้อยแล้ว";
                    } else {
                        $flashMsg = 'ไม่สามารถเพิ่มรายการยาได้: ' . $res['error'];
                        $flashType = 'danger';
                    }
                }
            }
        } elseif ($action === 'toggle_status') {
            $targetId = (int)($_POST['id'] ?? 0);
            $newState = ($_POST['new_state'] ?? '1') === '1';
            if ($targetId > 0) {
                $res = SupabaseService::update('rx_drug_lookup', 'id=eq.' . $targetId, [
                    'is_active'  => $newState,
                    'updated_at' => gmdate('c'),
                ]);
                if ($res['ok']) {
                    $flashMsg = $newState ? 'เปิดใช้งานรายการยาเรียบร้อยแล้ว' : 'ระงับการใช้งานรายการยาเรียบร้อยแล้ว';
                } else {
                    $flashMsg = 'เปลี่ยนสถานะไม่สำเร็จ: ' . $res['error'];
                    $flashType = 'danger';
                }
            }
        }
    }
}

// ดึงข้อมูลยาจริงทั้งหมดจาก Supabase
$res = SupabaseService::request('GET', 'rx_drug_lookup?select=*&order=icode.asc');
$allDrugs = ($res['ok'] && is_array($res['data'])) ? $res['data'] : [];
$dbError = !$res['ok'] ? $res['error'] : '';

// นับจำนวนตามกลุ่มยา (เรียงตามจำนวนมากไปน้อยสำหรับปุ่มกรองด่วน)
$groupCounts = [];
$activeCount = 0;
foreach ($allDrugs as $d) {
    if (!empty($d['is_active'])) {
        $activeCount++;
    }
    $g = trim((string)($d['drug_type_name'] ?? ''));
    if ($g !== '') {
        $groupCounts[$g] = ($groupCounts[$g] ?? 0) + 1;
    }
}
arsort($groupCounts);
$topGroups = array_slice(array_keys($groupCounts), 0, 7);
$allGroupsSorted = array_keys($groupCounts);
natcasesort($allGroupsSorted);

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php if ($flashMsg !== ''): ?>
            <div class="rx-notice <?= htmlspecialchars($flashType) ?>">
                <div><i class="bi <?= $flashType === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle' ?> me-2"></i><?= htmlspecialchars($flashMsg) ?></div>
                <button type="button" class="btn-close btn-sm" onclick="this.parentElement.remove()"></button>
            </div>
        <?php endif; ?>

        <?php if ($dbError !== ''): ?>
            <div class="rx-notice danger">
                <div><i class="bi bi-wifi-off me-2"></i>เชื่อมต่อฐานข้อมูลขัดข้อง: <?= htmlspecialchars($dbError) ?></div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">ทะเบียนยา</h1>
                <p class="page-description">
                    ทั้งหมด <strong class="tabular-nums"><?= count($allDrugs) ?></strong> รายการ &middot;
                    เปิดใช้งาน <strong class="tabular-nums"><?= $activeCount ?></strong> รายการ &middot;
                    <?= count($groupCounts) ?> กลุ่มยา
                </p>
            </div>
            <div class="d-flex align-items-center gap-2 rx-no-print">
                <a href="<?= url('pages/drugs.php?export=csv') ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> ส่งออก CSV
                </a>
                <?php if (is_admin()): ?>
                    <button type="button" class="btn btn-rx-primary btn-sm" onclick="openAddDrugModal()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการยา
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Instant Filter & Search Bar -->
        <div class="rx-panel p-3 mb-3 rx-no-print">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="drugSearchInput" class="form-control" placeholder="พิมพ์ค้นหาทันที: ชื่อยา, รหัส icode หรือ TPU... (กด /)">
                    </div>
                </div>
                <div class="col-7 col-md-4">
                    <select id="drugGroupSelect" class="form-select">
                        <option value="">ทุกกลุ่มยา (<?= count($allGroupsSorted) ?> กลุ่ม)</option>
                        <?php foreach ($allGroupsSorted as $grp): ?>
                            <option value="<?= htmlspecialchars($grp) ?>">
                                <?= htmlspecialchars($grp) ?> (<?= $groupCounts[$grp] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5 col-md-3 d-flex gap-2">
                    <select id="drugStatusSelect" class="form-select">
                        <option value="all">ทุกสถานะ</option>
                        <option value="active">เปิดใช้เท่านั้น</option>
                        <option value="inactive">ปิดใช้</option>
                    </select>
                    <button type="button" id="resetFilterBtn" class="btn btn-rx-secondary d-none" title="ล้างตัวกรอง">
                        ล้าง
                    </button>
                </div>
            </div>

            <!-- Quick Group Chips -->
            <?php if (!empty($topGroups)): ?>
                <div class="rx-chip-bar mt-2 pt-2 border-top" style="border-color: var(--rx-border) !important;">
                    <button type="button" class="rx-chip active" data-group-chip="">ทั้งหมด</button>
                    <?php foreach ($topGroups as $tg): ?>
                        <button type="button" class="rx-chip" data-group-chip="<?= htmlspecialchars($tg) ?>">
                            <?= htmlspecialchars($tg) ?> <span class="opacity-75 ms-1"><?= $groupCounts[$tg] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Clean Low-Clutter Table -->
        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header py-2 px-3">
                <span class="small text-secondary">
                    แสดง <strong id="visibleCountLabel" class="tabular-nums"><?= count($allDrugs) ?></strong> จาก <?= count($allDrugs) ?> รายการ
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-rx" id="drugsTable">
                    <thead>
                        <tr>
                            <th style="width: 105px;">รหัส icode</th>
                            <th>ชื่อยาและความแรง</th>
                            <th style="width: 120px;">รหัส TPU</th>
                            <th style="width: 170px;">กลุ่มยา</th>
                            <th class="text-end" style="width: 105px;">ต้นทุน</th>
                            <th class="text-end" style="width: 105px;">ราคาขาย</th>
                            <th style="width: 120px;">หน่วยนับ</th>
                            <th class="text-center" style="width: 100px;">สถานะ</th>
                            <?php if (is_admin()): ?>
                                <th class="text-end rx-no-print" style="width: 100px;">จัดการ</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allDrugs)): ?>
                            <tr>
                                <td colspan="<?= is_admin() ? 9 : 8 ?>">
                                    <div class="rx-empty-state">
                                        <i class="bi bi-capsule"></i>
                                        <div class="fw-medium mb-1">ยังไม่มีรายการยาในระบบ</div>
                                        <div class="small">คลิกปุ่ม "เพิ่มรายการยา" เพื่อเริ่มบันทึกทะเบียนยา</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allDrugs as $drug):
                                $isActive = !empty($drug['is_active']);
                                $searchBlob = mb_strtolower(
                                    ($drug['icode'] ?? '') . ' ' .
                                    ($drug['drug_name'] ?? '') . ' ' .
                                    ($drug['tpu_code_list'] ?? '') . ' ' .
                                    ($drug['drug_type_name'] ?? '') . ' ' .
                                    ($drug['unit'] ?? '')
                                );
                            ?>
                                <tr class="drug-row"
                                    data-search="<?= htmlspecialchars($searchBlob) ?>"
                                    data-group="<?= htmlspecialchars((string)($drug['drug_type_name'] ?? '')) ?>"
                                    data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                                    <td class="rx-code"><?= htmlspecialchars((string)($drug['icode'] ?? '-')) ?></td>
                                    <td>
                                        <span class="fw-medium <?= !$isActive ? 'text-muted text-decoration-line-through' : '' ?>">
                                            <?= htmlspecialchars((string)($drug['drug_name'] ?? '-')) ?>
                                        </span>
                                    </td>
                                    <td class="rx-code"><?= htmlspecialchars((string)($drug['tpu_code_list'] ?? '-')) ?></td>
                                    <td>
                                        <?php if (!empty($drug['drug_type_name'])): ?>
                                            <span class="rx-tag"><?= htmlspecialchars((string)$drug['drug_type_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono"><?= number_format((float)($drug['unitcost'] ?? 0), 2) ?></td>
                                    <td class="text-end font-mono text-secondary"><?= number_format((float)($drug['unitprice'] ?? 0), 2) ?></td>
                                    <td class="text-secondary small"><?= htmlspecialchars((string)($drug['unit'] ?? '-')) ?></td>
                                    <td class="text-center">
                                        <span class="rx-status <?= $isActive ? 'active' : 'inactive' ?>">
                                            <?= $isActive ? 'เปิดใช้' : 'ปิดใช้' ?>
                                        </span>
                                    </td>
                                    <?php if (is_admin()): ?>
                                        <td class="text-end rx-no-print">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button"
                                                        class="btn btn-rx-secondary btn-sm py-1 px-2"
                                                        title="แก้ไข"
                                                        onclick='openEditDrugModal(<?= json_encode([
                                                            'id'             => $drug['id'],
                                                            'icode'          => $drug['icode'],
                                                            'drug_name'      => $drug['drug_name'],
                                                            'tpu_code_list'  => $drug['tpu_code_list'],
                                                            'drug_type_name' => $drug['drug_type_name'],
                                                            'unitcost'       => $drug['unitcost'],
                                                            'unitprice'      => $drug['unitprice'],
                                                            'unit'           => $drug['unit'],
                                                            'is_active'      => $isActive ? 1 : 0,
                                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-rx-secondary btn-sm py-1 px-2"
                                                        title="<?= $isActive ? 'ระงับการใช้ยานี้' : 'เปิดใช้ยานี้' ?>"
                                                        onclick="toggleDrugStatus(<?= (int)$drug['id'] ?>, '<?= $isActive ? '0' : '1' ?>')">
                                                    <i class="bi <?= $isActive ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="noMatchRow" class="d-none">
                                <td colspan="<?= is_admin() ? 9 : 8 ?>">
                                    <div class="rx-empty-state py-4">
                                        <i class="bi bi-search"></i>
                                        <div>ไม่พบรายการยาที่ตรงกับเงื่อนไขการค้นหา</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php if (is_admin()): ?>
    <form method="POST" id="toggleDrugForm" class="d-none">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="id" id="td_id" value="0">
        <input type="hidden" name="new_state" id="td_state" value="1">
    </form>

    <!-- Modal เพิ่ม / แก้ไขรายการยาจริง -->
    <div class="modal fade" id="drugModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_drug">
                <input type="hidden" name="id" id="dm_id" value="0">

                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold mb-0" id="drugModalTitle">เพิ่มรายการยาใหม่</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">รหัสยา HOSxP (icode)</label>
                            <input type="number" name="icode" id="dm_icode" class="form-control font-mono" placeholder="เช่น 1000227">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">รหัสมาตรฐาน TPU <span class="text-danger">*</span></label>
                            <input type="text" name="tpu_code_list" id="dm_tpu" class="form-control font-mono" placeholder="เช่น 438642" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">หน่วยนับ <span class="text-danger">*</span></label>
                            <input type="text" name="unit" id="dm_unit" class="form-control" placeholder="เช่น เม็ด, แค็บซูล, ขวด" required>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">ชื่อยาและความแรง <span class="text-danger">*</span></label>
                            <input type="text" name="drug_name" id="dm_name" class="form-control" placeholder="เช่น PARACETAMOL 500 mg." required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">กลุ่มยา (drug_type_name)</label>
                            <input type="text" name="drug_type_name" id="dm_type" list="drugTypeSuggestions" class="form-control" placeholder="เช่น Antipyretic, HT, DM">
                            <datalist id="drugTypeSuggestions">
                                <?php foreach ($allGroupsSorted as $grp): ?>
                                    <option value="<?= htmlspecialchars($grp) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">ต้นทุนต่อหน่วย (บาท)</label>
                            <input type="number" step="0.01" min="0" name="unitcost" id="dm_cost" class="form-control font-mono" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ราคาขายต่อหน่วย (บาท)</label>
                            <input type="number" step="0.01" min="0" name="unitprice" id="dm_price" class="form-control font-mono" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">สถานะการใช้งาน</label>
                            <select name="is_active" id="dm_active" class="form-select">
                                <option value="1">เปิดใช้งาน (อนุญาตให้ Sync)</option>
                                <option value="0">ระงับการใช้งาน</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-rx-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-rx-primary btn-sm"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('drugSearchInput');
        const groupSelect = document.getElementById('drugGroupSelect');
        const statusSelect = document.getElementById('drugStatusSelect');
        const resetBtn = document.getElementById('resetFilterBtn');
        const chips = document.querySelectorAll('[data-group-chip]');
        const rows = document.querySelectorAll('.drug-row');
        const visibleLabel = document.getElementById('visibleCountLabel');
        const noMatchRow = document.getElementById('noMatchRow');

        function applyFilter() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const g = groupSelect.value;
            const s = statusSelect.value;
            let shown = 0;

            rows.forEach(tr => {
                const matchQ = !q || tr.dataset.search.indexOf(q) !== -1;
                const matchG = !g || tr.dataset.group === g;
                const matchS = s === 'all' || tr.dataset.status === s;
                const visible = matchQ && matchG && matchS;
                tr.classList.toggle('d-none', !visible);
                if (visible) shown++;
            });

            if (visibleLabel) visibleLabel.textContent = shown;
            if (noMatchRow) noMatchRow.classList.toggle('d-none', shown > 0 || rows.length === 0);
            if (resetBtn) resetBtn.classList.toggle('d-none', !q && !g && s === 'all');

            chips.forEach(chip => {
                chip.classList.toggle('active', chip.getAttribute('data-group-chip') === g);
            });
        }

        if (searchInput) searchInput.addEventListener('input', applyFilter);
        if (groupSelect) groupSelect.addEventListener('change', applyFilter);
        if (statusSelect) statusSelect.addEventListener('change', applyFilter);

        chips.forEach(chip => {
            chip.addEventListener('click', function () {
                groupSelect.value = this.getAttribute('data-group-chip');
                applyFilter();
            });
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                searchInput.value = '';
                groupSelect.value = '';
                statusSelect.value = 'all';
                applyFilter();
            });
        }

        // Shortcut '/' เพื่อโฟกัสช่องค้นหาทันที
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                e.preventDefault();
                if (searchInput) searchInput.focus();
            }
        });
    });

    function toggleDrugStatus(id, newState) {
        document.getElementById('td_id').value = id;
        document.getElementById('td_state').value = newState;
        document.getElementById('toggleDrugForm').submit();
    }

    function openAddDrugModal() {
        document.getElementById('drugModalTitle').textContent = 'เพิ่มรายการยาใหม่';
        document.getElementById('dm_id').value = '0';
        document.getElementById('dm_icode').value = '';
        document.getElementById('dm_tpu').value = '';
        document.getElementById('dm_unit').value = 'เม็ด';
        document.getElementById('dm_name').value = '';
        document.getElementById('dm_type').value = '';
        document.getElementById('dm_cost').value = '0.00';
        document.getElementById('dm_price').value = '0.00';
        document.getElementById('dm_active').value = '1';
        new bootstrap.Modal(document.getElementById('drugModal')).show();
    }

    function openEditDrugModal(d) {
        document.getElementById('drugModalTitle').textContent = 'แก้ไขรายการยา';
        document.getElementById('dm_id').value = d.id || 0;
        document.getElementById('dm_icode').value = d.icode || '';
        document.getElementById('dm_tpu').value = d.tpu_code_list || '';
        document.getElementById('dm_unit').value = d.unit || '';
        document.getElementById('dm_name').value = d.drug_name || '';
        document.getElementById('dm_type').value = d.drug_type_name || '';
        document.getElementById('dm_cost').value = parseFloat(d.unitcost || 0).toFixed(2);
        document.getElementById('dm_price').value = parseFloat(d.unitprice || 0).toFixed(2);
        document.getElementById('dm_active').value = d.is_active ? '1' : '0';
        new bootstrap.Modal(document.getElementById('drugModal')).show();
    }
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
