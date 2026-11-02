<?php

declare(strict_types=1);

namespace Stockroom;

use DomainException;
use PDO;
use PDOException;
use Throwable;

/** All tenant mutations acquire SQLite's write lock before checking balances. */
final class Inventory
{
    public function __construct(private PDO $db) {}

    public static function units(mixed $raw, bool $zero = false): int
    {
        if ((!is_string($raw) && !is_int($raw)) || !preg_match('/^[0-9]{1,7}$/D', (string) $raw)) {
            throw new DomainException('Quantity must be a whole number of units.');
        }
        $value = (int) $raw;
        if ($value < ($zero ? 0 : 1) || $value > 1000000) {
            throw new DomainException(
                'Quantity must be between ' . ($zero ? '0' : '1') . ' and 1,000,000 units.',
            );
        }
        return $value;
    }

    public static function text(string $raw, int $limit, string $label): string
    {
        $value = trim($raw);
        if (
            !mb_check_encoding($value, 'UTF-8') ||
            $value === '' ||
            mb_strlen($value) > $limit ||
            preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            throw new DomainException(
                $label . ' is required and must be at most ' . $limit . ' printable characters.',
            );
        }
        return $value;
    }

    public function createItem(Actor $a, string $sku, string $name, mixed $reorder): int
    {
        $a->require('catalog');
        $sku = strtoupper(trim($sku));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/D', $sku)) {
            throw new DomainException('SKU must contain 2–32 letters, numbers or hyphens.');
        }
        $name = self::text($name, 100, 'Item name');
        $level = self::units($reorder, true);
        return $this->write(function () use ($a, $sku, $name, $level) {
            $q = $this->db->prepare('SELECT count(*) FROM items WHERE tenant_id=?');
            $q->execute([$a->tenantId]);
            if ((int) $q->fetchColumn() >= 200) {
                throw new DomainException('This workspace is limited to 200 catalog items.');
            }
            $q = $this->db->prepare('SELECT id FROM items WHERE tenant_id=? AND sku=?');
            $q->execute([$a->tenantId, $sku]);
            if ($q->fetch()) {
                throw new DomainException('This SKU already exists in your workspace.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db
                ->prepare(
                    'INSERT INTO items(tenant_id,sku,name,reorder_level,created_at,updated_at) VALUES(?,?,?,?,?,?)',
                )
                ->execute([$a->tenantId, $sku, $name, $level, $now, $now]);
            return (int) $this->db->lastInsertId();
        });
    }

    public function receive(
        Actor $a,
        int $item,
        mixed $quantity,
        string $reference,
        string $key,
    ): int {
        $a->require('receipt');
        $qty = self::units($quantity);
        $reference = self::text($reference, 80, 'Receipt reference');
        return $this->command(
            $a,
            $key,
            ['receipt', $a->id, $item, $qty, $reference],
            function () use ($a, $item, $qty, $reference) {
                $this->item($a, $item);
                $q = $this->db->prepare(
                    'UPDATE items SET on_hand=on_hand+?,updated_at=? WHERE id=? AND tenant_id=? AND on_hand<=?',
                );
                $q->execute([$qty, gmdate('Y-m-d H:i:s'), $item, $a->tenantId, 1000000 - $qty]);
                if ($q->rowCount() !== 1) {
                    throw new DomainException(
                        'Receipt would exceed the 1,000,000-unit item limit.',
                    );
                }
                return $this->movement($a, $item, null, 'receipt', $qty, $qty, 0, $reference);
            },
        );
    }

    public function reserve(
        Actor $a,
        int $item,
        mixed $quantity,
        string $reference,
        string $key,
    ): int {
        $a->require('reserve');
        $qty = self::units($quantity);
        $reference = self::text($reference, 80, 'Reservation reference');
        return $this->command(
            $a,
            $key,
            ['reserve', $a->id, $item, $qty, $reference],
            function () use ($a, $item, $qty, $reference) {
                $this->item($a, $item);
                $count = $this->db->prepare('SELECT count(*) FROM reservations WHERE tenant_id=?');
                $count->execute([$a->tenantId]);
                if ((int) $count->fetchColumn() >= 1000) {
                    throw new DomainException('This workspace is limited to 1,000 reservations.');
                }
                $q = $this->db->prepare(
                    'UPDATE items SET reserved=reserved+?,updated_at=? WHERE id=? AND tenant_id=? AND on_hand-reserved>=CAST(? AS INTEGER)',
                );
                $q->execute([$qty, gmdate('Y-m-d H:i:s'), $item, $a->tenantId, $qty]);
                if ($q->rowCount() !== 1) {
                    throw new DomainException(
                        'Insufficient available stock. No reservation was created.',
                    );
                }
                $this->db
                    ->prepare(
                        "INSERT INTO reservations(tenant_id,item_id,quantity,reference,status,created_by,created_at) VALUES(?,?,?,?,'active',?,?)",
                    )
                    ->execute([
                        $a->tenantId,
                        $item,
                        $qty,
                        $reference,
                        $a->id,
                        gmdate('Y-m-d H:i:s'),
                    ]);
                $id = (int) $this->db->lastInsertId();
                $this->movement($a, $item, $id, 'reservation', $qty, 0, $qty, $reference);
                return $id;
            },
        );
    }

    public function close(Actor $a, int $reservation, string $action): void
    {
        if (!in_array($action, ['release', 'fulfil'], true)) {
            throw new DomainException('Unknown reservation action.');
        }
        $a->require($action);
        $this->write(function () use ($a, $reservation, $action) {
            $q = $this->db->prepare('SELECT * FROM reservations WHERE id=? AND tenant_id=?');
            $q->execute([$reservation, $a->tenantId]);
            $r = $q->fetch();
            if (!$r) {
                throw new DomainException('Reservation was not found in this workspace.');
            }
            if ($r['status'] !== 'active') {
                throw new DomainException(
                    'This reservation is already ' . $r['status'] . '. No stock was changed.',
                );
            }
            $qty = (int) $r['quantity'];
            $onHandDelta = $action === 'fulfil' ? -$qty : 0;
            $q = $this->db->prepare(
                'UPDATE items SET on_hand=on_hand+?,reserved=reserved-?,updated_at=? WHERE id=? AND tenant_id=? AND reserved>=? AND on_hand>=?',
            );
            $q->execute([
                $onHandDelta,
                $qty,
                gmdate('Y-m-d H:i:s'),
                $r['item_id'],
                $a->tenantId,
                $qty,
                $action === 'fulfil' ? $qty : 0,
            ]);
            if ($q->rowCount() !== 1) {
                throw new DomainException('Stock consistency check failed. Nothing was changed.');
            }
            $this->db
                ->prepare(
                    'UPDATE reservations SET status=?,closed_at=? WHERE id=? AND tenant_id=? AND status=?',
                )
                ->execute([
                    $action === 'fulfil' ? 'fulfilled' : 'released',
                    gmdate('Y-m-d H:i:s'),
                    $reservation,
                    $a->tenantId,
                    'active',
                ]);
            $this->movement(
                $a,
                (int) $r['item_id'],
                $reservation,
                $action === 'fulfil' ? 'fulfilment' : 'release',
                $qty,
                $onHandDelta,
                -$qty,
                $r['reference'],
            );
        });
    }

    public function items(Actor $a): array
    {
        $q = $this->db->prepare(
            'SELECT *,on_hand-reserved AS available FROM items WHERE tenant_id=? ORDER BY name',
        );
        $q->execute([$a->tenantId]);
        return $q->fetchAll();
    }

    public function reservations(Actor $a): array
    {
        $q = $this->db->prepare(
            'SELECT r.*,i.sku,i.name,u.name AS actor FROM reservations r JOIN items i ON i.id=r.item_id AND i.tenant_id=r.tenant_id JOIN users u ON u.id=r.created_by WHERE r.tenant_id=? ORDER BY r.id DESC LIMIT 1000',
        );
        $q->execute([$a->tenantId]);
        return $q->fetchAll();
    }

    public function movements(Actor $a): array
    {
        $q = $this->db->prepare(
            'SELECT m.*,i.sku,i.name,u.name AS actor FROM movements m JOIN items i ON i.id=m.item_id AND i.tenant_id=m.tenant_id JOIN users u ON u.id=m.actor_id WHERE m.tenant_id=? ORDER BY m.id DESC LIMIT 5000',
        );
        $q->execute([$a->tenantId]);
        return $q->fetchAll();
    }

    private function item(Actor $a, int $id): array
    {
        $q = $this->db->prepare('SELECT * FROM items WHERE id=? AND tenant_id=?');
        $q->execute([$id, $a->tenantId]);
        $item = $q->fetch();
        if (!$item) {
            throw new DomainException('Item was not found in this workspace.');
        }
        return $item;
    }

    private function movement(
        Actor $a,
        int $item,
        ?int $reservation,
        string $kind,
        int $qty,
        int $onHandDelta,
        int $reservedDelta,
        string $reference,
    ): int {
        $count = $this->db->prepare('SELECT count(*) FROM movements WHERE tenant_id=?');
        $count->execute([$a->tenantId]);
        if ((int) $count->fetchColumn() >= 5000) {
            throw new DomainException(
                'This workspace is limited to 5,000 immutable stock movements.',
            );
        }
        $balance = $this->item($a, $item);
        $this->db
            ->prepare(
                'INSERT INTO movements(tenant_id,item_id,reservation_id,actor_id,kind,quantity,on_hand_delta,reserved_delta,on_hand_after,reserved_after,reference,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            )
            ->execute([
                $a->tenantId,
                $item,
                $reservation,
                $a->id,
                $kind,
                $qty,
                $onHandDelta,
                $reservedDelta,
                $balance['on_hand'],
                $balance['reserved'],
                $reference,
                gmdate('Y-m-d H:i:s'),
            ]);
        return (int) $this->db->lastInsertId();
    }

    private function command(Actor $a, string $key, array $request, callable $apply): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new DomainException('Invalid request key. Reload the form and try again.');
        }
        $fingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        return $this->write(function () use ($a, $key, $fingerprint, $apply) {
            $q = $this->db->prepare(
                'SELECT fingerprint,result_id FROM commands WHERE tenant_id=? AND request_key=?',
            );
            $q->execute([$a->tenantId, $key]);
            $existing = $q->fetch();
            if ($existing) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new DomainException(
                        'This request key was already used with different values. Reload the form.',
                    );
                }
                return (int) $existing['result_id'];
            }
            $result = $apply();
            $this->db
                ->prepare(
                    'INSERT INTO commands(tenant_id,request_key,fingerprint,result_id,created_at) VALUES(?,?,?,?,?)',
                )
                ->execute([$a->tenantId, $key, $fingerprint, $result, gmdate('Y-m-d H:i:s')]);
            return $result;
        });
    }

    private function write(callable $apply): mixed
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $this->db->exec('BEGIN IMMEDIATE');
                $result = $apply();
                $this->db->exec('COMMIT');
                return $result;
            } catch (Throwable $e) {
                try {
                    $this->db->exec('ROLLBACK');
                } catch (Throwable) {
                }
                if (
                    $e instanceof PDOException &&
                    str_contains(strtolower($e->getMessage()), 'locked') &&
                    $attempt < 3
                ) {
                    usleep(25000 * ($attempt + 1));
                    continue;
                }
                throw $e;
            }
        }
        throw new DomainException('Inventory is busy. Please retry.');
    }
}
