<?php
/**
 * RxCore — Global Footer Component
 */
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
?>
    <footer class="rx-footer">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-2">
            <div>
                <strong><?= htmlspecialchars(APP_NAME) ?></strong> &middot; <?= htmlspecialchars(HOSPITAL_NAME) ?>
                <span class="ms-1 text-muted">v<?= htmlspecialchars(APP_VERSION) ?></span>
            </div>
            <div class="text-center text-lg-end">
                พัฒนาโดย: <strong>กฤษนัฐ สีมารัตนกูล</strong> นักวิชาการคอมพิวเตอร์ปฏิบัติการ &middot; กลุ่มงานสุขภาพดิจิทัล โรงพยาบาลค้อวัง &middot;
                <span class="font-mono"><i class="bi bi-telephone me-1"></i>045797161</span> &middot;
                <a href="mailto:kwhosp@gmail.com" class="text-decoration-none font-mono" style="color: inherit;">kwhosp@gmail.com</a>
            </div>
        </div>
    </footer>
</div><!-- /.app-main -->
</div><!-- /.app-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="<?= asset('js/main.js') ?>?v=<?= urlencode(APP_VERSION) ?>"></script>
</body>
</html>
