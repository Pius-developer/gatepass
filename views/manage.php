<?php
/** @var array $event @var array $types @var array $stats @var array $attendees @var array $sales */
$id = (int) $event['id'];
$phase = event_phase($event);
$url = base_url() . '/e/' . $event['slug'];
$capacity = array_sum(array_column($types, 'quantity'));
?>
<section class="wrap page-head">
    <p class="crumbs"><a href="/dashboard">Dashboard</a> / Event</p>
    <div class="manage-head">
        <div>
            <h1><?= e($event['title']) ?> <?= status_tag($event['status'], $phase) ?></h1>
            <p class="muted"><?= e(event_when($event)) ?> · <?= e($event['venue'] !== '' ? $event['venue'] : 'Online') ?></p>
        </div>
        <div class="actions">
            <a class="btn btn-sm" href="/events/<?= $id ?>/checkin">Open scanner</a>
            <a class="btn btn-sm btn-line" href="/events/<?= $id ?>/edit">Edit</a>
            <a class="btn btn-sm btn-line" href="/e/<?= e($event['slug']) ?>"><?= $event['status'] === 'draft' ? 'Preview' : 'View page' ?></a>
        </div>
    </div>
</section>

<?php if ($event['status'] !== 'cancelled'): ?>
<section class="wrap">
    <div class="sharebox">
        <div>
            <p class="small muted">Your ticket link</p>
            <p class="sharebox-url"><?= e($url) ?></p>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-sm" data-copy="<?= e($url) ?>">Copy link</button>
            <button type="button" class="btn btn-sm btn-line" data-share data-url="<?= e($url) ?>" data-title="<?= e($event['title']) ?>">Share</button>
            <button type="button" class="btn btn-sm btn-line" data-qr-toggle aria-expanded="false">QR poster</button>
        </div>
        <div class="poster-qr" hidden data-qr-panel>
            <?= Qr::svg($url, 2) ?>
            <p class="small muted">Print this on flyers or put it on a screen. Scanning it opens the ticket page.</p>
        </div>
    </div>
    <?php if ($event['status'] === 'draft'): ?>
        <p class="flash">This event is a draft: nobody else can see it yet.
            <form method="post" action="/events/<?= $id ?>/publish" class="inline"><?= csrf_field() ?><button class="link-btn strong">Publish it now</button></form></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="wrap stats">
    <div class="stat"><p>Tickets sold</p><strong><?= number_format($stats['tickets']) ?><small> / <?= number_format($capacity) ?></small></strong></div>
    <div class="stat"><p>Revenue</p><strong><?= e(money($stats['revenue'], false)) ?></strong></div>
    <div class="stat"><p>Orders</p><strong><?= number_format($stats['orders']) ?></strong></div>
    <div class="stat"><p>Checked in</p><strong><?= number_format($stats['checked_in']) ?><small> / <?= number_format($stats['tickets']) ?></small></strong></div>
</section>

<section class="wrap dash-grid">
    <div class="panel">
        <div class="panel-head"><h2>Sales, last 14 days</h2></div>
        <div class="chart" data-chart='<?= e(json_encode($sales)) ?>' data-symbol="<?= e(config('currency_symbol')) ?>">
            <canvas aria-label="Bar chart of daily ticket sales for this event" role="img"></canvas>
            <div class="chart-tip" hidden></div>
        </div>
    </div>
    <div class="panel">
        <h2>Ticket types</h2>
        <ul class="type-list">
            <?php foreach ($types as $t): $pct = $t['quantity'] ? min(100, round($t['sold'] / $t['quantity'] * 100)) : 0; ?>
                <li>
                    <div class="tl-row"><strong><?= e($t['name']) ?></strong><span class="num"><?= e(money($t['price'])) ?></span></div>
                    <div class="meter"><span style="width:<?= $pct ?>%"></span></div>
                    <p class="small muted"><?= number_format($t['sold']) ?> sold<?= $t['held'] ? ' · ' . (int) $t['held'] . ' in checkout' : '' ?> · <?= number_format($t['left']) ?> left</p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="wrap">
    <div class="section-head">
        <h2>Guest list <span class="muted">(<?= count($attendees) ?>)</span></h2>
        <?php if ($attendees): ?><a class="btn btn-sm btn-line" href="/events/<?= $id ?>/attendees.csv">Download CSV</a><?php endif; ?>
    </div>
    <?php if (!$attendees): ?>
        <div class="empty"><p class="muted">No tickets sold yet. Share your link to get started.</p></div>
    <?php else: ?>
        <div class="table-tools">
            <input type="search" placeholder="Search name, email or code" data-table-search="guests" aria-label="Search guests">
            <div class="seg seg-sm" role="radiogroup" aria-label="Show">
                <label><input type="radio" name="gfilter" value="all" checked data-table-filter="guests"><span>All</span></label>
                <label><input type="radio" name="gfilter" value="in" data-table-filter="guests"><span>Checked in</span></label>
                <label><input type="radio" name="gfilter" value="out" data-table-filter="guests"><span>Not yet</span></label>
            </div>
        </div>
        <div class="table-wrap">
        <table class="table" id="guests" data-sortable>
            <thead><tr>
                <th data-sort="text">Name</th><th data-sort="text">Ticket</th><th data-sort="text">Code</th>
                <th data-sort="num" class="right">Paid</th><th data-sort="text">Bought</th><th data-sort="text">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($attendees as $a): ?>
                <tr data-in="<?= $a['checked_in_at'] ? 1 : 0 ?>">
                    <td data-v="<?= e(mb_strtolower($a['buyer_name'])) ?>"><strong><?= e($a['buyer_name']) ?></strong><br><span class="small muted"><?= e($a['buyer_email']) ?></span></td>
                    <td><?= e($a['type_name']) ?></td>
                    <td class="mono"><?= e($a['code']) ?></td>
                    <td class="right num" data-v="<?= (int) $a['price'] ?>"><?= e(money($a['price'])) ?></td>
                    <td data-v="<?= e($a['paid_at']) ?>" class="small"><?= e(local_time($a['paid_at'], $event['timezone'], 'j M, g:i A')) ?></td>
                    <td data-v="<?= e($a['checked_in_at'] ?? '') ?>"><?= $a['checked_in_at'] ? '<span class="tag tag-green">In · ' . e(local_time($a['checked_in_at'], $event['timezone'], 'g:i A')) . '</span>' : '<span class="tag tag-grey">Not yet</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="small muted" data-table-count="guests"></p>
    <?php endif; ?>
</section>

<section class="wrap danger">
    <h2>Event settings</h2>
    <div class="danger-row">
        <?php if ($event['status'] === 'published'): ?>
            <form method="post" action="/events/<?= $id ?>/unpublish"><?= csrf_field() ?><button class="btn btn-sm btn-line">Unpublish (pause sales)</button></form>
        <?php elseif ($event['status'] === 'draft'): ?>
            <form method="post" action="/events/<?= $id ?>/publish"><?= csrf_field() ?><button class="btn btn-sm">Publish</button></form>
        <?php endif; ?>
        <?php if ($event['status'] !== 'cancelled'): ?>
            <form method="post" action="/events/<?= $id ?>/cancel" data-confirm="Cancel this event? Everyone with a ticket gets an email. This can't be undone."><?= csrf_field() ?><button class="btn btn-sm btn-danger">Cancel event</button></form>
        <?php endif; ?>
        <?php if (!$stats['orders']): ?>
            <form method="post" action="/events/<?= $id ?>/delete" data-confirm="Delete this event for good?"><?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button></form>
        <?php endif; ?>
    </div>
</section>
