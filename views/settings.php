<?php /** @var array $old @var array $errors */ ?>
<section class="wrap auth">
    <h1>Profile</h1>
    <p class="muted">Shown on your page, <a href="/@<?= e($me['username']) ?>"><?= e(host()) ?>/@<?= e($me['username']) ?></a>, and on every event as "Hosted by".</p>
    <form method="post" action="/settings" class="form">
        <?= csrf_field() ?>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Name or brand</label>
            <input id="name" name="name" value="<?= e($old['name']) ?>" maxlength="60" required>
            <?php if (isset($errors['name'])): ?><p class="error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>
        <div class="field<?= isset($errors['bio']) ? ' has-error' : '' ?>">
            <label for="bio">Short bio <span class="muted">(optional)</span></label>
            <textarea id="bio" name="bio" rows="3" maxlength="280" data-counter><?= e($old['bio']) ?></textarea>
            <?php if (isset($errors['bio'])): ?><p class="error"><?= e($errors['bio']) ?></p><?php endif; ?>
        </div>
        <button class="btn">Save</button>
    </form>
</section>
