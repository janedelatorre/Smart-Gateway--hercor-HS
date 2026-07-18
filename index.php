<?php
/**
 * Root entry point — redirects to the dashboard if already authenticated,
 * otherwise sends the visitor to the login page.
 */
require_once __DIR__ . '/database/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null));
} else {
    header('Location: login.php');
}
exit;
