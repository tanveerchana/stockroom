<?php if (
    !$items
): ?><div class="empty-state compact"><h3>Add your first item</h3><p>Create a catalog item before receiving stock.</p><a class="button secondary" href="/?page=catalog">Open catalog</a></div><?php else: ?>
<?php if (
    !$actor->can('receipt')
): ?><p class="permission-note">Viewer access. An owner or operator must record receipts.</p><?php endif; ?>
<form method="post" action="/" class="stack"><?php fields(
    'receipt',
    $page,
); ?><fieldset <?= !$actor->can('receipt')
    ? 'disabled'
    : '' ?>><label>Catalog item<select name="item_id" required><?php foreach (
    $items
    as $i
): ?><option value="<?= e($i['id']) ?>" <?= $selectedItem === (string) $i['id']
    ? 'selected'
    : '' ?>><?= e(
    $i['sku'] . ' · ' . $i['name'],
) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Quantity<input type="number" name="quantity" required min="1" max="1000000" step="1" value="<?= e(
    $old['quantity'] ?? '10',
) ?>"></label><label>Receipt reference<input name="reference" required maxlength="80" placeholder="Inbound #2009" value="<?= e(
    $old['reference'] ?? '',
) ?>"></label></div><p class="help">Whole units only. This adds stock and an immutable receipt movement.</p><button class="button primary" type="submit">Record receipt</button></fieldset></form><?php endif; ?>
