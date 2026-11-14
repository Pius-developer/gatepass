<?php /** @var array $order @var array $event @var array $items */ ?>
<section class="wrap auth">
    <p class="eyebrow">Demo checkout</p>
    <h1>Pay <?= e(money($order['total'])) ?></h1>
    <p class="muted">Paystack keys aren't set up on this site yet, so this page stands in for the real checkout. <strong>No money is taken.</strong></p>
    <div class="panel receipt">
        <p><strong><?= e($event['title']) ?></strong></p>
        <p class="small muted"><?= e(event_when($event)) ?></p>
        <ul>
            <?php foreach ($items as $it): ?>
                <li><span><?= (int) $it['quantity'] ?> × <?= e($it['name']) ?></span><span><?= e(money($it['price'] * $it['quantity'])) ?></span></li>
            <?php endforeach; ?>
            <li class="sum"><span>Total</span><span><?= e(money($order['total'])) ?></span></li>
        </ul>
        <p class="small muted">Tickets held for you until <span data-hold="<?= e(str_replace(' ', 'T', $order['expires_at'])) ?>Z"><?= e($order['expires_at']) ?> UTC</span>.</p>
    </div>
    <form method="post" action="/pay/demo/<?= e($order['reference']) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-block btn-lg">Complete demo payment</button>
    </form>
    <p class="small center"><a href="/e/<?= e($event['slug']) ?>">Cancel</a></p>
</section>
