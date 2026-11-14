<?php
/** @var array $order @var array $event @var array $tickets @var array $items @var bool $isNew */
$base = '/tickets/' . $order['reference'] . '/' . $order['access_key'];
?>
<section class="wrap tickets-page">
    <?php if ($order['status'] === 'paid'): ?>
        <div class="tp-head">
            <?php if ($isNew): ?><div class="confetti" data-confetti aria-hidden="true"></div><?php endif; ?>
            <p class="eyebrow"><?= $isNew ? 'You\'re going!' : 'Your tickets' ?></p>
            <h1><?= e($event['title']) ?></h1>
            <p class="muted"><?= e(event_when($event)) ?> · <?= e($event['venue'] !== '' ? $event['venue'] : 'Online') ?></p>
            <?php if ($isNew): ?>
                <p class="small">We've emailed your tickets to <strong><?= e($order['buyer_email']) ?></strong>. This page has them too, so you can bookmark it.</p>
            <?php endif; ?>
            <div class="actions">
                <a class="btn" href="<?= e($base) ?>/event.ics">📅 Add to my phone's calendar</a>
                <a class="btn btn-line" href="<?= e(Ics::googleLink($event, ticket_url($order))) ?>" target="_blank" rel="noopener">Google Calendar</a>
                <button type="button" class="btn btn-line" onclick="window.print()">Print</button>
            </div>
            <?php if ($event['online_url'] !== ''): ?>
                <p class="online-link">Join online: <a href="<?= e($event['online_url']) ?>" target="_blank" rel="noopener"><?= e($event['online_url']) ?></a></p>
            <?php endif; ?>
            <?php if ($event['status'] === 'cancelled'): ?><p class="alert">This event was cancelled by the organizer.</p><?php endif; ?>
        </div>

        <div class="stubs">
            <?php foreach ($tickets as $i => $t): ?>
                <div class="stub<?= $t['checked_in_at'] ? ' is-used' : '' ?>">
                    <div class="stub-main">
                        <p class="td-eyebrow">Ticket <?= $i + 1 ?> of <?= count($tickets) ?> · <?= e($t['type_name']) ?></p>
                        <p class="stub-title"><?= e($event['title']) ?></p>
                        <p class="small"><?= e($order['buyer_name']) ?></p>
                        <p class="td-code"><?= e($t['code']) ?></p>
                        <?php if ($t['checked_in_at']): ?><p class="small used">Checked in <?= e(local_time($t['checked_in_at'], $event['timezone'], 'j M, g:i A')) ?></p><?php endif; ?>
                    </div>
                    <div class="stub-qr"><img src="<?= e($base . '/qr/' . $t['code'] . '.svg') ?>" alt="QR code for ticket <?= e($t['code']) ?>" width="150" height="150"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small muted center">Order <?= e($order['reference']) ?> · <?= e(money($order['total'])) ?> · Each ticket can be scanned once. Don't post it online.</p>

    <?php elseif ($order['status'] === 'pending'): ?>
        <div class="tp-head center">
            <p class="eyebrow">Waiting for payment</p>
            <h1><?= e($event['title']) ?></h1>
            <p class="muted">We haven't received a confirmed payment for order <?= e($order['reference']) ?> yet. If you've just paid, this page will update in a moment.</p>
            <p class="small muted" data-auto-refresh="8">Checking again shortly…</p>
            <p><a href="/e/<?= e($event['slug']) ?>">Back to the event</a></p>
        </div>
    <?php else: ?>
        <div class="tp-head center">
            <p class="eyebrow">Checkout expired</p>
            <h1><?= e($event['title']) ?></h1>
            <p class="muted">This checkout wasn't paid in time, so the tickets were released. No money was taken.</p>
            <a class="btn" href="/e/<?= e($event['slug']) ?>">Try again</a>
        </div>
    <?php endif; ?>
</section>
