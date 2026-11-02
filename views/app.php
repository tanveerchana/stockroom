<?php
$titles = [
    'overview' => 'Inventory overview',
    'catalog' => 'Item catalog',
    'reservations' => 'Stock reservations',
    'movements' => 'Movement audit',
    'team' => 'Workspace team',
    'low-stock' => 'Low-stock items',
];
$descriptions = [
    'overview' => 'Receive stock, reserve available units and review warehouse activity.',
    'catalog' => 'Maintain SKUs and inspect on-hand, reserved and available stock.',
    'reservations' => 'Release a hold or fulfil it to deduct the reserved units from stock.',
    'movements' => 'Append-only records of every receipt, reservation, release and fulfilment.',
    'team' => 'Manage access within this workspace. Permissions are checked on every request.',
    'low-stock' => 'Items with available quantity at or below their reorder level.',
];
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
$catalog = $page === 'low-stock' ? $lowStock : $items;
if ($search !== '') {
    $catalog = array_values(
        array_filter(
            $catalog,
            fn($i) => str_contains(
                mb_strtolower($i['sku'] . ' ' . $i['name']),
                mb_strtolower($search),
            ),
        ),
    );
}
$selectedItem = is_string($_GET['item'] ?? null) ? $_GET['item'] : '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Stockroom — tenant-scoped synthetic inventory, reservations and immutable movement audit."><title><?= e(
    $titles[$page],
) ?> · Stockroom</title><link rel="stylesheet" href="/app.css"></head><body>
<header class="workspace-bar"><div><a class="brand" href="/"><span class="brand-icon">▦</span> Stockroom</a><span class="workspace-name"><?= e(
    $actor->workspace,
) ?></span><span class="tag"><?= $actor->demo
    ? 'GUEST DEMO'
    : 'ACCOUNT WORKSPACE' ?></span></div><div><span class="identity"><?= e(
    $actor->name,
) ?><span class="role"><?= e(
    ucfirst($actor->role),
) ?></span></span><form method="post" action="/"><?php fields(
    'logout',
    'login',
); ?><button class="button secondary" type="submit"><?= $actor->demo
    ? 'Exit demo'
    : 'Sign out' ?></button></form></div></header>
<div class="app-shell"><aside class="sidebar"><div class="nav-label">WORKSPACE</div><nav aria-label="Workspace navigation"><?php foreach (
    [
        'overview' => 'Overview',
        'catalog' => 'Catalog',
        'reservations' => 'Reservations',
        'low-stock' => 'Low stock',
        'movements' => 'Movements',
        'team' => 'Team',
    ]
    as $key => $label
): ?><a class="nav-item <?= $page === $key ? 'active' : '' ?>" href="/?page=<?= e(
    $key,
) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>><span><?= e($label) ?></span><?php if (
    $key === 'low-stock' &&
    count($lowStock)
): ?><span class="count"><?= count(
    $lowStock,
) ?></span><?php endif; ?></a><?php endforeach; ?></nav><div class="sidebar-note"><strong>Synthetic inventory</strong><p>No commerce, payments or real warehouse integrations.</p><a href="https://singhtanveer.com" target="_blank" rel="noreferrer">Built by Tanveer Singh ↗</a></div></aside>
<main class="main-content"><div class="page-heading"><div><div class="eyebrow">STOCKROOM / <?= e(
    strtoupper($page),
) ?></div><h1><?= e($titles[$page]) ?></h1><p><?= e($descriptions[$page]) ?></p></div><?php if (
    $page === 'movements'
): ?><a class="button secondary" href="/?page=movements&amp;export=csv">Export audit CSV</a><?php elseif (
    $page === 'overview'
): ?><a class="button secondary" href="/?page=catalog">View catalog</a><?php endif; ?></div>
<?php require __DIR__ . '/hosting-notice.php'; ?>
<?php if (
    $actor->demo
): ?><div class="context-note"><strong>Guest demo.</strong> This is your isolated sample warehouse. It expires after 24 hours. <a href="/?page=team">View access</a></div><?php endif; ?>
<?php if ($flash): ?><div class="notice <?= e($flash['kind']) ?>" role="alert"><?= e(
    $flash['message'],
) ?></div><?php endif; ?>
<?php if (
    in_array($page, ['overview', 'catalog', 'low-stock'], true)
): ?><section class="metrics" aria-label="Inventory totals"><article><span>Catalog items</span><strong><?= count(
    $items,
) ?></strong><small>Unique workspace SKUs</small></article><article><span>On-hand units</span><strong><?= qty(
    array_sum(array_column($items, 'on_hand')),
) ?></strong><small>Includes reserved stock</small></article><article><span>Reserved units</span><strong><?= qty(
    array_sum(array_column($items, 'reserved')),
) ?></strong><small><?= count(
    $active,
) ?> active reservations</small></article><article><span>Low-stock items</span><strong><?= count(
     $lowStock,
 ) ?></strong><small>At or below reorder level</small></article></section><?php endif; ?>
<?php if ($page === 'overview'): ?>
<div class="two-columns"><section class="panel"><div class="panel-heading"><div><h2>Receive inbound stock</h2><p>Increase on-hand units with an audit reference.</p></div><span class="tag">RECEIPT</span></div><div class="panel-body"><?php require __DIR__ .
    '/receipt-form.php'; ?></div></section><section class="panel"><div class="panel-heading"><div><h2>Reserve available stock</h2><p>Hold units without reducing on-hand quantity.</p></div><span class="tag">RESERVATION</span></div><div class="panel-body"><?php require __DIR__ .
    '/reservation-form.php'; ?></div></section></div>
<section class="panel"><div class="panel-heading"><div><h2>Low-stock watch</h2><p>Availability is on-hand stock minus reserved units.</p></div><a class="button ghost" href="/?page=low-stock">View all</a></div><?php
$displayItems = array_slice($lowStock, 0, 5);
require __DIR__ . '/items-table.php';
?></section>
<section class="panel"><div class="panel-heading"><div><h2>Recent movements</h2><p>Balances and audit entries commit in the same transaction.</p></div><a class="button ghost" href="/?page=movements">View audit</a></div><?php
$displayMovements = array_slice($movements, 0, 6);
require __DIR__ . '/movements-table.php';
?></section>
<?php elseif (in_array($page, ['catalog', 'low-stock'], true)): ?>
<?php if (
    $page === 'catalog'
): ?><div class="two-columns"><section class="panel"><div class="panel-heading"><div><h2>Add a catalog item</h2><p>New items start at zero stock. Owners manage the catalog.</p></div></div><div class="panel-body"><?php if (
    !$actor->can('catalog')
): ?><p class="permission-note">Owner access is required to add catalog items.</p><?php endif; ?><form method="post" action="/" class="stack"><?php fields(
    'create_item',
    'catalog',
); ?><fieldset <?= !$actor->can('catalog')
    ? 'disabled'
    : '' ?>><div class="form-row"><label>SKU<input name="sku" required minlength="2" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9-]{1,31}" placeholder="PKG-004" value="<?= e(
    $old['sku'] ?? '',
) ?>"></label><label>Reorder level<input name="reorder_level" type="number" min="0" max="1000000" step="1" required value="<?= e(
    $old['reorder_level'] ?? '10',
) ?>"></label></div><label>Item name<input name="name" required maxlength="100" placeholder="Recycled mailer · Large" value="<?= e(
    $old['name'] ?? '',
) ?>"></label><button class="button primary" type="submit">Add item</button></fieldset></form></div></section><section class="panel"><div class="panel-heading"><div><h2>Receive stock</h2><p>All receipts are recorded as immutable movements.</p></div></div><div class="panel-body"><?php require __DIR__ .
    '/receipt-form.php'; ?></div></section></div><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2><?= $page === 'low-stock'
    ? 'Reorder list'
    : 'All catalog items' ?></h2><p><?= count(
    $catalog,
) ?> matching items · quantities in whole units</p></div></div><form method="get" action="/" class="table-toolbar"><input type="hidden" name="page" value="<?= e(
     $page,
 ) ?>"><label class="search-label"><span class="sr-only">Search by SKU or name</span><input name="q" value="<?= e(
    $search,
) ?>" maxlength="80" placeholder="Search by SKU or item name"></label><button class="button secondary" type="submit">Search</button><?php if (
    $search !== ''
): ?><a class="button ghost" href="/?page=<?= e($page) ?>">Clear</a><?php endif; ?></form><?php
$displayItems = $catalog;
require __DIR__ . '/items-table.php';
?></section>
<?php elseif ($page === 'reservations'): ?>
<div class="two-columns"><section class="panel"><div class="panel-heading"><div><h2>Create reservation</h2><p>Only available stock can be reserved.</p></div></div><div class="panel-body"><?php require __DIR__ .
    '/reservation-form.php'; ?></div></section><section class="panel"><div class="panel-heading"><h2>Reservation rules</h2></div><div class="panel-body rule-list"><div><strong>Reserve</strong><p>Increases reserved units. On-hand stock stays unchanged.</p></div><div><strong>Release</strong><p>Returns a hold to availability. No stock leaves the warehouse.</p></div><div><strong>Fulfil</strong><p>Deducts the held quantity from on-hand and reserved stock. This is a terminal action.</p></div></div></section></div>
<section class="panel"><div class="panel-heading"><div><h2>Reservation ledger</h2><p><?= count(
    $active,
) ?> active · <?= count(
     $reservations,
 ) ?> total</p></div></div><div class="table-scroll"><table><thead><tr><th>Reference</th><th>Item</th><th>Quantity</th><th>Status</th><th>Created (UTC)</th><th>Actions</th></tr></thead><tbody><?php foreach (
     $reservations
     as $r
 ): ?><tr><td><strong><?= e($r['reference']) ?></strong><small>RES-<?= str_pad(
    (string) $r['id'],
    5,
    '0',
    STR_PAD_LEFT,
) ?></small></td><td><?= e($r['name']) ?><small><?= e(
    $r['sku'],
) ?></small></td><td class="numeric"><?= qty($r['quantity']) ?></td><td><?= status(
    $r['status'],
) ?></td><td class="metadata"><?= e($r['created_at']) ?></td><td><?php if (
    $r['status'] === 'active'
): ?><div class="row-actions"><form method="post" action="/"><?php fields(
    'fulfil',
    'reservations',
); ?><input type="hidden" name="reservation_id" value="<?= e(
    $r['id'],
) ?>"><button class="button secondary" type="submit" <?= !$actor->can('fulfil')
    ? 'disabled'
    : '' ?>>Fulfil</button></form><form method="post" action="/"><?php fields(
    'release',
    'reservations',
); ?><input type="hidden" name="reservation_id" value="<?= e(
    $r['id'],
) ?>"><button class="button ghost" type="submit" <?= !$actor->can('release')
    ? 'disabled'
    : '' ?>>Release</button></form></div><?php else: ?><span class="metadata">Closed <?= e(
    $r['closed_at'],
) ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (
    !$reservations
): ?><div class="empty-state"><h3>No reservations yet</h3><p>Receive stock, then reserve units using the form above.</p></div><?php endif; ?></section>
<?php elseif ($page === 'movements'): ?>
<div class="context-note">Audit entries cannot be updated or deleted. Reservation and release rows change reserved units; fulfilment rows reduce on-hand units.</div><section class="panel"><div class="panel-heading"><div><h2>All stock movements</h2><p><?= count(
    $movements,
) ?> entries · latest first · UTC timestamps</p></div></div><?php
 $displayMovements = $movements;
 require __DIR__ . '/movements-table.php';
 ?></section>
<?php elseif ($page === 'team'): ?>
<?php if (
    $actor->demo
): ?><div class="context-note">Guest demos are isolated and temporary. Team management is available after creating an account.</div><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Workspace members</h2><p>One workspace per account. Removed members keep their historical audit attribution.</p></div></div><div class="table-scroll"><table><thead><tr><th>Member</th><th>Email</th><th>Role</th><th>Manage access</th></tr></thead><tbody><?php foreach (
    $members
    as $m
): ?><tr><td><?=
e($m['name']) . ((int) $m['id'] === $actor->id ? ' <span class="metadata">(you)</span>' : '')
?></td><td><?= e($m['email']) ?></td><td><?= status($m['role']) ?></td><td><?php if (
    $m['role'] !== 'owner' &&
    $actor->can('team') &&
    !$actor->demo
): ?><div class="row-actions"><form method="post" action="/" class="inline-form"><?php fields(
    'member_role',
    'team',
); ?><input type="hidden" name="member_id" value="<?= e(
    $m['id'],
) ?>"><label class="sr-only" for="role-<?= e($m['id']) ?>">Role for <?= e(
    $m['name'],
) ?></label><select id="role-<?= e($m['id']) ?>" name="role"><option value="operator" <?= $m[
    'role'
] === 'operator'
    ? 'selected'
    : '' ?>>Operator</option><option value="viewer" <?= $m['role'] === 'viewer'
    ? 'selected'
    : '' ?>>Viewer</option></select><button class="button secondary" type="submit">Save</button></form><form method="post" action="/"><?php fields(
    'remove_member',
    'team',
); ?><input type="hidden" name="member_id" value="<?= e(
    $m['id'],
) ?>"><button class="button ghost danger" type="submit">Remove</button></form></div><?php else: ?><span class="metadata"><?= $m[
    'role'
] === 'owner'
    ? 'Owner role is protected'
    : 'Read only' ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<div class="two-columns"><section class="panel"><div class="panel-heading"><div><h2>Invite a member</h2><p>Invitation links expire after 24 hours and can be used once.</p></div></div><div class="panel-body"><?php if (
    !$actor->can('team') ||
    $actor->demo
): ?><p class="permission-note">Only account workspace owners can invite members.</p><?php endif; ?><form method="post" action="/" class="stack"><?php fields(
    'invite',
    'team',
); ?><fieldset <?= !$actor->can('team') || $actor->demo
    ? 'disabled'
    : '' ?>><label>Recipient email<input type="email" name="email" required maxlength="254" autocomplete="off"></label><label>Access role<select name="role"><option value="operator">Operator — receive and manage reservations</option><option value="viewer">Viewer — inspect and export only</option></select></label><button class="button primary" type="submit">Create invitation link</button></fieldset></form><?php if (
    isset($_SESSION['invite_link'])
):

    $link = $_SESSION['invite_link'];
    unset($_SESSION['invite_link']);
    ?><div class="invitation-result"><strong>Share only with the intended recipient</strong><p>The link grants access to their invited email. No email is sent automatically.</p><a class="button secondary" href="<?= e(
    $link,
) ?>" target="_blank" rel="noreferrer">Open invitation link ↗</a><label>Relative invitation URL<textarea readonly rows="3"><?= e(
    $link,
) ?></textarea></label></div><?php
endif; ?></div></section><section class="panel"><div class="panel-heading"><h2>Access roles</h2></div><div class="panel-body rule-list"><div><strong>Owner</strong><p>Manage catalog items, stock, reservations and workspace members. Owner access cannot be delegated or removed.</p></div><div><strong>Operator</strong><p>Receive stock and create, release or fulfil reservations. Cannot change catalog or team access.</p></div><div><strong>Viewer</strong><p>Inspect catalog, low-stock lists, reservations and audit records. Export the movement ledger.</p></div></div></section></div>
<?php endif; ?>
<footer class="footer"><span>Stockroom · Synthetic portfolio data · Core PHP 8.3 + SQLite</span><p>Synthetic inventory only. Keep no sensitive or real operational data here. Storage retention is controlled by your deployment.</p><a href="https://singhtanveer.com" target="_blank" rel="noreferrer">Tanveer Singh ↗</a></footer></main></div></body></html>
