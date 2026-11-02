<?php

declare(strict_types=1);

namespace Stockroom;

use PDO;
use DomainException;
use Throwable;

final class Auth
{
    private const DUMMY_HASH = '$2y$12$cuPTg.f0Wf/D4vXIU51Q4e3/NE8NFKKw24Ey52tF5QmF8l0zArsku';
    public function __construct(private PDO $db) {}

    public function register(string $name, string $email, string $password, string $workspace): int
    {
        $name = Inventory::text($name, 80, 'Your name');
        $email = $this->email($email);
        $this->password($password);
        $workspace = Inventory::text($workspace, 80, 'Workspace name');
        return $this->write(function () use ($name, $email, $password, $workspace) {
            if ($this->exists($email)) {
                throw new DomainException('This email is already registered. Sign in instead.');
            }
            $this->db
                ->prepare('INSERT INTO tenants(name,created_at) VALUES(?,?)')
                ->execute([$workspace, gmdate('Y-m-d H:i:s')]);
            $tenant = (int) $this->db->lastInsertId();
            return $this->user($tenant, $name, $email, $password, 'owner');
        });
    }

    public function login(string $email, string $password): int
    {
        $email = strtolower(trim($email));
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Email or password is incorrect.');
        }
        $q = $this->db->prepare('SELECT id,password FROM users WHERE email=? AND is_active=1');
        $q->execute([$email]);
        $u = $q->fetch();
        $valid = password_verify($password, $u['password'] ?? self::DUMMY_HASH);
        if (!$u || !$valid) {
            throw new DomainException('Email or password is incorrect.');
        }
        return (int) $u['id'];
    }

    public function demo(): int
    {
        $id = $this->write(function () {
            $this->db
                ->prepare('INSERT INTO tenants(name,is_demo,expires_at,created_at) VALUES(?,1,?,?)')
                ->execute([
                    'Demo warehouse',
                    gmdate('Y-m-d H:i:s', time() + 86400),
                    gmdate('Y-m-d H:i:s'),
                ]);
            $tid = (int) $this->db->lastInsertId();
            return $this->user(
                $tid,
                'Demo owner',
                'demo-' . bin2hex(random_bytes(12)) . '@example.invalid',
                bin2hex(random_bytes(32)),
                'owner',
            );
        });
        $a = Actor::load($this->db, $id);
        $s = new Inventory($this->db);
        $seed = [
            ['PKG-001', 'Recycled mailer · Medium', 120, 30, 24],
            ['PKG-002', 'Shipping carton · Small', 64, 20, 12],
            ['PKG-003', 'Kraft tape · 50 m', 18, 10, 10],
            ['STN-001', 'A5 grid notebook', 80, 15, 8],
            ['STN-002', 'Black fine liner · Pack', 42, 12, 6],
            ['OPS-001', 'Barcode label roll', 15, 10, 8],
            ['OPS-002', 'Thermal receipt roll', 38, 10, 5],
            ['OPS-003', 'Cotton packing gloves', 9, 6, 4],
        ];
        foreach ($seed as $i => [$sku, $name, $qty, $level, $held]) {
            $item = $s->createItem($a, $sku, $name, $level);
            $s->receive(
                $a,
                $item,
                $qty,
                'Synthetic inbound #' . (2001 + $i),
                bin2hex(random_bytes(16)),
            );
            if ($held) {
                $s->reserve(
                    $a,
                    $item,
                    $held,
                    'Synthetic order #' . (1041 + $i),
                    bin2hex(random_bytes(16)),
                );
            }
        }
        return $id;
    }

    public function invite(Actor $a, string $email, string $role): string
    {
        $a->require('team');
        if ($a->demo) {
            throw new DomainException('Team management is available in account workspaces.');
        }
        $email = $this->email($email);
        $this->role($role);
        $token = bin2hex(random_bytes(32));
        $this->write(function () use ($a, $email, $role, $token) {
            if ($this->exists($email)) {
                throw new DomainException(
                    'That email already has an account. This demo supports one workspace per account.',
                );
            }
            $q = $this->db->prepare('SELECT count(*) FROM users WHERE tenant_id=? AND is_active=1');
            $q->execute([$a->tenantId]);
            if ((int) $q->fetchColumn() >= 20) {
                throw new DomainException('Workspaces are limited to 20 active members.');
            }
            $q = $this->db->prepare(
                'SELECT count(*) FROM invitations WHERE tenant_id=? AND accepted_at IS NULL AND expires_at>?',
            );
            $q->execute([$a->tenantId, gmdate('Y-m-d H:i:s')]);
            if ((int) $q->fetchColumn() >= 20) {
                throw new DomainException('A maximum of 20 invitations can be pending.');
            }
            $this->db
                ->prepare(
                    'INSERT INTO invitations(tenant_id,email,role,token_hash,expires_at,created_at) VALUES(?,?,?,?,?,?)',
                )
                ->execute([
                    $a->tenantId,
                    $email,
                    $role,
                    hash('sha256', $token),
                    gmdate('Y-m-d H:i:s', time() + 86400),
                    gmdate('Y-m-d H:i:s'),
                ]);
        });
        return $token;
    }

    public function invitation(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $q = $this->db->prepare(
            'SELECT i.*,t.name AS workspace FROM invitations i JOIN tenants t ON t.id=i.tenant_id WHERE token_hash=? AND accepted_at IS NULL AND i.expires_at>?',
        );
        $q->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        return $q->fetch() ?: null;
    }

    public function accept(string $token, string $name, string $password): int
    {
        $name = Inventory::text($name, 80, 'Your name');
        $this->password($password);
        return $this->write(function () use ($token, $name, $password) {
            $invite = $this->invitation($token);
            if (!$invite) {
                throw new DomainException('This invitation is invalid, expired or already used.');
            }
            if ($this->exists($invite['email'])) {
                throw new DomainException('An account already uses this invitation email.');
            }
            $q = $this->db->prepare('SELECT count(*) FROM users WHERE tenant_id=? AND is_active=1');
            $q->execute([$invite['tenant_id']]);
            if ((int) $q->fetchColumn() >= 20) {
                throw new DomainException('This workspace has reached its member limit.');
            }
            $id = $this->user(
                (int) $invite['tenant_id'],
                $name,
                $invite['email'],
                $password,
                $invite['role'],
            );
            $this->db
                ->prepare('UPDATE invitations SET accepted_at=? WHERE id=? AND accepted_at IS NULL')
                ->execute([gmdate('Y-m-d H:i:s'), $invite['id']]);
            return $id;
        });
    }

    public function updateMember(Actor $a, int $id, string $role, bool $remove = false): void
    {
        $a->require('team');
        if ($a->demo) {
            throw new DomainException('Demo team membership cannot be changed.');
        }
        if (!$remove) {
            $this->role($role);
        }
        $this->write(function () use ($a, $id, $role, $remove) {
            $q = $this->db->prepare(
                'SELECT role FROM users WHERE tenant_id=? AND id=? AND is_active=1',
            );
            $q->execute([$a->tenantId, $id]);
            $old = $q->fetchColumn();
            if (!$old) {
                throw new DomainException('Member was not found in this workspace.');
            }
            if ($old === 'owner') {
                throw new DomainException('The workspace owner cannot be removed or demoted.');
            }
            if ($remove) {
                $this->db
                    ->prepare('UPDATE users SET is_active=0 WHERE id=? AND tenant_id=?')
                    ->execute([$id, $a->tenantId]);
            } else {
                $this->db
                    ->prepare('UPDATE users SET role=? WHERE id=? AND tenant_id=?')
                    ->execute([$role, $id, $a->tenantId]);
            }
        });
    }

    public function members(Actor $a): array
    {
        $q = $this->db->prepare(
            'SELECT id,name,email,role FROM users WHERE tenant_id=? AND is_active=1 ORDER BY id',
        );
        $q->execute([$a->tenantId]);
        return $q->fetchAll();
    }

    public function throttle(
        string $scope,
        string $identity,
        int $limit = 8,
        int $seconds = 600,
    ): void {
        $key = hash('sha256', $scope . '|' . $identity);
        $now = time();
        $q = $this->db->prepare(
            'INSERT INTO rate_limits(id,window_start,attempts) VALUES(?,?,1) ON CONFLICT(id) DO UPDATE SET attempts=CASE WHEN window_start<=? THEN 1 ELSE attempts+1 END,window_start=CASE WHEN window_start<=? THEN ? ELSE window_start END',
        );
        $q->execute([$key, $now, $now - $seconds, $now - $seconds, $now]);
        $q = $this->db->prepare('SELECT attempts FROM rate_limits WHERE id=?');
        $q->execute([$key]);
        if ((int) $q->fetchColumn() > $limit) {
            throw new DomainException(
                'Too many attempts. Please wait a few minutes before trying again.',
            );
        }
    }

    private function user(
        int $tenant,
        string $name,
        string $email,
        string $password,
        string $role,
    ): int {
        $this->db
            ->prepare(
                'INSERT INTO users(tenant_id,name,email,password,role,created_at) VALUES(?,?,?,?,?,?)',
            )
            ->execute([
                $tenant,
                $name,
                $email,
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                $role,
                gmdate('Y-m-d H:i:s'),
            ]);
        return (int) $this->db->lastInsertId();
    }

    private function exists(string $email): bool
    {
        $q = $this->db->prepare('SELECT id FROM users WHERE email=?');
        $q->execute([$email]);
        return (bool) $q->fetchColumn();
    }

    private function email(string $email): string
    {
        $value = strtolower(trim($email));
        if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid email address.');
        }
        return $value;
    }

    private function password(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Use a password between 12 and 72 bytes.');
        }
    }

    private function role(string $role): void
    {
        if (!in_array($role, ['operator', 'viewer'], true)) {
            throw new DomainException(
                'Choose operator or viewer. Owner access cannot be assigned.',
            );
        }
    }

    private function write(callable $apply): mixed
    {
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
            throw $e;
        }
    }
}
