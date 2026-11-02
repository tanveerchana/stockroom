<?php if (
    !$items
): ?><div class="empty-state compact"><h3>No stock to reserve</h3><p>Add an item and record its first receipt to get started.</p></div><?php else: ?>
<?php if (
    !$actor->can('reserve')
): ?><p class="permission-note">Viewer access. An owner or operator must manage reservations.</p><?php endif; ?>
<form method="post" action="/" class="stack"><?php fields(
    'reserve',
    $page,
); ?><fieldset <?= !$actor->can('reserve')
    ? 'disabled'
    : '' ?>><label>Catalog item<select name="item_id" required><?php foreach (
    $items
    as $i
): ?><option value="<?= e($i['id']) ?>"><?= e(
    $i['sku'] . ' · ' . $i['name'] . ' · ' . $i['available'] . ' available',
) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Quantity<input type="number" name="quantity" required min="1" max="1000000" step="1" value="5"></label><label>Reservation reference<input name="reference" required maxlength="80" placeholder="Order #1049"></label></div><p class="help">Availability is checked atomically when you submit. On-hand stock stays unchanged.</p><button class="button primary" type="submit">Reserve stock</button></fieldset></form><?php endif; ?>
