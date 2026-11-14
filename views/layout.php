<?php
$meta = ($meta ?? []) + [
    'title'       => 'Gatepass: sell tickets to your events',
    'description' => 'Create an event, sell tickets with Paystack, and let the door scanner do the rest. Buyers get QR tickets by email with a reminder for their phone.',
    'url'         => base_url() . ($_SERVER['REQUEST_URI'] ?? '/'),
    'image'       => base_url() . '/assets/og.png',
];
$bodyClass = $bodyClass ?? '';
$plain = $plain ?? false;   // full-screen pages (scanner) skip the footer
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($meta['title']) ?></title>
    <meta name="description" content="<?= e($meta['description']) ?>">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Gatepass">
    <meta property="og:title" content="<?= e($meta['title']) ?>">
    <meta property="og:description" content="<?= e($meta['description']) ?>">
    <meta property="og:url" content="<?= e($meta['url']) ?>">
    <meta property="og:image" content="<?= e($meta['image']) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= e($meta['image']) ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="/assets/fonts/InterDisplay-ExtraBold.woff" as="font" type="font/woff" crossorigin>
    <link rel="stylesheet" href="/assets/app.css?v=1">
    <script src="/assets/app.js?v=1" defer></script>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="top">
    <div class="wrap top-row">
        <a class="logo" href="/" aria-label="Gatepass home">
            <svg viewBox="0 0 28 20" width="28" height="20" aria-hidden="true"><path d="M3 1h22a2 2 0 0 1 2 2v4a3 3 0 0 0 0 6v4a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-4a3 3 0 0 0 0-6V3a2 2 0 0 1 2-2z" fill="#E8483B"/><path d="M18 4v12" stroke="#fff" stroke-width="1.6" stroke-dasharray="2 2"/></svg>
            Gatepass
        </a>
        <nav aria-label="Main">
            <a href="/explore" class="hide-sm">Explore</a>
            <?php if ($me): ?>
                <a href="/dashboard">Dashboard</a>
                <a href="/@<?= e($me['username']) ?>" class="hide-sm">My page</a>
                <form method="post" action="/logout" class="inline hide-sm"><?= csrf_field() ?><button class="link-btn">Log out</button></form>
                <a class="btn btn-sm" href="/events/new">New event</a>
            <?php else: ?>
                <a href="/login">Log in</a>
                <a class="btn btn-sm" href="/signup">Start selling</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main id="main">
    <?php if ($flash): ?>
        <div class="wrap"><p class="flash" role="status"><?= e($flash) ?></p></div>
    <?php endif; ?>
    <?= $content ?>
</main>

<?php if (!$plain): ?>
<footer class="foot">
    <div class="wrap foot-row">
        <p>Gatepass is built by <a href="https://ejikepius.dev"><?= e(config('creator')) ?></a> as Day 4 of The Daily Ship. Follow on X: <a href="https://x.com/<?= e(config('creator_x')) ?>">@<?= e(config('creator_x')) ?></a></p>
        <p><a href="/explore">Explore events</a> · <a href="<?= e(config('github_url')) ?>">Source on GitHub</a></p>
    </div>
</footer>
<?php endif; ?>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
