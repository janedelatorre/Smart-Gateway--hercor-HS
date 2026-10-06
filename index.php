<?php
/**
 * Public Smart Gateway landing page.
 * Authenticated users are still redirected to their role dashboard.
 * This page is intentionally public and is the only indexable URL.
 */
require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/seo_helper.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null));
    exit;
}

$canonical = sg_seo_url();
$description = sg_seo_description();
$ogImage = sg_seo_url('web-app-manifest-512x512.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Smart Gateway | Hercor College High School Department</title>
<meta name="description" content="<?= e($description) ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= e($canonical) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Smart Gateway — Hercor College High School Department">
<meta property="og:title" content="Smart Gateway | Hercor College High School Department">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:alt" content="Smart Gateway — Hercor College High School Department">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="Smart Gateway | Hercor College High School Department">
<meta name="twitter:description" content="<?= e($description) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<link rel="icon" type="image/png" href="favicon-96x96.png" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="shortcut icon" href="favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebApplication',
            '@id' => $canonical . '#application',
            'name' => 'Smart Gateway',
            'url' => $canonical,
            'description' => $description,
            'applicationCategory' => 'SecurityApplication',
            'operatingSystem' => 'Web',
            'publisher' => [
                '@type' => 'EducationalOrganization',
                'name' => 'Hercor College High School Department'
            ]
        ],
        [
            '@type' => 'WebSite',
            '@id' => $canonical . '#website',
            'url' => $canonical,
            'name' => 'Smart Gateway — Hercor College High School Department',
            'description' => $description,
            'inLanguage' => 'en-PH'
        ]
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>

<style>
:root{color-scheme:dark;--bg:#061a2b;--panel:#08253a;--panel2:#0b3048;--line:#174d68;--accent:#20c4e8;--text:#f3f8fb;--muted:#9eb6c5}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}
body{overflow-x:hidden}
.sg-public{min-height:100vh;display:flex;flex-direction:column}
.sg-nav{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:22px 0;display:flex;align-items:center;justify-content:space-between;gap:20px}
.sg-brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}.sg-brand img{width:46px;height:46px;object-fit:contain}.sg-brand strong{display:block;font-size:15px;letter-spacing:.02em}.sg-brand span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.08em}
.sg-login{display:inline-flex;align-items:center;gap:8px;padding:10px 17px;border:1px solid #21617e;border-radius:10px;color:var(--text);text-decoration:none;background:#0b3048;transition:.2s}.sg-login:hover{border-color:var(--accent);transform:translateY(-1px)}
.sg-hero{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:70px 0 60px;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);align-items:center;gap:70px;flex:1}
.sg-eyebrow{display:inline-flex;align-items:center;gap:8px;color:#7de7f7;font-size:12px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}.sg-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 18px #20c4e8}
h1{font-size:clamp(42px,6vw,72px);line-height:.98;letter-spacing:-.045em;margin:18px 0 22px;max-width:780px}h1 span{display:block;color:#82dff0}.sg-copy{font-size:18px;line-height:1.7;color:var(--muted);max-width:700px;margin:0 0 28px}.sg-actions{display:flex;flex-wrap:wrap;gap:12px}.sg-primary{display:inline-flex;align-items:center;gap:9px;padding:13px 20px;border-radius:10px;background:var(--accent);color:#052033;font-weight:800;text-decoration:none}.sg-secondary{display:inline-flex;align-items:center;gap:9px;padding:13px 20px;border-radius:10px;border:1px solid var(--line);color:var(--text);text-decoration:none;background:#08253a}
.sg-card{background:linear-gradient(145deg,#0b3048,#071e32);border:1px solid var(--line);border-radius:20px;padding:26px;box-shadow:0 24px 70px rgba(0,0,0,.25)}.sg-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}.sg-card-title{font-weight:800}.sg-status{font-size:11px;color:#89e5ae;background:#103d35;border:1px solid #226b58;border-radius:999px;padding:5px 9px}.sg-flow{display:grid;gap:12px}.sg-flow-item{display:flex;align-items:center;gap:13px;padding:14px;border:1px solid #16465e;border-radius:12px;background:#08273c}.sg-flow-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;background:#0e3a53;color:#6ee7f8;font-size:18px}.sg-flow-item strong{display:block;font-size:13px}.sg-flow-item small{display:block;color:var(--muted);margin-top:2px}.sg-arrow{text-align:center;color:#527d91;font-size:13px}
.sg-features{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:10px 0 60px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.sg-feature{border-top:1px solid var(--line);padding:20px 4px}.sg-feature i{font-size:23px;color:var(--accent)}.sg-feature h2{font-size:17px;margin:10px 0 7px}.sg-feature p{color:var(--muted);font-size:14px;line-height:1.6;margin:0}
.sg-footer{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:22px 0 30px;border-top:1px solid #12394e;color:#7894a4;font-size:12px;display:flex;justify-content:space-between;gap:20px}
@media(max-width:850px){.sg-hero{grid-template-columns:1fr;gap:38px;padding-top:45px}.sg-card{max-width:620px}.sg-features{grid-template-columns:1fr}.sg-footer{flex-direction:column}.sg-copy{font-size:16px}}
@media(max-width:520px){.sg-nav{width:calc(100% - 24px);padding:15px 0}.sg-brand span{display:none}.sg-login{padding:9px 12px}.sg-hero,.sg-features,.sg-footer{width:calc(100% - 24px)}.sg-hero{padding:35px 0 45px}h1{font-size:42px}.sg-copy{font-size:15px}.sg-actions>*{width:100%;justify-content:center}.sg-card{padding:18px;border-radius:16px}}
</style>
</head>
<body>
<div class="sg-public">
    <header class="sg-nav">
        <a class="sg-brand" href="/" aria-label="Smart Gateway home">
            <img src="web-app-manifest-192x192.png" alt="Smart Gateway logo">
            <span><strong>Hercor College</strong>High School Department</span>
        </a>
        <a class="sg-login" href="login.php"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign In</a>
    </header>

    <main>
        <section class="sg-hero" aria-labelledby="hero-title">
            <div>
                <div class="sg-eyebrow"><span class="sg-dot" aria-hidden="true"></span> Campus Entry Verification</div>
                <h1 id="hero-title">Smart Gateway<span>Safer campus entry.</span></h1>
                <p class="sg-copy">A secure campus entry verification system for Hercor College High School Department. Smart Gateway combines student ID barcode scanning, facial recognition, real-time access records, and SMS notification support to strengthen identity verification at the school entrance.</p>
                <div class="sg-actions">
                    <a class="sg-primary" href="login.php"><i class="bi bi-shield-lock" aria-hidden="true"></i> Access Smart Gateway</a>
                    <a class="sg-secondary" href="#features"><i class="bi bi-arrow-down" aria-hidden="true"></i> Learn More</a>
                </div>
            </div>

            <aside class="sg-card" aria-label="Smart Gateway verification process">
                <div class="sg-card-head"><span class="sg-card-title">Verification Flow</span><span class="sg-status">Secure Process</span></div>
                <div class="sg-flow">
                    <div class="sg-flow-item"><span class="sg-flow-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></span><span><strong>1. Student ID</strong><small>Scan the student barcode.</small></span></div>
                    <div class="sg-arrow"><i class="bi bi-arrow-down" aria-hidden="true"></i></div>
                    <div class="sg-flow-item"><span class="sg-flow-icon"><i class="bi bi-person-bounding-box" aria-hidden="true"></i></span><span><strong>2. Face Verification</strong><small>Confirm the student's identity.</small></span></div>
                    <div class="sg-arrow"><i class="bi bi-arrow-down" aria-hidden="true"></i></div>
                    <div class="sg-flow-item"><span class="sg-flow-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><span><strong>3. Entry Decision</strong><small>Record and monitor the result.</small></span></div>
                </div>
            </aside>
        </section>

        <section id="features" class="sg-features" aria-label="Smart Gateway features">
            <article class="sg-feature"><i class="bi bi-upc-scan" aria-hidden="true"></i><h2>Barcode Verification</h2><p>Quickly identify students using their school-issued ID barcode before entry is granted.</p></article>
            <article class="sg-feature"><i class="bi bi-person-bounding-box" aria-hidden="true"></i><h2>Facial Recognition</h2><p>Use facial verification as a secondary identity check to help reduce unauthorized ID use.</p></article>
            <article class="sg-feature"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><h2>Real-Time Monitoring</h2><p>Maintain searchable entry and exit records and provide operational visibility for authorized staff.</p></article>
        </section>
    </main>

    <footer class="sg-footer">
        <span>&copy; <?= date('Y') ?> Smart Gateway — Hercor College High School Department</span>
        <span>Campus Entry Verification System</span>
    </footer>
</div>
</body>
</html>
