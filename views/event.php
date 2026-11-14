<?php
/** @var array $event @var array $types @var array $form @var ?string $error @var bool $isOwner */
$phase = event_phase($event);
$selling = $event['status'] === 'published' && in_array($phase, ['upcoming', 'live'], true);
$left = array_sum(array_column($types, 'left'));
$url = base_url() . '/e/' . $event['slug'];
?>
<?php if ($event['status'] === 'draft'): ?>
    <div class="wrap"><p class="flash">This is a preview of your draft. Only you can see it. <a href="/events/<?= (int) $event['id'] ?>">Manage event</a></p></div>
<?php elseif ($isOwner): ?>
    <div class="wrap"><p class="flash">You're the organizer. <a href="/events/<?= (int) $event['id'] ?>">Manage this event</a> · <a href="/events/<?= (int) $event['id'] ?>/checkin">Open the scanner</a></p></div>
<?php endif; ?>

<?php if ($selling && $left > 0): ?><?php $from = min(array_column(array_filter($types, fn ($t) => $t['left'] > 0), 'price')); ?>
    <a class="mobile-cta btn btn-lg" href="#tickets">Get tickets<?= $from > 0 ? ' · from ' . e(money($from)) : '' ?></a><?php endif; ?>
<article class="wrap event-page">
    <div class="ep-cover"<?= $event['cover'] ? '' : ' style="--h:' . crc32($event['title']) % 360 . '"' ?>>
        <?php if ($event['cover']): ?><img src="<?= e(cover_url($event['cover'])) ?>" alt=""><?php else: ?><span class="card-mono" aria-hidden="true"><?= e(mb_substr($event['title'], 0, 1)) ?></span><?php endif; ?>
    </div>

    <div class="ep-grid">
        <div class="ep-main">
            <?php if ($phase === 'cancelled'): ?><p class="tag tag-red">Cancelled</p>
            <?php elseif ($phase === 'past'): ?><p class="tag tag-dark">This event has ended</p>
            <?php elseif ($phase === 'live'): ?><p class="tag tag-live">Happening now</p><?php endif; ?>

            <h1><?= e($event['title']) ?></h1>
            <p class="host">Hosted by <a href="/@<?= e($event['username']) ?>"><?= e($event['host_name']) ?></a></p>

            <ul class="facts">
                <li>
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <div>
                        <strong><?= e(event_when($event)) ?></strong>
                        <span class="small">
                            <a href="/e/<?= e($event['slug']) ?>/calendar.ics">Add to calendar</a> ·
                            <a href="<?= e(Ics::googleLink($event, $url)) ?>" target="_blank" rel="noopener">Google Calendar</a>
                        </span>
                    </div>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9.5" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    <div>
                        <strong><?= e($event['venue'] !== '' ? $event['venue'] : 'Online event') ?></strong>
                        <span class="small">
                            <?php if ($event['venue'] !== ''): ?><a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($event['venue']) ?>" target="_blank" rel="noopener">Open in Maps</a><?php endif; ?>
                            <?php if ($event['online_url'] !== ''): ?><?= $event['venue'] !== '' ? ' · ' : '' ?>Also online; the link comes with your ticket<?php endif; ?>
                        </span>
                    </div>
                </li>
            </ul>

            <?php if ($phase === 'upcoming'): ?>
                <div class="countdown" data-countdown="<?= e(str_replace(' ', 'T', $event['starts_at'])) ?>Z" aria-label="Time until the event starts">
                    <div><b data-u="d">–</b><span>days</span></div>
                    <div><b data-u="h">–</b><span>hours</span></div>
                    <div><b data-u="m">–</b><span>mins</span></div>
                    <div><b data-u="s">–</b><span>secs</span></div>
                </div>
            <?php endif; ?>

            <?php if (trim($event['description']) !== ''): ?>
                <h2 class="ep-h2">About this event</h2>
                <div class="prose"><?= paragraphs($event['description']) ?></div>
            <?php endif; ?>

            <div class="share">
                <button type="button" class="btn btn-sm btn-line" data-share data-url="<?= e($url) ?>" data-title="<?= e($event['title']) ?>">Share</button>
                <button type="button" class="btn btn-sm btn-line" data-copy="<?= e($url) ?>">Copy link</button>
                <a class="btn btn-sm btn-line" href="https://x.com/intent/post?<?= e(http_build_query(['text' => 'I\'m going to ' . $event['title'] . ' 🎟️', 'url' => $url])) ?>" target="_blank" rel="noopener">Post on X</a>
                <a class="btn btn-sm btn-line" href="https://wa.me/?<?= e(http_build_query(['text' => $event['title'] . ' ' . $url])) ?>" target="_blank" rel="noopener">WhatsApp</a>
            </div>
        </div>

        <aside class="ep-side">
            <form class="buy panel sticky" id="tickets" method="post" action="/e/<?= e($event['slug']) ?>/checkout" data-buy data-max="<?= (int) config('max_per_order') ?>" data-symbol="<?= e(config('currency_symbol')) ?>">
                <?= csrf_field() ?>
                <h2>Tickets</h2>
                <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>

                <?php if (!$selling): ?>
                    <p class="muted"><?= $phase === 'cancelled' ? 'This event was cancelled.' : ($phase === 'past' ? 'Ticket sales have closed.' : 'Tickets aren\'t on sale yet.') ?></p>
                <?php elseif ($left === 0): ?>
                    <p class="soldout">Sold out</p>
                    <p class="small muted">Tickets held by unfinished checkouts are released after <?= (int) config('hold_minutes') ?> minutes. Check back soon.</p>
                <?php else: ?>
                    <ul class="tiers">
                        <?php foreach ($types as $t): $q = (int) ($form['qty'][$t['id']] ?? 0); ?>
                            <li class="tier<?= $t['left'] ? '' : ' is-out' ?>">
                                <div>
                                    <p class="tier-name"><?= e($t['name']) ?></p>
                                    <p class="tier-price"><?= e(money($t['price'])) ?></p>
                                    <?php if ($t['left'] === 0): ?><p class="small muted">Sold out</p>
                                    <?php elseif ($t['left'] <= 10): ?><p class="small hot">Only <?= (int) $t['left'] ?> left</p><?php endif; ?>
                                </div>
                                <?php if ($t['left'] > 0): ?>
                                    <div class="stepper" data-stepper data-price="<?= (int) $t['price'] ?>" data-left="<?= (int) $t['left'] ?>">
                                        <button type="button" data-step="-1" aria-label="One fewer <?= e($t['name']) ?>">−</button>
                                        <input name="qty[<?= (int) $t['id'] ?>]" value="<?= $q ?>" inputmode="numeric" aria-label="Number of <?= e($t['name']) ?> tickets">
                                        <button type="button" data-step="1" aria-label="One more <?= e($t['name']) ?>">+</button>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="buyer" data-buyer>
                        <div class="field">
                            <label for="buyer-name">Full name</label>
                            <input id="buyer-name" name="name" value="<?= e($form['name']) ?>" maxlength="80" required autocomplete="name">
                        </div>
                        <div class="field">
                            <label for="buyer-email">Email</label>
                            <input id="buyer-email" name="email" type="email" value="<?= e($form['email']) ?>" required autocomplete="email">
                            <p class="hint">Your tickets are sent here.</p>
                        </div>
                    </div>

                    <div class="total"><span>Total</span><strong data-total>–</strong></div>
                    <button class="btn btn-block btn-lg" data-buy-btn disabled>Choose tickets</button>
                    <p class="small muted center secure">🔒 <?= Paystack::fromConfig() ? 'Secure payment by Paystack' : 'Demo mode: no real money is taken' ?></p>
                <?php endif; ?>
            </form>
        </aside>
    </div>
</article>
