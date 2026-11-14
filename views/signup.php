<?php /** @var array $old @var array $errors */ ?>
<section class="wrap auth">
    <h1>Start selling tickets</h1>
    <p class="muted">Free to sign up. Your events will live at <strong><?= e(host()) ?>/@yourname</strong>.</p>
    <form method="post" action="/signup" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Your name or brand</label>
            <input id="name" name="name" value="<?= e($old['name']) ?>" maxlength="60" required autocomplete="organization" placeholder="e.g. Lagos Builders">
            <?php if (isset($errors['name'])): ?><p class="error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>
        <div class="field<?= isset($errors['username']) ? ' has-error' : '' ?>">
            <label for="username">Username</label>
            <div class="prefix"><span><?= e(host()) ?>/@</span><input id="username" name="username" value="<?= e($old['username']) ?>" maxlength="20" required pattern="[a-zA-Z0-9_]{3,20}" autocomplete="username" data-slugify></div>
            <?php if (isset($errors['username'])): ?><p class="error"><?= e($errors['username']) ?></p><?php endif; ?>
        </div>
        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" required autocomplete="email">
            <?php if (isset($errors['email'])): ?><p class="error"><?= e($errors['email']) ?></p><?php endif; ?>
        </div>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password">Password</label>
            <div class="pw"><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" data-pw-toggle>Show</button></div>
            <?php if (isset($errors['password'])): ?><p class="error"><?= e($errors['password']) ?></p><?php else: ?><p class="hint">At least 8 characters.</p><?php endif; ?>
        </div>
        <button class="btn btn-block">Create my account</button>
        <p class="small muted center">Already have an account? <a href="/login">Log in</a></p>
    </form>
</section>
