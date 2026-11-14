<?php
/** @var array $event @var array $stats */
$plain = true;
$bodyClass = 'scanner-body';
?>
<section class="scanner" data-scanner data-url="/events/<?= (int) $event['id'] ?>/checkin">
    <div class="sc-top">
        <a href="/events/<?= (int) $event['id'] ?>" class="sc-back" aria-label="Back to event">←</a>
        <div>
            <p class="sc-title"><?= e($event['title']) ?></p>
            <p class="sc-count"><span data-in><?= (int) $stats['checked_in'] ?></span> of <span data-total><?= (int) $stats['tickets'] ?></span> checked in</p>
        </div>
    </div>

    <div class="sc-view">
        <video playsinline muted data-video></video>
        <div class="sc-frame" aria-hidden="true"></div>
        <div class="sc-start" data-start>
            <p>Point your camera at a ticket's QR code.</p>
            <button type="button" class="btn btn-lg" data-start-btn>Start camera</button>
            <p class="small" data-cam-msg></p>
        </div>
        <div class="sc-result" data-result hidden role="status" aria-live="assertive">
            <p class="sc-icon" data-r-icon></p>
            <p class="sc-head" data-r-head></p>
            <p class="sc-sub" data-r-sub></p>
        </div>
    </div>

    <form class="sc-manual" data-manual>
        <label for="manual-code" class="sr">Ticket code</label>
        <input id="manual-code" name="code" placeholder="Or type a code: K7Q2-M9XD-4TPA" autocomplete="off" autocapitalize="characters" spellcheck="false">
        <button class="btn">Check</button>
    </form>

    <ol class="sc-log" data-log aria-label="Recent scans"></ol>
</section>
<script src="/assets/scanner.js?v=1" defer></script>
