<?php
/**
 * RxCore — หน้ารวมรายงาน (Reports Menu List)
 * แสดงรายชื่อรายงานทั้งหมดเรียงเป็นแถวเพื่อให้คลิกเลือกเข้าใช้งานได้สะดวกและไม่รกตา
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/reports_registry.php';

$current_page = 'reports';
$page_title = 'รายงาน';

$catalog = rxcore_get_reports_catalog();

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">รายงาน</h1>
                <p class="page-description">เลือกรายงานที่ต้องการตรวจสอบและส่งออกข้อมูลการใช้ยาผู้ป่วยนอก (OPD)</p>
            </div>
        </div>

        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header">
                <span>รายชื่อรายงานทั้งหมด (<?= number_format(count($catalog)) ?> รายการ)</span>
                <div style="width: 240px;">
                    <input type="search" id="reportSearchInput" class="form-control form-control-sm" placeholder="ค้นหาชื่อรายงาน..." oninput="filterReportRows(this.value)">
                </div>
            </div>

            <div class="rx-report-list" id="reportListContainer">
                <?php foreach ($catalog as $idx => $rpt):
                    $numLabel = str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
                ?>
                    <a href="<?= url($rpt['path']) ?>" class="rx-report-row" data-search="<?= htmlspecialchars(mb_strtolower($rpt['title'] . ' ' . $rpt['description'], 'UTF-8')) ?>">
                        <div class="d-flex align-items-center gap-3">
                            <span class="report-index"><?= $numLabel ?></span>
                            <div>
                                <div class="report-row-title"><?= htmlspecialchars($rpt['title']) ?></div>
                                <p class="report-row-desc"><?= htmlspecialchars($rpt['description']) ?></p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 report-row-arrow">
                            <span class="small d-none d-sm-inline">เปิดรายงาน</span>
                            <i class="bi bi-chevron-right"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div id="reportEmptySearch" class="rx-empty-state" style="display: none;">
                <i class="bi bi-search"></i>
                <div class="fw-medium">ไม่พบรายงานที่ค้นหา</div>
            </div>
        </div>
    </main>

    <script>
        function filterReportRows(keyword) {
            var q = (keyword || '').trim().toLowerCase();
            var rows = document.querySelectorAll('#reportListContainer .rx-report-row');
            var visible = 0;
            rows.forEach(function(row) {
                var hay = row.getAttribute('data-search') || '';
                var show = (q === '' || hay.indexOf(q) !== -1);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var emptyEl = document.getElementById('reportEmptySearch');
            if (emptyEl) {
                emptyEl.style.display = (visible === 0) ? '' : 'none';
            }
        }
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
