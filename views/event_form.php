<?php
/** @var ?array $event @var array $old @var array $errors */
$editing = $event !== null;
$types = $old['types'] ?: [['id' => 0, 'name' => '', 'price' => 0, 'quantity' => '']];
$err = fn (string $k) => isset($errors[$k]) ? '<p class="error">' . e($errors[$k]) . '</p>' : '';
$cls = fn (string $k) => isset($errors[$k]) ? ' has-error' : '';
?>
<section class="wrap page-head">
    <p class="crumbs"><a href="/dashboard">Dashboard</a><?php if ($editing): ?> / <a href="/events/<?= (int) $event['id'] ?>"><?= e($event['title']) ?></a><?php endif; ?></p>
    <h1><?= $editing ? 'Edit event' : 'New event' ?></h1>
</section>

<form class="wrap event-form" method="post" enctype="multipart/form-data" action="<?= $editing ? '/events/' . (int) $event['id'] . '/edit' : '/events/new' ?>" data-event-form novalidate>
    <?= csrf_field() ?>
    <?php if ($errors): ?><p class="alert" role="alert">Please fix the highlighted fields below.</p><?php endif; ?>

    <div class="ef-grid">
        <div class="ef-main">
            <fieldset class="panel">
                <legend>Cover photo</legend>
                <div class="dropzone<?= $cls('cover') ?>" data-dropzone>
                    <img data-cover-preview alt="" <?= !empty($old['cover']) ? 'src="' . e(cover_url($old['cover'])) . '"' : 'hidden' ?>>
                    <div class="dz-empty" <?= !empty($old['cover']) ? 'hidden' : '' ?>>
                        <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M4 16l4-4 3 3 5-5 4 4M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        <p><strong>Drop a photo here</strong> or <span class="link-like">choose one</span></p>
                        <p class="small muted">JPG, PNG or WebP. Shown wide (2:1). We shrink big photos for you.</p>
                    </div>
                    <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-cover-input aria-label="Cover photo">
                </div>
                <?= $err('cover') ?>
                <?php if (!empty($old['cover'])): ?>
                    <label class="check small"><input type="checkbox" name="remove_cover" value="1"> Remove the current photo</label>
                <?php endif; ?>
            </fieldset>

            <fieldset class="panel">
                <legend>Details</legend>
                <div class="field<?= $cls('title') ?>">
                    <label for="title">Event name</label>
                    <input id="title" name="title" value="<?= e($old['title']) ?>" maxlength="90" required placeholder="e.g. Lagos Builders Meetup" data-preview="title">
                    <?= $err('title') ?>
                </div>
                <div class="field<?= $cls('description') ?>">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="7" maxlength="5000" data-counter placeholder="What's happening, who it's for, what to bring..."><?= e($old['description']) ?></textarea>
                    <?= $err('description') ?>
                </div>
            </fieldset>

            <fieldset class="panel">
                <legend>When</legend>
                <div class="row3">
                    <div class="field<?= $cls('start_local') ?>">
                        <label for="start_local">Starts</label>
                        <input id="start_local" type="datetime-local" name="start_local" value="<?= e($old['start_local']) ?>" required data-start>
                        <?= $err('start_local') ?>
                    </div>
                    <div class="field<?= $cls('end_local') ?>">
                        <label for="end_local">Ends</label>
                        <input id="end_local" type="datetime-local" name="end_local" value="<?= e($old['end_local']) ?>" required data-end>
                        <?= $err('end_local') ?>
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" data-timezone<?= $editing ? '' : ' data-detect' ?>>
                            <?php foreach (config('timezones') as $tz => $label): ?>
                                <option value="<?= e($tz) ?>" <?= $old['timezone'] === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="hint" data-duration></p>
            </fieldset>

            <fieldset class="panel">
                <legend>Where</legend>
                <div class="field<?= $cls('venue') ?>">
                    <label for="venue">Venue address</label>
                    <input id="venue" name="venue" value="<?= e($old['venue']) ?>" maxlength="200" placeholder="e.g. The Zone, 9 Gbagada Expressway, Lagos">
                    <?= $err('venue') ?>
                </div>
                <div class="field<?= $cls('online_url') ?>">
                    <label for="online_url">Online link <span class="muted">(optional)</span></label>
                    <input id="online_url" name="online_url" type="url" value="<?= e($old['online_url']) ?>" maxlength="300" placeholder="https://meet.google.com/...">
                    <p class="hint">Only people with a ticket see this link.</p>
                    <?= $err('online_url') ?>
                </div>
            </fieldset>

            <fieldset class="panel">
                <legend>Tickets</legend>
                <?= $err('types') ?>
                <div class="types" data-types data-max="<?= (int) config('max_ticket_types') ?>">
                    <div class="type-head" aria-hidden="true"><span>Name</span><span>Price (<?= e(config('currency_symbol')) ?>)</span><span>Quantity</span><span></span></div>
                    <?php foreach ($types as $t): ?>
                        <div class="type-row" data-type-row>
                            <input type="hidden" name="type_id[]" value="<?= (int) ($t['id'] ?? 0) ?>">
                            <input name="type_name[]" value="<?= e($t['name']) ?>" maxlength="40" placeholder="Regular" aria-label="Ticket name">
                            <input name="type_price[]" value="<?= (int) $t['price'] ? e(rtrim(rtrim(number_format($t['price'] / 100, 2, '.', ''), '0'), '.')) : '0' ?>" inputmode="decimal" aria-label="Price, 0 for free" data-price>
                            <input name="type_qty[]" value="<?= e((string) $t['quantity']) ?>" inputmode="numeric" placeholder="100" aria-label="How many">
                            <button type="button" class="icon-btn" data-remove-type aria-label="Remove this ticket type" <?= !empty($t['sold']) ? 'disabled title="Some are sold already"' : '' ?>>×</button>
                            <?php if (!empty($t['sold'])): ?><p class="type-sold small muted"><?= (int) $t['sold'] ?> sold</p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-line" data-add-type>+ Add ticket type</button>
                <p class="hint">Use 0 for free tickets. Paid tickets go through Paystack.</p>
            </fieldset>
        </div>

        <aside class="ef-side">
            <div class="panel sticky">
                <p class="small muted">Preview</p>
                <div class="mini-ticket">
                    <div class="mt-cover" data-preview-cover></div>
                    <p class="mt-title" data-preview-title><?= e($old['title'] ?: 'Your event name') ?></p>
                    <p class="small muted" data-preview-when>Pick a date</p>
                    <p class="small" data-preview-price></p>
                </div>
                <?php if (!$editing): ?>
                    <fieldset class="publish-choice">
                        <legend class="sr">Publish now?</legend>
                        <label class="check"><input type="radio" name="status" value="published" <?= $old['status'] === 'published' ? 'checked' : '' ?>> <span><strong>Publish now</strong><br><span class="small muted">Start selling straight away</span></span></label>
                        <label class="check"><input type="radio" name="status" value="draft" <?= $old['status'] !== 'published' ? 'checked' : '' ?>> <span><strong>Save as draft</strong><br><span class="small muted">Only you can see it</span></span></label>
                    </fieldset>
                <?php endif; ?>
                <button class="btn btn-block" data-submit><?= $editing ? 'Save changes' : 'Create event' ?></button>
            </div>
        </aside>
    </div>
</form>
