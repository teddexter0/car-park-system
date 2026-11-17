<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class RequestError extends RuntimeException {}

function fail(int $status, string $message): void
{
    throw new RequestError($message, $status);
}

function field(array $data, string $key, int $limit, bool $required = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value = trim($value)) > $limit || ($required && $value === '')) {
        fail(422, 'Check the ' . str_replace('_', ' ', $key) . ' field.');
    }
    return $value;
}

function integer(array $data, string $key, int $max = 4294967295): int
{
    $value = $data[$key] ?? null;
    $number = is_string($value) || is_int($value)
        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]])
        : false;
    if ($number === false) {
        fail(422, 'Choose a valid ' . str_replace('_', ' ', $key) . '.');
    }
    return $number;
}

function flag(array $data, string $key, bool $default = false): bool
{
    $value = $data[$key] ?? $default;
    if (!is_bool($value)) {
        fail(422, 'Invalid checkbox value.');
    }
    return $value;
}

function login(array $data): array
{
    $email = strtolower(field($data, 'email', 150));
    $password = $data['password'] ?? null;
    if (!is_string($password) || $password === '' || strlen($password) > 72) {
        fail(422, 'Check the password field.');
    }
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = time();
    $result = transaction(function () use ($email, $password, $key, $now): array {
        sql('INSERT IGNORE INTO login_attempts (scope_key, window_started) VALUES (?, ?)', [$key, $now]);
        $rate = sql('SELECT attempts, window_started FROM login_attempts WHERE scope_key = ? FOR UPDATE', [$key])->fetch();
        $attempts = $now - (int) $rate['window_started'] < 900 ? (int) $rate['attempts'] : 0;
        if ($attempts >= 8) {
            return ['status' => 429, 'message' => 'Too many failed attempts. Try again in 15 minutes.'];
        }
        $user = sql("SELECT u.id, u.name, u.email, u.role, u.password_hash
            FROM users u LEFT JOIN mock_ams m ON m.id = u.ams_id
            WHERE u.email = ? AND u.active = 1 AND (u.ams_id IS NULL OR (m.active = 1 AND m.category = u.role))",
            [$email])->fetch();
        $fallback = '$2y$10$EP645PfCvUHKb2qdOi7sIuztPloz9g8zDs6FE7p/g0nKY4U3qfulm';
        if (!password_verify($password, $user['password_hash'] ?? $fallback) || !$user) {
            sql('UPDATE login_attempts SET attempts = ?, window_started = ? WHERE scope_key = ?',
                [$attempts + 1, $attempts ? $rate['window_started'] : $now, $key]);
            audit(null, 'login_failed', 'authentication', null);
            return ['status' => 401, 'message' => 'Email or password is incorrect.'];
        }
        audit((int) $user['id'], 'login', 'authentication', (int) $user['id']);
        unset($user['password_hash']);
        return ['user' => $user];
    });
    if (isset($result['status'])) {
        fail($result['status'], $result['message']);
    }
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $result['user']['id'], 'last_activity' => time(), 'csrf' => bin2hex(random_bytes(32))];
    return $result + ['csrf' => $_SESSION['csrf']];
}

function zones(bool $includeInactive = false, ?array $viewer = null): array
{
    $rows = sql('SELECT z.id, z.name, z.description, z.capacity, z.active,
        COALESCE(g.total, 0) AS gate_occupied, b.bay_number, b.status, b.student_id, b.booking_label
        FROM parking_zones z
        LEFT JOIN (SELECT zone_id, COUNT(*) AS total FROM parking_sessions WHERE checkout_at IS NULL GROUP BY zone_id) g
            ON g.zone_id = z.id
        LEFT JOIN parking_bays b ON b.zone_id = z.id
        WHERE (? = 1 OR z.active = 1) ORDER BY z.id, b.bay_number', [(int) $includeInactive]);
    $zones = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $zones[$id] ??= array_diff_key($row, array_flip(['bay_number', 'status', 'student_id', 'booking_label'])) + [
            'occupied' => (int) $row['gate_occupied'],
            'available' => (int) $row['capacity'] - (int) $row['gate_occupied'],
            'bays' => [],
        ];
        if ($row['bay_number'] !== null) {
            $zones[$id]['available']--;
            $zones[$id]['occupied'] += (int) ($row['status'] === 'occupied');
            $bay = ['number' => (int) $row['bay_number'], 'status' => $row['status'],
                'mine' => $row['student_id'] !== null && (int) $row['student_id'] === (int) ($viewer['id'] ?? 0)];
            if (($viewer['role'] ?? '') === 'admin' && $row['booking_label'] !== null) {
                $bay['label'] = $row['booking_label'];
            }
            $zones[$id]['bays'][] = $bay;
        }
    }
    return array_values($zones);
}

function dashboard(?array $viewer = null): array
{
    $list = zones(false, $viewer);
    $capacity = array_sum(array_column($list, 'capacity'));
    $occupied = array_sum(array_column($list, 'occupied'));
    return ['zones' => $list, 'capacity' => $capacity, 'occupied' => $occupied,
        'available' => array_sum(array_column($list, 'available')),
        'percentage' => $capacity ? round($occupied * 100 / $capacity) : 0];
}

function lockZone(int $id): array
{
    $zone = sql('SELECT * FROM parking_zones WHERE id = ? FOR UPDATE', [$id])->fetch();
    if (!$zone) {
        fail(404, 'That parking zone no longer exists.');
    }
    return $zone;
}

function occupancy(int $zone): int
{
    return (int) sql('SELECT COUNT(*) FROM parking_sessions WHERE zone_id = ? AND checkout_at IS NULL', [$zone])->fetchColumn();
}

function requireSpace(array $zone): void
{
    $id = (int) $zone['id'];
    $held = (int) sql('SELECT COUNT(*) FROM parking_bays WHERE zone_id = ?', [$id])->fetchColumn();
    if (!$zone['active'] || occupancy($id) + $held >= (int) $zone['capacity']) {
        fail(409, 'The selected zone is full or inactive.');
    }
}

function changeBay(string $action, array $data, int $actor): array
{
    $zoneId = integer($data, 'zone_id');
    $number = integer($data, 'bay_number', 9999);
    $current = field($data, 'current', 16);
    $status = match ($action) {
        'bay_occupy' => 'occupied',
        'bay_release' => 'available',
        'bay_manage' => field($data, 'status', 16),
    };
    $adminStates = ['available', 'booked', 'unavailable'];
    if ($action === 'bay_manage' && (!in_array($current, $adminStates, true) || !in_array($status, $adminStates, true))) {
        fail(422, 'Choose a valid bay status.');
    }
    $label = $status === 'booked' ? field($data, 'booking_label', 100) : null;
    return transaction(function () use ($action, $actor, $zoneId, $number, $current, $status, $label): array {
        $zone = lockZone($zoneId);
        if (!$zone['active'] || $number > (int) $zone['capacity']) {
            fail(409, 'That bay is no longer in an active parking zone.');
        }
        $bay = sql('SELECT status, student_id FROM parking_bays WHERE zone_id = ? AND bay_number = ?', [$zoneId, $number])->fetch();
        $previous = $bay['status'] ?? 'available';
        if (!$bay) {
            // Gate sessions only identify a zone. Map them to the first bays without explicit state.
            $earlier = (int) sql('SELECT COUNT(*) FROM parking_bays WHERE zone_id = ? AND bay_number < ?',
                [$zoneId, $number])->fetchColumn();
            if ($number - $earlier <= occupancy($zoneId)) {
                $previous = 'occupied';
            }
        }
        if ($action === 'bay_release' && (int) ($bay['student_id'] ?? 0) !== $actor) {
            fail(409, 'You can only release the bay you occupied.');
        }
        if ($previous !== $current || ($action === 'bay_occupy' && $previous !== 'available')) {
            fail(409, 'That bay has changed. Refresh and choose again.');
        }
        if ($status === 'available') {
            sql('DELETE FROM parking_bays WHERE zone_id = ? AND bay_number = ?', [$zoneId, $number]);
        } elseif ($bay) {
            sql('UPDATE parking_bays SET status = ?, booking_label = ? WHERE zone_id = ? AND bay_number = ?',
                [$status, $label, $zoneId, $number]);
        } else {
            sql('INSERT INTO parking_bays (zone_id, bay_number, status, student_id, booking_label) VALUES (?,?,?,?,?)',
                [$zoneId, $number, $status, $status === 'occupied' ? $actor : null, $label]);
        }
        audit($actor, $action, 'parking_bay', $zoneId,
            ['bay_number' => $number, 'previous_status' => $previous, 'status' => $status, 'booking_label' => $label]);
        return ['status' => $status];
    });
}

function changeZone(string $action, array $data, int $actor): array
{
    return transaction(function () use ($action, $data, $actor): array {
        $id = $action === 'zone_create' ? null : integer($data, 'id');
        $zone = $id === null ? null : lockZone($id);
        if ($action === 'zone_delete') {
            if (sql('SELECT 1 FROM parking_sessions WHERE zone_id = ? LIMIT 1', [$id])->fetchColumn()) {
                fail(409, 'This zone has parking history. Deactivate it after all vehicles leave.');
            }
            if (sql('SELECT 1 FROM parking_bays WHERE zone_id = ? LIMIT 1', [$id])->fetchColumn()) {
                fail(409, 'Release the occupied, booked and unavailable bays before deleting this zone.');
            }
            sql('DELETE FROM parking_zones WHERE id = ?', [$id]);
            audit($actor, 'zone_deleted', 'zone', $id, ['name' => $zone['name']]);
            return ['id' => $id];
        }
        $name = field($data, 'name', 80);
        $description = field($data, 'description', 250, false);
        $capacity = integer($data, 'capacity', 9999);
        $active = flag($data, 'active', true);
        if ($zone) {
            $occupied = occupancy($id);
            $bays = sql('SELECT COUNT(*) AS total, MAX(bay_number) AS highest FROM parking_bays WHERE zone_id = ?', [$id])->fetch();
            $used = $occupied + (int) $bays['total'];
            if ($capacity < max($used, (int) $bays['highest']) || (!$active && $used > 0)) {
                fail(409, 'Capacity must cover occupied and held bays. Clear all bays before deactivation.');
            }
            sql('UPDATE parking_zones SET name = ?, description = ?, capacity = ?, active = ? WHERE id = ?',
                [$name, $description, $capacity, (int) $active, $id]);
            audit($actor, !$active && $zone['active'] ? 'zone_deactivated' : 'zone_updated', 'zone', $id,
                ['previous_capacity' => (int) $zone['capacity'], 'capacity' => $capacity, 'active' => $active]);
        } else {
            sql('INSERT INTO parking_zones (name, description, capacity, active) VALUES (?, ?, ?, ?)',
                [$name, $description, $capacity, (int) $active]);
            $id = (int) db()->lastInsertId();
            audit($actor, 'zone_created', 'zone', $id, ['name' => $name, 'capacity' => $capacity]);
        }
        return ['id' => $id];
    });
}

function amsUsers(): array
{
    $query = field($_GET, 'q', 80, false);
    return sql("SELECT u.id, m.institutional_id, m.name, m.category
        FROM mock_ams m JOIN users u ON u.ams_id = m.id AND u.role = m.category
        WHERE m.active = 1 AND u.active = 1
        AND (LOCATE(?, m.name) > 0 OR LOCATE(?, m.institutional_id) > 0 OR ? = '')
        ORDER BY m.name LIMIT 30", [$query, $query, $query])->fetchAll();
}

function checkin(array $data, int $actor): array
{
    $zoneId = integer($data, 'zone_id');
    $plate = strtoupper(preg_replace('/\s+/', '', field($data, 'vehicle_plate', 32)));
    if (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,15}$/D', $plate)) {
        fail(422, 'Enter a vehicle registration of 3–16 letters, digits or hyphens.');
    }
    $kind = field($data, 'kind', 20);
    if (!in_array($kind, ['institutional', 'visitor'], true)) {
        fail(422, 'Choose a valid driver type.');
    }
    return transaction(function () use ($data, $actor, $plate, $zoneId, $kind): array {
        requireSpace(lockZone($zoneId));
        if (sql('SELECT 1 FROM parking_sessions WHERE active_plate = ?', [$plate])->fetchColumn()) {
            fail(409, 'That vehicle already has an active parking session.');
        }
        $userId = $visitorId = null;
        if ($kind === 'visitor') {
            $inspected = flag($data, 'id_inspected');
            $retained = flag($data, 'id_retained');
            if ($retained && !$inspected) {
                fail(422, 'Confirm the physical ID was inspected before marking it retained.');
            }
            sql('INSERT INTO visitors (name, purpose, destination, id_inspected, id_retained) VALUES (?,?,?,?,?)', [
                field($data, 'name', 100), field($data, 'purpose', 120), field($data, 'destination', 100), (int) $inspected, (int) $retained,
            ]);
            $visitorId = (int) db()->lastInsertId();
        } else {
            $userId = integer($data, 'user_id');
            if (!sql("SELECT 1 FROM users u JOIN mock_ams m ON m.id = u.ams_id AND m.category = u.role
                WHERE u.id = ? AND u.active = 1 AND m.active = 1", [$userId])->fetchColumn()) {
                fail(422, 'Select an active student or staff member from the simulated AMS.');
            }
        }
        sql('INSERT INTO parking_sessions (user_id, visitor_id, vehicle_plate, zone_id) VALUES (?,?,?,?)',
            [$userId, $visitorId, $plate, $zoneId]);
        $id = (int) db()->lastInsertId();
        audit($actor, 'vehicle_entry', 'parking_session', $id, ['zone_id' => $zoneId, 'driver_type' => $kind]);
        return ['id' => $id];
    });
}

function changeSession(string $action, array $data, int $actor): array
{
    $id = integer($data, 'id');
    $reason = $action === 'checkout' ? '' : field($data, 'reason', 250);
    return transaction(function () use ($action, $data, $actor, $id, $reason): array {
        $session = sql('SELECT * FROM parking_sessions WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$session || $session['checkout_at'] !== null) {
            fail(409, 'This parking session is no longer active.');
        }
        $source = (int) $session['zone_id'];
        if ($action === 'transfer') {
            $target = integer($data, 'zone_id');
            if ($source === $target) {
                fail(422, 'Choose a different parking zone.');
            }
            $first = lockZone(min($source, $target));
            $second = lockZone(max($source, $target));
            requireSpace($first['id'] == $target ? $first : $second);
            sql('UPDATE parking_sessions SET zone_id = ? WHERE id = ?', [$target, $id]);
            audit($actor, 'zone_transfer', 'parking_session', $id, ['from_zone_id' => $source, 'to_zone_id' => $target, 'reason' => $reason]);
            return ['id' => $id];
        }
        lockZone($source);
        sql('UPDATE parking_sessions SET checkout_at = NOW() WHERE id = ?', [$id]);
        audit($actor, $action === 'checkout' ? 'vehicle_exit' : 'checkout_corrected', 'parking_session', $id,
            $reason === '' ? ['zone_id' => $source] : ['zone_id' => $source, 'reason' => $reason]);
        $retained = $session['visitor_id'] && sql('SELECT id_retained FROM visitors WHERE id = ?', [$session['visitor_id']])->fetchColumn();
        return ['id' => $id, 'return_id' => (bool) $retained];
    });
}

function sessions(bool $activeOnly): array
{
    $query = strtoupper(preg_replace('/\s+/', '', field($_GET, 'q', 32, false)));
    $filter = $activeOnly ? 'AND s.checkout_at IS NULL' : '';
    return sql("SELECT s.id, s.vehicle_plate, s.zone_id, s.checkin_at, s.checkout_at, z.name AS zone,
        COALESCE(m.name, v.name) AS driver, COALESCE(m.category, 'visitor') AS driver_type,
        COALESCE(v.id_retained, 0) AS id_retained, v.destination, v.purpose,
        (s.checkout_at IS NULL AND s.checkin_at < NOW() - INTERVAL 12 HOUR) AS needs_review
        FROM parking_sessions s JOIN parking_zones z ON z.id = s.zone_id
        LEFT JOIN users u ON u.id = s.user_id LEFT JOIN mock_ams m ON m.id = u.ams_id
        LEFT JOIN visitors v ON v.id = s.visitor_id
        WHERE (LOCATE(?, s.vehicle_plate) > 0 OR CAST(s.id AS CHAR) = ? OR ? = '') $filter
        ORDER BY s.id DESC LIMIT 100", [$query, $query, $query])->fetchAll();
}

function report(): array
{
    $counts = sql('SELECT
        (SELECT COUNT(*) FROM parking_sessions WHERE checkin_at >= CURRENT_DATE AND checkin_at < CURRENT_DATE + INTERVAL 1 DAY) AS entries,
        (SELECT COUNT(*) FROM parking_sessions WHERE checkout_at >= CURRENT_DATE AND checkout_at < CURRENT_DATE + INTERVAL 1 DAY) AS exits,
        (SELECT COUNT(*) FROM parking_sessions WHERE visitor_id IS NOT NULL AND checkout_at IS NULL) AS active_visitors')->fetch();
    return $counts + dashboard() + ['sessions' => sessions(false)];
}

function updateAccount(array $data, int $actor): array
{
    $id = integer($data, 'id');
    $active = flag($data, 'active');
    if ($id === $actor) {
        fail(409, 'You cannot change the status of your own account.');
    }
    return transaction(function () use ($id, $active, $actor): array {
        $user = sql('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$user) {
            fail(404, 'That account no longer exists.');
        }
        sql('UPDATE users SET active = ? WHERE id = ?', [(int) $active, $id]);
        audit($actor, 'account_status_changed', 'user', $id, ['active' => $active]);
        return ['id' => $id];
    });
}

try {
    $action = field($_GET, 'action', 30);
    $reads = ['state', 'dashboard', 'ams', 'active', 'zones', 'audit', 'report', 'accounts'];
    $writes = ['login', 'logout', 'zone_create', 'zone_update', 'zone_delete', 'checkin', 'checkout', 'transfer', 'correct_checkout',
        'account_status', 'bay_occupy', 'bay_release', 'bay_manage'];
    if (!in_array($action, array_merge($reads, $writes), true)) {
        fail(404, 'Unknown action.');
    }
    $method = in_array($action, $reads, true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header("Allow: $method");
        fail(405, "Use $method for this action.");
    }
    startParkingSession();
    $data = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            fail(403, 'Your session token expired. Refresh and try again.');
        }
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($body) > 16384) {
            fail(413, 'This request is too large.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            fail(400, 'Send a JSON request body.');
        }
    }
    $user = currentUser();
    if ($action === 'state') {
        if ($user) {
            $_SESSION['last_activity'] = time();
        }
        $result = ['user' => $user, 'csrf' => $_SESSION['csrf'], 'demo' => localDemo()];
    } elseif ($action === 'login') {
        $result = login($data);
    } else {
        if (!$user) {
            fail(401, 'Sign in to continue.');
        }
        $adminOnly = ['zones', 'zone_create', 'zone_update', 'zone_delete', 'audit', 'report', 'accounts', 'account_status', 'bay_manage'];
        $gateOnly = ['ams', 'active', 'checkin', 'checkout', 'transfer', 'correct_checkout'];
        if ((in_array($action, $adminOnly, true) && $user['role'] !== 'admin')
            || (in_array($action, $gateOnly, true) && !in_array($user['role'], ['security', 'admin'], true))
            || (in_array($action, ['bay_occupy', 'bay_release'], true) && $user['role'] !== 'student')) {
            fail(403, 'Your role does not have access to this action.');
        }
        $actor = (int) $user['id'];
        if ($action !== 'logout') {
            if ($action !== 'dashboard' || !isset($_GET['poll'])) {
                $_SESSION['last_activity'] = time();
            }
            session_write_close();
        }
        $result = match ($action) {
            'logout' => (function () use ($actor): array {
                audit($actor, 'logout', 'authentication', $actor);
                $_SESSION = [];
                session_regenerate_id(true);
                return ['csrf' => $_SESSION['csrf'] = bin2hex(random_bytes(32))];
            })(),
            'dashboard' => dashboard($user),
            'ams' => ['users' => amsUsers()],
            'active' => ['sessions' => sessions(true)],
            'zones' => ['zones' => zones(true)],
            'zone_create', 'zone_update', 'zone_delete' => changeZone($action, $data, $actor),
            'checkin' => checkin($data, $actor),
            'checkout', 'transfer', 'correct_checkout' => changeSession($action, $data, $actor),
            'audit' => ['logs' => sql("SELECT a.id, a.action, a.entity_type, a.entity_id, a.created_at, a.metadata,
                u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 100")->fetchAll()],
            'report' => report(),
            'accounts' => ['accounts' => sql('SELECT u.id, u.name, u.email, u.role, u.active, m.institutional_id
                FROM users u LEFT JOIN mock_ams m ON m.id = u.ams_id ORDER BY u.id')->fetchAll()],
            'account_status' => updateAccount($data, $actor),
            'bay_occupy', 'bay_release', 'bay_manage' => changeBay($action, $data, $actor),
        };
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (RequestError $error) {
    http_response_code($error->getCode());
    echo json_encode(['error' => $error->getMessage()]);
} catch (PDOException $error) {
    $code = (int) ($error->errorInfo[1] ?? 0);
    if ($code === 1062) {
        http_response_code(409);
        echo json_encode(['error' => match ($action ?? '') {
            'checkin' => 'That vehicle already has an active parking session.',
            'bay_occupy' => 'Release your current bay before occupying another one.',
            default => 'A zone with that name already exists.',
        }]);
    } elseif (in_array($code, [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['error' => 'Another operation changed these records. Refresh and try again.']);
    } else {
        error_log($error->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'The parking database is unavailable. Check the database setup and try again.']);
    }
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The request could not be completed.']);
}
