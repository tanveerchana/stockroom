<?php

declare(strict_types=1);

namespace Stockroom;

use PDO;

final readonly class Actor
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $name,
        public string $email,
        public string $role,
        public string $workspace,
        public bool $demo,
    ) {}

    public static function load(PDO $db, int $id): ?self
    {
        $q = $db->prepare(
            'SELECT u.*,t.name AS workspace,t.is_demo,t.expires_at FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? AND u.is_active=1',
        );
        $q->execute([$id]);
        $u = $q->fetch();
        if (!$u || ($u['expires_at'] !== null && $u['expires_at'] <= gmdate('Y-m-d H:i:s'))) {
            return null;
        }
        return new self(
            (int) $u['id'],
            (int) $u['tenant_id'],
            $u['name'],
            $u['email'],
            $u['role'],
            $u['workspace'],
            (bool) $u['is_demo'],
        );
    }

    public function can(string $action): bool
    {
        return $action === 'read' ||
            $this->role === 'owner' ||
            ($this->role === 'operator' &&
                in_array($action, ['receipt', 'reserve', 'release', 'fulfil'], true));
    }

    public function require(string $action): void
    {
        if (!$this->can($action)) {
            throw new Forbidden('Your workspace role does not allow this action.');
        }
    }
}
