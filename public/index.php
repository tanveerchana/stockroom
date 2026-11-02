<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
use Stockroom\{Database, Inventory, Auth, Actor, Forbidden};
header(
    "Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    if (!is_string($v)) {
        throw new DomainException('Invalid form value.');
    }
    return $v;
}
function numberId(string $key): int
{
    $v = input($key);
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $v)) {
        throw new DomainException('Choose a valid record.');
    }
    return (int) $v;
}
function redirect(string $page): never
{
    header('Location: /?page=' . $page, true, 303);
    exit();
}
function fields(string $action, string $page): void
{
    echo '<input type="hidden" name="csrf" value="' .
        e($_SESSION['csrf']) .
        '"><input type="hidden" name="action" value="' .
        e($action) .
        '"><input type="hidden" name="return" value="' .
        e($page) .
        '"><input type="hidden" name="request_key" value="' .
        bin2hex(random_bytes(16)) .
        '">';
}
function signIn(int $id): void
{
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $id, 'csrf' => bin2hex(random_bytes(32)), 'last_seen' => time()];
}
function status(string $status): string
{
    return '<span class="status ' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}
function qty(mixed $v): string
{
    return number_format((int) $v);
}

try {
    $ephemeralHosting = getenv('STOCKROOM_EPHEMERAL_HOSTING') === 'true';
    $path = getenv('DB_PATH') ?: __DIR__ . '/../storage/stockroom.sqlite';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    $sessionPath = getenv('SESSION_PATH') ?: __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '7200');
    session_save_path($sessionPath);
    $production = getenv('APP_ENV') !== 'local';
    session_name($production ? '__Host-stockroom' : 'stockroom-local');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $production,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['last_seen'] = time();
    $db = Database::open($path);
    $inventory = new Inventory($db);
    $auth = new Auth($db);
    $actor = isset($_SESSION['user_id']) ? Actor::load($db, (int) $_SESSION['user_id']) : null;
    if (!$actor) {
        unset($_SESSION['user_id']);
    }
    $allowed = [
        'overview',
        'catalog',
        'reservations',
        'movements',
        'team',
        'login',
        'register',
        'join',
        'low-stock',
    ];
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'overview';
    if (!in_array($page, $allowed, true)) {
        $page = 'overview';
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            !isset($_POST['csrf']) ||
            !is_string($_POST['csrf']) ||
            !hash_equals($_SESSION['csrf'], $_POST['csrf'])
        ) {
            http_response_code(419);
            $errorTitle = 'This form has expired';
            $errorText = 'Reload the page before submitting again.';
            require __DIR__ . '/../views/error.php';
            exit();
        }
        $return = input('return', 'overview');
        if (!in_array($return, $allowed, true)) {
            $return = 'overview';
        }
        try {
            $action = input('action');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (in_array($action, ['login', 'register', 'demo', 'accept'], true)) {
                $auth->throttle('all-auth', $ip, 30, 600);
                $auth->throttle($action, $ip . '|' . strtolower(input('email')), 8, 600);
                if ($action === 'login') {
                    $id = $auth->login(input('email'), input('password'));
                } elseif ($action === 'register') {
                    $password = input('password');
                    if ($password !== input('password_confirmation')) {
                        throw new DomainException('Password confirmation does not match.');
                    }
                    $id = $auth->register(
                        input('name'),
                        input('email'),
                        $password,
                        input('workspace'),
                    );
                } elseif ($action === 'accept') {
                    $password = input('password');
                    if ($password !== input('password_confirmation')) {
                        throw new DomainException('Password confirmation does not match.');
                    }
                    $id = $auth->accept(input('token'), input('name'), $password);
                } else {
                    $id = $auth->demo();
                }
                signIn($id);
                redirect('overview');
            }
            if (!$actor) {
                redirect('login');
            }
            switch ($action) {
                case 'logout':
                    $_SESSION = [];
                    session_regenerate_id(true);
                    redirect('login');
                // No fall-through: redirect() exits the request.
                case 'create_item':
                    $inventory->createItem(
                        $actor,
                        input('sku'),
                        input('name'),
                        input('reorder_level'),
                    );
                    $message = 'Catalog item created. Receive stock to add its first units.';
                    break;
                case 'receipt':
                    $inventory->receive(
                        $actor,
                        numberId('item_id'),
                        input('quantity'),
                        input('reference'),
                        input('request_key'),
                    );
                    $message = 'Receipt recorded. Stock and audit ledger updated together.';
                    break;
                case 'reserve':
                    $inventory->reserve(
                        $actor,
                        numberId('item_id'),
                        input('quantity'),
                        input('reference'),
                        input('request_key'),
                    );
                    $message = 'Stock reserved. Available quantity has been updated.';
                    break;
                case 'release':
                case 'fulfil':
                    $inventory->close($actor, numberId('reservation_id'), $action);
                    $message =
                        $action === 'release'
                            ? 'Reservation released. Units are available again.'
                            : 'Reservation fulfilled. On-hand and reserved stock reduced.';
                    break;
                case 'invite':
                    $token = $auth->invite($actor, input('email'), input('role'));
                    $message =
                        'Invitation created. Copy this link and share it directly with the intended recipient. It expires in 24 hours.';
                    $_SESSION['invite_link'] = '/?page=join&token=' . $token;
                    break;
                case 'member_role':
                    $auth->updateMember($actor, numberId('member_id'), input('role'));
                    $message = 'Member role updated. New permissions apply on their next request.';
                    break;
                case 'remove_member':
                    $auth->updateMember($actor, numberId('member_id'), 'viewer', true);
                    $message = 'Member access removed. Historical audit records are retained.';
                    break;
                default:
                    throw new DomainException('Unknown action.');
            }
            $_SESSION['flash'] = ['kind' => 'success', 'message' => $message];
            redirect($return);
        } catch (Forbidden $ex) {
            http_response_code(403);
            $errorTitle = 'Action not permitted';
            $errorText = $ex->getMessage();
            require __DIR__ . '/../views/error.php';
            exit();
        } catch (DomainException $ex) {
            $_SESSION['flash'] = ['kind' => 'error', 'message' => $ex->getMessage()];
            $_SESSION['old'] = array_filter(
                array_intersect_key(
                    $_POST,
                    array_flip([
                        'name',
                        'email',
                        'workspace',
                        'sku',
                        'reorder_level',
                        'quantity',
                        'reference',
                    ]),
                ),
                'is_string',
            );
            if ($action === 'accept' && preg_match('/^[a-f0-9]{64}$/D', input('token'))) {
                redirect('join&token=' . input('token'));
            }
            redirect($return);
        }
    }
    if (!$actor && !in_array($page, ['login', 'register', 'join'], true)) {
        redirect('login');
    }
    if ($actor && in_array($page, ['login', 'register'], true)) {
        redirect('overview');
    }
    $joinToken = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    $invitation = $page === 'join' ? $auth->invitation($joinToken) : null;
    $old = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    if (!$actor || $page === 'join') {
        require __DIR__ . '/../views/auth.php';
        exit();
    }
    $items = $inventory->items($actor);
    $reservations = $inventory->reservations($actor);
    $movements = $inventory->movements($actor);
    $members = $page === 'team' ? $auth->members($actor) : [];
    $lowStock = array_values(
        array_filter($items, fn($i) => (int) $i['available'] <= (int) $i['reorder_level']),
    );
    $active = array_values(array_filter($reservations, fn($r) => $r['status'] === 'active'));
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stockroom-movements.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'movement_id',
            'sku',
            'type',
            'quantity',
            'on_hand_delta',
            'reserved_delta',
            'on_hand_after',
            'reserved_after',
            'reference',
            'actor',
            'created_utc',
        ]);
        foreach ($movements as $m) {
            $row = [
                $m['id'],
                $m['sku'],
                $m['kind'],
                $m['quantity'],
                $m['on_hand_delta'],
                $m['reserved_delta'],
                $m['on_hand_after'],
                $m['reserved_after'],
                $m['reference'],
                $m['actor'],
                $m['created_at'],
            ];
            $row = array_map(
                fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                $row,
            );
            fputcsv($out, $row);
        }
        fclose($out);
        exit();
    }
    require __DIR__ . '/../views/app.php';
} catch (Throwable $ex) {
    error_log('Stockroom request failed: ' . get_class($ex));
    http_response_code(503);
    $errorTitle = 'Workspace temporarily unavailable';
    $errorText = 'The workspace could not load or save. Please try again.';
    require __DIR__ . '/../views/error.php';
}
