<?php /** @var array $events @var string $q @var string $when @var string $price */ ?>
<section class="wrap page-head">
    <h1>Explore events</h1>
    <p class="muted">Everything coming up on Gatepass, from every organizer.</p>
</section>

<section class="wrap">
    <form class="filters" action="/explore" method="get" data-explore role="search">
        <label class="search">
            <span class="sr">Search events</span>
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="8.5" cy="8.5" r="6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M13 13l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, place or organizer" autocomplete="off">
        </label>
        <div class="seg" role="radiogroup" aria-label="When">
            <?php foreach (['' => 'Any time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $v => $label): ?>
                <label><input type="radio" name="when" value="<?= $v ?>" <?= $when === $v ? 'checked' : '' ?>><span><?= $label ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="seg" role="radiogroup" aria-label="Price">
            <?php foreach (['' => 'All', 'free' => 'Free', 'paid' => 'Paid'] as $v => $label): ?>
                <label><input type="radio" name="price" value="<?= $v ?>" <?= $price === $v ? 'checked' : '' ?>><span><?= $label ?></span></label>
            <?php endforeach; ?>
        </div>
        <noscript><button class="btn btn-sm">Search</button></noscript>
    </form>

    <p class="small muted results-count" data-count aria-live="polite"><?= plural(count($events), 'event') ?></p>
    <div class="cards" data-results>
        <?php foreach ($events as $ev) { $c = event_card_json($ev); require ROOT . '/views/_card.php'; } ?>
    </div>
    <div class="empty" data-empty <?= $events ? 'hidden' : '' ?>>
        <p><strong>No events match.</strong></p>
        <p class="muted">Try another search, or <a href="<?= $me ? '/events/new' : '/signup' ?>">host your own</a>.</p>
    </div>
</section>

<template id="card-tpl">
    <a class="card" href="">
        <div class="card-cover"><span class="date-chip"><b></b></span></div>
        <div class="card-body">
            <h3></h3>
            <p class="muted small" data-f="when"></p>
            <p class="muted small card-venue" data-f="venue"></p>
            <p class="card-foot"><span class="small" data-f="host"></span><span class="tag" data-f="price"></span></p>
        </div>
    </a>
</template>
