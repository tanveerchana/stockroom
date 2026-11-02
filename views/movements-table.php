<div class="table-scroll"><table><thead><tr><th>Movement / reference</th><th>Item</th><th>Type</th><th>Units</th><th>On-hand Δ</th><th>Reserved Δ</th><th>Balance after</th><th>Actor / UTC time</th></tr></thead><tbody><?php foreach (
    $displayMovements
    as $m
): ?><tr><td><code>MOV-<?= str_pad((string) $m['id'], 5, '0', STR_PAD_LEFT) ?></code><small><?= e(
    $m['reference'],
) ?></small></td><td><?= e($m['name']) ?><small><?= e($m['sku']) ?></small></td><td><?= status(
    $m['kind'],
) ?></td><td class="numeric"><?= qty($m['quantity']) ?></td><td class="numeric"><?=
((int) $m['on_hand_delta'] > 0 ? '+' : '') . qty($m['on_hand_delta'])
?></td><td class="numeric"><?=
((int) $m['reserved_delta'] > 0 ? '+' : '') . qty($m['reserved_delta'])
?></td><td><span class="metadata"><?= qty($m['on_hand_after']) ?> on hand<br><?= qty(
     $m['reserved_after'],
 ) ?> reserved</span></td><td><?= e($m['actor']) ?><small><?= e(
    $m['created_at'],
) ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php if (
    !$displayMovements
): ?><div class="empty-state"><h3>No movements yet</h3><p>Record a receipt to create the first immutable stock movement.</p></div><?php endif; ?>
