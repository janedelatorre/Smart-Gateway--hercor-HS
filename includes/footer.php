<?php /* Shared footer: closes layout, loads JS libraries */ ?>
    <footer class="text-center text-muted small py-3">
        &copy; <?= date('Y') ?> Smart Gateway System — All rights reserved.
    </footer>
</div><!-- /.sg-content -->

<!-- jQuery (self-hosted — Phase 2, was code.jquery.com; not referenced elsewhere in the app's own JS, kept only to avoid any behavior change) -->
<script src="<?= APP_URL ?>/assets/vendor/jquery/jquery.min.js"></script>
<!-- Bootstrap 5 JS Bundle (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<script src="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<!-- Chart.js (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<script src="<?= APP_URL ?>/assets/vendor/chartjs/chart.umd.js"></script>
<!-- App JS -->
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<!-- Notification Center -->
<script src="<?= APP_URL ?>/assets/js/notifications.js"></script>
</body>
</html>
