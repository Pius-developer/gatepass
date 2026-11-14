<?php
/** @var array $list @var array $sales @var array $totals @var array $recent */
$link = base_url() . '/@' . $me['username'];
?>
<section class="wrap page-head dash-head">
    <div>
        <h1>Hi, <?= e($me['name']) ?></h1>
        <p class="muted">Your page: <a href="/@<?= e($me['username']) ?>"><?= e(host()) ?>/@<?= e($me['username']) ?></a>
            <button type="button" class="link-btn small" data-copy="<?= e($link) ?>">Copy</button></p>
    </div>
    <div class="actions"><a class="btn btn-line btn-sm" href="/settings">Profile</a><a class="btn btn-sm" href="/events/new">New event</a></div>
</section>

<section class="wrap stats">
    <div class="stat"><p>Revenue</p><strong data-count-up="<?= (int) $totals['revenue'] ?>" data-money data-symbol="<?= e(config('currency_symbol')) ?>"><?= e(money($totals['revenue'], false)) ?></strong></div>
    <div class="stat"><p>Tickets sold</p><strong data-count-up="<?= (int) $totals['sold'] ?>"><?= number_format($totals['sold']) ?></strong></div>
    <div class="stat"><p>Last 30 days</p><strong data-count-up="<?= (int) $totals['last30'] ?>"><?= number_format($totals['last30']) ?></strong></div>
    <div class="stat"><p>Upcoming events</p><strong><?= (int) $totals['upcoming'] ?></strong></div>
</section>

<section class="wrap dash-grid">
    <div class="panel">
        <div class="panel-head">
            <h2>Sales, last 30 days</h2>
            <div class="seg seg-sm" role="radiogroup" aria-label="Chart shows">
                <label><input type="radio" name="metric" value="tickets" checked data-metric><span>Tickets</span></label>
                <label><input type="radio" name="metric" value="revenue" data-metric><span>Revenue</span></label>
            </div>
        </div>
        <div class="chart" data-chart='<?= e(json_encode($sales)) ?>' data-symbol="<?= e(config('currency_symbol')) ?>">
            <canvas aria-label="Bar chart of daily ticket sales" role="img"></canvas>
            <div class="chart-tip" hidden></div>
        </div>
    </div>
    <div class="panel">
        <h2>Latest orders</h2>
        <?php if ($recent): ?>
            <ul class="feed">
                <?php foreach ($recent as $r): ?>
                    <li>
                        <div><strong><?= e($r['buyer_name']) ?></strong> got <?= plural((int) $r['tickets'], 'ticket') ?><br>
                            <a class="small" href="/events/<?= (int) $r['event_id'] ?>"><?= e($r['title']) ?></a></div>
                        <div class="right"><span class="num"><?= e(money($r['total'])) ?></span><br><span class="small muted"><?= e(ago($r['paid_at'])) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted small">Orders show up here as they come in.</p>
        <?php endif; ?>
    </div>
</section>

<section class="wrap">
    <h2 class="section-title">Your events</h2>
    <?php if (!$list): ?>
        <div class="empty">
            <p><strong>No events yet.</strong></p>
            <p class="muted">Create one, share the link, and sales show up here.</p>
            <p><a class="btn" href="/events/new">Create your first event</a></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Event</th><th>Status</th><th class="right">Sold</th><th class="right">Revenue</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $ev): $phase = event_phase($ev); $pct = $ev['capacity'] ? min(100, round($ev['sold'] / $ev['capacity'] * 100)) : 0; ?>
                <tr>
                    <td><a class="strong" href="/events/<?= (int) $ev['id'] ?>"><?= e($ev['title']) ?></a><br><span class="small muted"><?= e(event_when($ev)) ?></span></td>
                    <td><?= status_tag($ev['status'], $phase) ?></td>
                    <td class="right"><span class="num"><?= number_format($ev['sold']) ?> / <?= number_format($ev['capacity']) ?></span>
                        <div class="meter" title="<?= $pct ?>% sold"><span style="width:<?= $pct ?>%"></span></div></td>
                    <td class="right num"><?= e(money($ev['revenue'], false)) ?></td>
                    <td class="right nowrap"><a class="btn btn-sm btn-line" href="/events/<?= (int) $ev['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
