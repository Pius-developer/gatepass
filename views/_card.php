<?php /** @var array $c from event_card_json() */ ?>
<a class="card" href="<?= e($c['url']) ?>">
    <div class="card-cover"<?= $c['cover'] ? '' : ' style="--h:' . (int) $c['hue'] . '"' ?>>
        <?php if ($c['cover']): ?><img src="<?= e($c['cover']) ?>" alt="" loading="lazy"><?php else: ?><span class="card-mono" aria-hidden="true"><?= e(mb_substr($c['title'], 0, 1)) ?></span><?php endif; ?>
        <span class="date-chip"><b><?= e($c['day']) ?></b><?= e($c['month']) ?></span>
    </div>
    <div class="card-body">
        <h3><?= e($c['title']) ?></h3>
        <p class="muted small"><?= e($c['when']) ?></p>
        <p class="muted small card-venue"><?= e($c['venue']) ?></p>
        <p class="card-foot">
            <span class="small">by <?= e($c['host']) ?></span>
            <?php if ($c['sold_out']): ?><span class="tag tag-dark">Sold out</span><?php elseif ($c['price']): ?><span class="tag"><?= e($c['price']) ?></span><?php endif; ?>
        </p>
    </div>
</a>
