<?php /** @var array $owner @var array $events */ ?>
<section class="wrap org-head">
    <div class="avatar" style="--h:<?= crc32($owner['username']) % 360 ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($owner['name'], 0, 1))) ?></div>
    <div>
        <h1><?= e($owner['name']) ?></h1>
        <p class="muted">@<?= e($owner['username']) ?> · Hosting on Gatepass since <?= e(date('M Y', strtotime($owner['created_at'] . ' UTC'))) ?></p>
        <?php if ($owner['bio'] !== ''): ?><p class="org-bio"><?= e($owner['bio']) ?></p><?php endif; ?>
    </div>
    <button type="button" class="btn btn-sm btn-line org-share" data-copy="<?= e(base_url() . '/@' . $owner['username']) ?>">Copy link</button>
</section>

<section class="wrap">
    <h2 class="section-title">Upcoming events</h2>
    <?php if ($events): ?>
        <div class="cards">
            <?php foreach ($events as $ev) { $c = event_card_json($ev); require ROOT . '/views/_card.php'; } ?>
        </div>
    <?php else: ?>
        <div class="empty"><p><strong>No upcoming events right now.</strong></p><p class="muted">Check back soon.</p></div>
    <?php endif; ?>
</section>
