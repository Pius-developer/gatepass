<?php /** @var ?string $error @var string $login */ ?>
<section class="wrap auth">
    <h1>Log in</h1>
    <form method="post" action="/login" class="form">
        <?= csrf_field() ?>
        <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
        <div class="field">
            <label for="login">Email or username</label>
            <input id="login" name="login" value="<?= e($login) ?>" required autocomplete="username" autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="pw"><input id="password" name="password" type="password" required autocomplete="current-password"><button type="button" class="pw-toggle" data-pw-toggle>Show</button></div>
        </div>
        <button class="btn btn-block">Log in</button>
        <p class="small muted center">New here? <a href="/signup">Create an account</a></p>
    </form>
</section>
