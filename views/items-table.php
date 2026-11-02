<div class="table-scroll"><table><thead><tr><th>Item</th><th>SKU</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Reorder level</th><th>Status</th></tr></thead><tbody><?php foreach (
    $displayItems
    as $i
): ?><tr><td><strong><?= e($i['name']) ?></strong><small>Whole units</small></td><td><code><?= e(
    $i['sku'],
) ?></code></td><td class="numeric"><?= qty($i['on_hand']) ?></td><td class="numeric"><?= qty(
    $i['reserved'],
) ?></td><td class="numeric"><strong><?= qty(
    $i['available'],
) ?></strong></td><td class="numeric"><?= qty($i['reorder_level']) ?></td><td><?= status(
    (int) $i['available'] <= (int) $i['reorder_level'] ? 'low-stock' : 'healthy',
) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (
    !$displayItems
): ?><div class="empty-state"><h3><?= $page === 'overview' || $page === 'low-stock'
    ? 'No low-stock items'
    : 'No matching items' ?></h3><p><?= $page === 'overview' || $page === 'low-stock'
    ? 'Items at or below their reorder level appear here.'
    : 'Try a different search or add a catalog item.' ?></p></div><?php endif; ?>
