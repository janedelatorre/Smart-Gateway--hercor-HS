<?php /* Shared footer: closes layout, loads JS libraries */ ?>
    <footer class="text-center text-muted small py-3">
        &copy; <?= date('Y') ?> Smart Gateway System — All rights reserved.
    </footer>
</div><!-- /.sg-content -->

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<!-- App JS -->
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<!-- Notification Center -->
<script src="<?= APP_URL ?>/assets/js/notifications.js"></script>
</body>
</html>
