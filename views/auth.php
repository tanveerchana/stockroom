<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Stockroom — a synthetic inventory and reservation workspace by Tanveer Singh."><title><?= $page ===
'register'
    ? 'Create account'
    : ($page === 'join'
        ? 'Join workspace'
        : 'Sign in') ?> · Stockroom</title><link rel="stylesheet" href="/app.css"></head><body><main class="auth-layout"><section class="auth-card"><a href="/" class="brand"><span class="brand-icon">▦</span> Stockroom</a><span class="tag">SYNTHETIC PORTFOLIO WORKSPACE</span>
<?php require __DIR__ . '/hosting-notice.php'; ?>
<?php if ($flash): ?><div role="alert" class="notice <?= e($flash['kind']) ?>"><?= e(
    $flash['message'],
) ?></div><?php endif; ?>
<?php if (
    $page === 'join' &&
    !$invitation
): ?><h1>Invitation unavailable</h1><p>This invitation is invalid, has expired or has already been used. Ask the workspace owner for a new link.</p><a class="button secondary" href="/?page=login">Sign in</a>
<?php else: ?>
<h1><?= $page === 'register'
    ? 'Create your workspace'
    : ($page === 'join'
        ? 'Join ' . e($invitation['workspace'])
        : 'Sign in to Stockroom') ?></h1><p><?= $page === 'register'
    ? 'Start an inventory workspace with owner access.'
    : ($page === 'join'
        ? 'Your invitation grants ' . e($invitation['role']) . ' access.'
        : 'Manage synthetic inventory, reservations and stock movements.') ?></p>
<form method="post" action="/" class="stack"><?php fields(
    $page === 'register' ? 'register' : ($page === 'join' ? 'accept' : 'login'),
    $page === 'join' ? 'login' : $page,
); ?>
<?php if ($page === 'join'): ?><input type="hidden" name="token" value="<?= e(
    $joinToken,
) ?>"><div class="field"><span>Invited email</span><strong><?= e(
    $invitation['email'],
) ?></strong></div><?php endif; ?>
<?php if (
    $page !== 'login'
): ?><label>Your name<input name="name" required maxlength="80" autocomplete="name" value="<?= e(
    $old['name'] ?? '',
) ?>"></label><?php endif; ?>
<?php if (
    $page === 'register'
): ?><label>Workspace name<input name="workspace" required maxlength="80" value="<?= e(
    $old['workspace'] ?? '',
) ?>" placeholder="Acme warehouse"></label><?php endif; ?>
<?php if (
    $page !== 'join'
): ?><label>Email address<input type="email" name="email" required maxlength="254" autocomplete="email" value="<?= e(
    $old['email'] ?? '',
) ?>"></label><?php endif; ?>
<label>Password<input type="password" name="password" required <?= $page === 'login'
    ? ''
    : 'minlength="12" maxlength="72"' ?> autocomplete="<?= $page === 'login'
     ? 'current-password'
     : 'new-password' ?>"></label>
<?php if (
    $page !== 'login'
): ?><label>Confirm password<input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label><p class="help">Use 12–72 bytes. Account and inventory data are for demonstration only.</p><?php endif; ?>
<button class="button primary" type="submit"><?= $page === 'register'
    ? 'Create account'
    : ($page === 'join'
        ? 'Accept invitation'
        : 'Sign in') ?></button></form>
<?php if ($page !== 'join'): ?><p class="auth-switch"><?= $page === 'register'
    ? 'Already registered?'
    : 'Need a workspace?' ?> <a href="/?page=<?= $page === 'register'
     ? 'login'
     : 'register' ?>"><?= $page === 'register'
    ? 'Sign in'
    : 'Create an account' ?></a></p><?php endif; ?>
<?php endif; ?>
<div class="demo-choice"><h2>Explore without an account</h2><p>Open a separate guest warehouse with sample items and reservations. It expires in 24 hours.</p><form method="post" action="/"><?php fields(
    'demo',
    'login',
); ?><button class="button secondary" type="submit">Try synthetic demo</button></form></div><p class="auth-footer">Built by <a href="https://singhtanveer.com" rel="noreferrer" target="_blank">Tanveer Singh</a> · Core PHP + SQLite</p></section></main></body></html>
