<?php
/**
 * Shared <head> header.
 * Expects optional $pageTitle variable to be set before including this file.
 */
$pageTitle = $pageTitle ?? 'Smart Gateway';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>document.documentElement.setAttribute('data-theme', localStorage.getItem('sgTheme') || 'dark');</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | Smart Gateway</title>

<!-- Inter font (self-hosted — Phase 2, was Google Fonts) -->
<link href="<?= APP_URL ?>/assets/fonts/inter/inter.css" rel="stylesheet">
<!-- Bootstrap 5 (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<link href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<!-- SweetAlert2 (self-hosted — Phase 2, was cdn.jsdelivr.net) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.min.css">
<!-- App styles -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/polish.css">
<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/favicon.svg" />
<link rel="shortcut icon" href="<?= APP_URL ?>/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="<?= APP_URL ?>/apple-touch-icon.png" />
<link rel="manifest" href="<?= APP_URL ?>/site.webmanifest" />
<meta name="robots" content="noindex, nofollow" />
</head>
<body>
