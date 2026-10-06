<?php
/**
 * Smart Gateway SEO helpers.
 * Public pages opt in with $seoIndexable = true before including header.php.
 * Internal/authenticated pages remain noindex by default.
 */

if (!defined('SG_CANONICAL_BASE_URL')) {
    define('SG_CANONICAL_BASE_URL', 'https://smartgateway-hercorhs.site');
}

function sg_seo_url(string $path = ''): string {
    $base = rtrim(SG_CANONICAL_BASE_URL, '/');
    return $base . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}

function sg_seo_description(): string {
    return 'Smart Gateway is Hercor College High School Department’s campus entry verification system using student ID barcode scanning, facial recognition, real-time entry monitoring, and SMS notifications.';
}

function sg_seo_meta(array $overrides = []): array {
    $defaults = [
        'title'       => 'Smart Gateway | Hercor College High School Department',
        'description' => sg_seo_description(),
        'canonical'   => sg_seo_url(),
        'robots'      => 'index, follow',
        'type'        => 'website',
        'image'       => sg_seo_url('web-app-manifest-512x512.png'),
    ];
    return array_merge($defaults, $overrides);
}
