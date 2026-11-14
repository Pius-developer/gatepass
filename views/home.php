<?php /** @var array $events */ ?>
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <p class="eyebrow">Tickets for your events</p>
            <h1>Sell tickets.<br>Scan them at the gate.<br><span class="hl">That's it.</span></h1>
            <p class="lede">Create an event in two minutes, share your link and get paid with Paystack. Buyers get QR tickets by email with a reminder for their phone. On the day, your phone becomes the door scanner.</p>
            <div class="actions">
                <a class="btn btn-lg" href="<?= $me ? '/events/new' : '/signup' ?>">Create your event</a>
                <a class="btn btn-lg btn-line" href="/explore">Find events</a>
            </div>
            <p class="small muted hero-note">Free events are free. No app to install, for you or your guests.</p>
        </div>

        <figure class="ticket-demo" aria-label="Example ticket">
            <div class="td-main">
                <p class="td-eyebrow">Admit one · VIP</p>
                <p class="td-title">Lagos Builders Meetup</p>
                <dl class="td-meta">
                    <div><dt>Date</dt><dd>Sat 5 Dec</dd></div>
                    <div><dt>Time</dt><dd>4:00 PM</dd></div>
                    <div><dt>Venue</dt><dd>Yaba, Lagos</dd></div>
                </dl>
                <p class="td-code">K7Q2-M9XD-4TPA</p>
            </div>
            <div class="td-stub">
                <div class="td-qr"><?= Qr::svg('DEMO-GATEPASS', 1) ?></div>
                <p>Scan at the gate</p>
            </div>
        </figure>
    </div>
</section>

<section class="wrap how">
    <h2>How it works</h2>
    <ol class="steps">
        <li><h3>Create</h3><p>Add the date, venue or online link, a cover photo and your ticket types: Regular, VIP, Free, up to six.</p></li>
        <li><h3>Share</h3><p>Every event gets its own page, and you get <code>/@yourname</code> with all your events. Post the link anywhere.</p></li>
        <li><h3>Sell</h3><p>Buyers pay with Paystack. Tickets are held while they pay, so you never sell more than you have.</p></li>
        <li><h3>Scan</h3><p>Open the scanner on your phone at the door. Each QR ticket works once; copies are caught instantly.</p></li>
    </ol>
</section>

<?php if ($events): ?>
<section class="wrap upcoming">
    <div class="section-head">
        <h2>Happening soon</h2>
        <a href="/explore">See all events →</a>
    </div>
    <div class="cards">
        <?php foreach ($events as $ev) { $c = event_card_json($ev); require ROOT . '/views/_card.php'; } ?>
    </div>
</section>
<?php endif; ?>

<section class="wrap features">
    <div class="feature"><h3>Tickets by email</h3><p>Each ticket has its own QR code, and the email comes with a calendar file. One tap adds the event to their phone with reminders the day before and an hour before.</p></div>
    <div class="feature"><h3>Your dashboard</h3><p>Sales over time, revenue, who's coming and who's arrived. Download your guest list as a spreadsheet whenever you need it.</p></div>
    <div class="feature"><h3>Built to be safe</h3><p>Payments are confirmed with Paystack before any ticket exists. Ticket links are secret, and scans are checked against the right event.</p></div>
</section>
