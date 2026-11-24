<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/config.php';

const DEMO_PASSWORD = 'ParkingDemo26!';
$failed = 0;
$passed = 0;
$servers = [];
$schema = 'parking_test_' . bin2hex(random_bytes(6));
$created = false;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scenario(string $id, string $name, callable $test): void
{
    global $failed, $passed;
    try {
        $evidence = $test();
        $passed++;
        echo "$id PASS $name | $evidence\n";
    } catch (Throwable $error) {
        $failed++;
        echo "$id FAIL $name | {$error->getMessage()}\n";
    }
}

function startServer(string $schema, int $idle = 30, int $maxAge = 28800, bool $publicDemo = false): string
{
    global $servers;
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    check($socket !== false, 'Could not reserve a local test port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment = array_merge(getenv(), [
        'PARKING_DB_NAME' => $schema, 'PARKING_TEST_MODE' => '1', 'PARKING_TEST_TIMEOUT' => (string) $idle,
        'PARKING_TEST_MAX_AGE' => (string) $maxAge, 'PARKING_PUBLIC_DEMO' => $publicDemo ? '1' : '0',
    ]);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open([PHP_BINARY, '-S', $address], [
        0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w'],
    ], $pipes, __DIR__, $environment, ['bypass_shell' => true]);
    check(is_resource($process), 'PHP server could not start');
    $servers[] = $process;
    $url = "http://$address/api.php";
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client("tcp://$address", $number, $error, 0.15);
        if ($connection) {
            fclose($connection);
            return $url;
        }
        usleep(50000);
    }
    throw new RuntimeException("PHP server did not become ready at $address");
}

final class Client
{
    private string $csrf = '';
    private string $cookie = '';

    public function __construct(private string $url) {}

    public function target(string $url): void
    {
        $this->url = $url;
    }

    public function session(): array
    {
        return [$this->cookie, $this->csrf];
    }

    public function useSession(array $session): void
    {
        [$this->cookie, $this->csrf] = $session;
    }

    public function handle(string $action, ?array $body = null, array $query = [], bool $csrf = true): CurlHandle
    {
        $curl = curl_init($this->url . '?' . http_build_query(['action' => $action] + $query));
        curl_setopt_array($curl, [
            CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIE => $this->cookie,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $curl, string $header): int {
                if (preg_match('/^Set-Cookie:\s*(strathmore_parking=[^;\s]+)/i', $header, $match)) {
                    $this->cookie = $match[1];
                }
                return strlen($header);
            },
        ]);
        $headers = ['X-CSRF-Token: ' . ($csrf ? $this->csrf : 'invalid')];
        if ($body !== null) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $body, JSON_THROW_ON_ERROR),
            ]);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        return $curl;
    }

    public function request(string $action, int $expected = 200, ?array $body = null, array $query = [], bool $csrf = true): array
    {
        return $this->response($this->handle($action, $body, $query, $csrf), $action, $expected);
    }

    public function rawPost(string $action, string $body, string $type, int $expected): array
    {
        $curl = $this->handle($action);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: $type", 'X-CSRF-Token: ' . $this->csrf],
        ]);
        return $this->response($curl, $action, $expected);
    }

    private function response(CurlHandle $curl, string $action, int $expected): array
    {
        $raw = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        check($raw !== false, "$action transport failed: " . curl_error($curl));
        check($code === $expected, "$action expected HTTP $expected, got $code: $raw");
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result;
    }

    public function login(string $role): array
    {
        $this->request('state');
        return $this->request('login', 200, ['email' => "$role@parking.test", 'password' => DEMO_PASSWORD]);
    }
}

function driver(int $user, string $plate, int $zone): array
{
    return ['kind' => 'institutional', 'user_id' => $user, 'vehicle_plate' => $plate, 'zone_id' => $zone];
}

function zone(array $result, int $id): array
{
    foreach ($result['zones'] as $zone) {
        if ((int) $zone['id'] === $id) {
            return $zone;
        }
    }
    throw new RuntimeException("Zone $id was not found");
}

function race(array $requests, array $zones, PDO $pdo): array
{
    $multi = curl_multi_init();
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM parking_zones WHERE id = ? FOR UPDATE');
    foreach ($zones as $zone) {
        $lock->execute([$zone]);
        $lock->closeCursor();
    }
    try {
        foreach ($requests as $request) {
            curl_multi_add_handle($multi, $request);
        }
        $deadline = microtime(true) + 0.35;
        do {
            check(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrent request transport failed');
            usleep(10000);
        } while ($running && microtime(true) < $deadline);
        check($running === 2, 'Expected both requests to wait on the locked zone');
        $pdo->commit();
        do {
            check(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrent request transport failed');
            if ($running) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running);
        $statuses = array_map(static fn($request) => curl_getinfo($request, CURLINFO_HTTP_CODE), $requests);
        sort($statuses);
        return $statuses;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($requests as $request) {
            curl_multi_remove_handle($multi, $request);
        }
        curl_multi_close($multi);
    }
}

try {
    check(extension_loaded('curl') && extension_loaded('pdo_mysql'), 'Enable PHP cURL and PDO MySQL first.');
    $host = getenv('PARKING_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PARKING_DB_PORT') ?: '3306';
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", getenv('PARKING_DB_USER') ?: 'root',
        getenv('PARKING_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $ddl = file_get_contents(__DIR__ . '/database.sql');
    $ddl = preg_replace('/\ACREATE DATABASE[^;]+;\s*USE[^;]+;\s*/i', '', $ddl);
    $ddl .= file_get_contents(__DIR__ . '/parking-bays.sql');
    $pdo->exec("USE `$schema`");
    foreach (explode(';', $ddl) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    echo "Isolated schema initialized: $schema\n";
    $url = startServer($schema);
    $url2 = startServer($schema);
    $student = new Client($url);
    $admin = new Client($url);
    $security = new Client($url);
    $zoneId = $raceZone = $first = $visitorSession = 0;

    scenario('A01', 'Authentication and simulated AMS', function () use ($student, $url) {
        $student->request('dashboard', 401);
        check(count($student->request('availability')['zones']) === 3, 'The public map did not show all active zones');
        $student->request('state');
        $student->request('bay_occupy', 401, ['zone_id' => 3, 'bay_number' => 1, 'current' => 'available']);
        $student->request('bay_manage', 401, ['zone_id' => 3, 'bay_number' => 1, 'current' => 'available', 'status' => 'unavailable']);
        $student->request('login', 401, ['email' => 'student@parking.test', 'password' => 'wrong']);
        check($student->login('student')['user']['role'] === 'student', 'Unexpected student role');
        check((new Client($url))->login('staff')['user']['role'] === 'staff', 'Unexpected staff role');
        $student->request('dashboard');
        return 'Guests could read availability, anonymous writes returned 401, and student and staff could sign in.';
    });
    scenario('A02', 'Role boundaries, CSRF and logout', function () use ($student, $security) {
        $student->request('zones', 403);
        $student->request('audit', 403);
        $student->request('checkin', 403, driver(1, 'STUD001', 1));
        $security->login('security');
        $security->request('audit', 403);
        $security->request('checkin', 403, driver(1, 'CSRF001', 1), [], false);
        $student->request('logout', 200, []);
        $student->request('dashboard', 401);
        return 'Forbidden roles and forged CSRF returned 403; a logged-out session returned 401.';
    });
    scenario('C01', 'Zone create, read, update and delete', function () use ($admin) {
        $admin->login('admin');
        $id = $admin->request('zone_create', 200, ['name' => 'Temporary Demo', 'description' => 'Test', 'capacity' => 3])['id'];
        check((int) zone($admin->request('zones'), $id)['capacity'] === 3, 'Created zone was missing');
        $admin->request('zone_update', 200, ['id' => $id, 'name' => 'Updated Demo', 'description' => 'Edited', 'capacity' => 5]);
        $updated = zone($admin->request('zones'), $id);
        check((int) $updated['capacity'] === 5 && $updated['name'] === 'Updated Demo' && $updated['description'] === 'Edited', 'Edit not persisted');
        $admin->request('zone_delete', 200, ['id' => $id]);
        check(!in_array($id, array_column($admin->request('zones')['zones'], 'id')), 'Deleted zone still exists');
        return 'Temporary zone read at capacity 3, edited to 5 and removed from persisted results.';
    });

    scenario('C02', 'Zone capacity and administrative safeguards', function () use ($admin) {
        $id = $admin->request('zone_create', 200, [
            'name' => 'Admin Safeguards Demo',
            'capacity' => 5,
        ])['id'];

        $bay1 = ['zone_id' => $id, 'bay_number' => 4];
        $bay2 = ['zone_id' => $id, 'bay_number' => 5];

        $admin->request('bay_manage', 200, $bay1 + [
            'current' => 'available',
            'status' => 'booked',
            'booking_label' => 'Admin test booking',
        ]);

        $admin->request('zone_update', 409, [
            'id' => $id,
            'name' => 'Admin Safeguards Demo',
            'capacity' => 3,
        ]);

        $admin->request('zone_update', 409, [
            'id' => $id,
            'name' => 'Admin Safeguards Demo',
            'capacity' => 5,
            'active' => false,
        ]);

        $admin->request('bay_manage', 200, $bay2 + [
            'current' => 'available',
            'status' => 'unavailable',
        ]);

        $admin->request('zone_update', 409, [
            'id' => $id,
            'name' => 'Admin Safeguards Demo',
            'capacity' => 5,
            'active' => false,
        ]);

        $admin->request('bay_manage', 409, $bay1 + [
            'current' => 'available',
            'status' => 'unavailable',
        ]);

        $admin->request('bay_manage', 200, $bay1 + [
            'current' => 'booked',
            'status' => 'available',
        ]);

        $admin->request('bay_manage', 200, $bay2 + [
            'current' => 'unavailable',
            'status' => 'available',
        ]);

        $admin->request('zone_update', 200, [
            'id' => $id,
            'name' => 'Admin Safeguards Demo',
            'capacity' => 5,
            'active' => false,
        ]);

        check(!in_array($id, array_column($admin->request('dashboard')['zones'], 'id')),
            'Deactivated zone remained on the active dashboard');

        $admin->request('zone_delete', 200, ['id' => $id]);

        return 'Capacity reduction, booked/unavailable zone deactivation and stale bay updates were rejected. Clearing both bays allowed deactivation.';
    });

    scenario('B01', 'Guest bookings, unavailable bays and shared availability', function () use ($admin, $security, $student) {
        $student->login('student');
        $id = (int) $admin->request('zone_create', 200, ['name' => 'Bays Demo', 'capacity' => 2])['id'];
        $firstBay = ['zone_id' => $id, 'bay_number' => 1];
        $secondBay = ['zone_id' => $id, 'bay_number' => 2];
        $booking = ['current' => 'available', 'status' => 'booked', 'booking_label' => 'Directors meeting'];
        $student->request('bay_manage', 403, $firstBay + $booking);
        $admin->request('bay_manage', 200, $firstBay + $booking);
        $admin->request('bay_manage', 200, $secondBay + ['current' => 'available', 'status' => 'unavailable']);
        $studentZone = zone($student->request('dashboard'), $id);
        check($studentZone['available'] === 0 && $studentZone['occupied'] === 0, 'Held bays were counted incorrectly');
        check(array_column($studentZone['bays'], 'status') === ['booked', 'unavailable'], 'Bay states were missing');
        check(!isset($studentZone['bays'][0]['label']), 'Booking details were exposed to a student');
        $publicZone = zone($student->request('availability'), $id);
        check($publicZone['available'] === 0 && $publicZone['bays'][0]['status'] === 'booked'
            && !isset($publicZone['bays'][0]['label']), 'The public map did not show a private booking correctly');
        check(zone($admin->request('dashboard'), $id)['bays'][0]['label'] === 'Directors meeting', 'Booking label missing');
        $student->request('bay_occupy', 409, $firstBay + ['current' => 'available']);
        $security->request('checkin', 409, driver(2, 'BAYS001', $id));
        $admin->request('bay_manage', 200, $secondBay + ['current' => 'unavailable', 'status' => 'available']);
        $entry = $security->request('checkin', 200, driver(2, 'BAYS001', $id));
        $admin->request('bay_manage', 409, $secondBay + $booking);
        $security->request('checkout', 200, ['id' => $entry['id']]);
        $admin->request('bay_manage', 200, $firstBay + ['current' => 'booked', 'status' => 'available']);
        check(zone($student->request('dashboard'), $id)['available'] === 2, 'Cleared bays did not reopen');
        return 'Booking and closure persisted, reduced availability and blocked both student and gate claims.';
    });
    scenario('B02', 'A student can occupy one bay across zones and sessions', function () use ($admin, $student, $url2, $pdo) {
        $other = new Client($url2);
        $other->login('student');
        $a = ['zone_id' => 1, 'bay_number' => 50];
        $b = ['zone_id' => 3, 'bay_number' => 12];
        $claim = ['current' => 'available'];
        $statuses = race([$student->handle('bay_occupy', $a + $claim), $other->handle('bay_occupy', $b + $claim)], [1, 3], $pdo);
        check($statuses === [200, 409], 'Concurrent claims should have exactly one winner');
        $dashboard = $student->request('dashboard');
        $mineInA = zone($dashboard, 1)['bays'][0]['mine'] ?? false;
        [$own, $next] = $mineInA ? [$a, $b] : [$b, $a];
        check(zone($dashboard, $own['zone_id'])['bays'][0]['mine'], 'The student could not see their bay');
        $admin->request('bay_release', 403, $own + ['current' => 'occupied']);
        $student->request('bay_occupy', 409, $next + $claim);
        $student->request('bay_release', 200, $own + ['current' => 'occupied']);
        $student->request('bay_occupy', 200, $next + $claim);
        $student->request('bay_release', 200, $next + ['current' => 'occupied']);
        return 'Two sessions competing across zones got 200 and 409. Releasing the winning bay allowed another claim.';
    });
    scenario('P01', 'Student and staff entry and live counts', function () use ($admin, $security, &$zoneId, &$first) {
        $zoneId = $admin->request('zone_create', 200, ['name' => 'Lifecycle Demo', 'capacity' => 2])['id'];
        check(count($security->request('ams', 200, null, ['q' => 'STU-DEMO'])['users']) === 1, 'AMS search mismatch');
        $arrival = $security->request('checkin', 200, driver(1, 'DEMO 901', $zoneId));
        $first = $arrival['id'];
        check($arrival['zone'] === 'Lifecycle Demo', 'Missing check-in confirmation zone');
        check((int) zone($security->request('dashboard'), $zoneId)['available'] === 1, 'Expected one free space');
        $security->request('checkin', 200, driver(2, 'DEMO902', $zoneId));
        check((int) zone($security->request('dashboard'), $zoneId)['available'] === 0, 'Expected no free spaces');
        return 'AMS located the student. Check-ins moved availability from 2 to 1 to 0.';
    });
    scenario('P02', 'Duplicate, capacity and historical integrity', function () use ($admin, $security, &$zoneId, &$first) {
        $duplicate = $security->request('checkin', 409, driver(1, 'demo901', $zoneId));
        check(str_contains($duplicate['error'], 'already has an active'), 'A full zone hid the duplicate vehicle error');
        $security->request('checkin', 409, driver(1, 'DEMO903', $zoneId));
        $admin->request('zone_delete', 409, ['id' => $zoneId]);
        $admin->request('zone_update', 409, ['id' => $zoneId, 'name' => 'Lifecycle Demo', 'capacity' => 1]);
        $closed = $security->request('checkout', 200, ['id' => $first]);
        check(!$closed['return_id'], 'Institutional checkout requested an ID return');
        check((int) zone($security->request('dashboard'), $zoneId)['available'] === 1, 'Checkout did not free a space');
        $security->request('checkout', 409, ['id' => $first]);
        return 'Duplicate, full zone, occupied deletion, capacity cut and repeat checkout returned 409; exit freed one spot.';
    });
    scenario('P03', 'Visitor validation, transfer and corrected exit', function () use ($admin, $security, &$zoneId, &$visitorSession) {
        $visitor = ['kind' => 'visitor', 'name' => 'Quinn Demo', 'purpose' => 'Interview', 'destination' => 'Careers',
            'vehicle_plate' => 'DEMO904', 'zone_id' => 3, 'id_retained' => true];
        $security->request('checkin', 422, $visitor);
        $visitor['id_inspected'] = true;
        $visitorSession = $security->request('checkin', 200, $visitor)['id'];
        check((int) $admin->request('report')['active_visitors'] === 1, 'Visitor check-in absent from report');
        $matches = $security->request('active', 200, null, ['q' => 'DEMO 904'])['sessions'];
        check(count($matches) === 1 && (int) $matches[0]['id'] === $visitorSession, 'Plate lookup failed');
        check($matches[0]['driver'] === 'Quinn Demo' && $matches[0]['purpose'] === 'Interview'
            && $matches[0]['destination'] === 'Careers' && (bool) $matches[0]['id_retained'], 'Gate lost operational visitor details');
        $exact = $security->request('active', 200, null, ['q' => "#$visitorSession"])['sessions'];
        check(array_column($exact, 'id') === array_column($matches, 'id'), 'Exact session lookup failed');
        $transfer = ['id' => $visitorSession, 'zone_id' => $zoneId, 'expected_zone_id' => 3, 'reason' => 'Officer verified Quinn Demo’s zone'];
        $activityBeforeTransfer = $admin->request('report');
        $moved = $security->request('transfer', 200, $transfer);
        $activityAfterTransfer = $admin->request('report');
        check($activityAfterTransfer['entries'] === $activityBeforeTransfer['entries']
            && $activityAfterTransfer['exits'] === $activityBeforeTransfer['exits'], 'Transfer changed entry or exit totals');
        check($moved['from_zone'] === 'Visitor Parking' && $moved['to_zone'] === 'Lifecycle Demo', 'Transfer confirmation omitted its zones');
        $security->request('transfer', 409, $transfer);
        $security->request('correct_checkout', 409, ['id' => $visitorSession, 'expected_zone_id' => 3, 'reason' => 'Stale view']);
        $dashboard = $security->request('dashboard');
        check((int) zone($dashboard, 3)['occupied'] === 0 && (int) zone($dashboard, $zoneId)['available'] === 0, 'Transfer occupancy incorrect');
        $security->request('correct_checkout', 422, ['id' => $visitorSession]);
        $correction = ['id' => $visitorSession, 'expected_zone_id' => $zoneId, 'reason' => 'Officer confirmed departure'];
        $closed = $security->request('correct_checkout', 200, $correction);
        check($closed['return_id'] && (int) $admin->request('report')['active_visitors'] === 0, 'Missing ID reminder or visitor not closed');
        $security->request('correct_checkout', 409, $correction);
        check($security->request('active', 200, null, ['q' => "#$visitorSession"])['sessions'] === [], 'Closed session appeared in active lookup');
        $pending = $security->request('active', 200, null, ['q' => 'DEMO902'])['sessions'][0];
        $security->request('checkout', 200, ['id' => $pending['id']]);
        $admin->request('zone_delete', 409, ['id' => $zoneId]);
        $admin->request('zone_update', 200, ['id' => $zoneId, 'name' => 'Lifecycle Demo', 'capacity' => 2, 'active' => false]);
        check(!in_array($zoneId, array_column($security->request('dashboard')['zones'], 'id')), 'Deactivated zone visible');
        return 'Plate and #session matched; stale and repeated writes rejected; transfer named both zones; corrected exit returned the ID reminder.';
    });
    scenario('P04', 'Simultaneous last-space race', function () use ($admin, $security, $url2, $pdo, &$raceZone) {
        $raceZone = $admin->request('zone_create', 200, ['name' => 'Race Demo', 'capacity' => 1])['id'];
        $other = new Client($url2);
        $other->login('security');
        $statuses = race([$security->handle('checkin', driver(1, 'RACEA1', $raceZone)),
            $other->handle('checkin', driver(2, 'RACEB2', $raceZone))], [$raceZone], $pdo);
        check($statuses === [200, 409], 'Unexpected concurrent statuses: ' . implode(', ', $statuses));
        check((int) zone($admin->request('zones'), $raceZone)['occupied'] === 1, 'Race oversubscribed the zone');
        return 'Two HTTP requests blocked on the same zone row; after release one got 200 and one 409; exactly one occupied.';
    });
    scenario('P05', 'Duplicate arrival across two gates', function () use ($security, $url2, $pdo) {
        $other = new Client($url2);
        $other->login('security');
        $statuses = race([$security->handle('checkin', driver(1, 'RACE DUP', 1)),
            $other->handle('checkin', driver(2, 'racedup', 2))], [1, 2], $pdo);
        check($statuses === [200, 409], 'Unexpected duplicate arrival statuses: ' . implode(', ', $statuses));
        $sessions = $security->request('active', 200, null, ['q' => 'RACE DUP'])['sessions'];
        check(count($sessions) === 1, 'Expected exactly one active session across the two zones');
        $security->request('checkout', 200, ['id' => $sessions[0]['id']]);
        return 'The same normalized registration sent to two zones produced one active record and one conflict.';
    });
    scenario('R01', 'Audit and reports keep visitor identifiers at the gate', function () use ($admin, $security, $pdo, &$visitorSession, &$zoneId) {
        $audit = $admin->request('audit')['logs'];
        $actions = array_column($audit, 'action');
        foreach (['login', 'logout', 'zone_created', 'zone_updated', 'zone_deleted', 'zone_deactivated',
            'vehicle_entry', 'vehicle_exit', 'zone_transfer', 'checkout_corrected'] as $action) {
            check(in_array($action, $actions, true), "Missing action $action");
        }
        $visitorActivity = $admin->request('audit', 200, null, ['session_id' => $visitorSession])['logs'];
        $visitorActions = array_column($visitorActivity, 'action');
        sort($visitorActions);
        check($visitorActions === ['checkout_corrected', 'vehicle_entry', 'zone_transfer'], 'Incorrect session audit trail');
        $transfer = $admin->request('audit', 200, null, ['session_id' => $visitorSession, 'event' => 'zone_transfer'])['logs'];
        check(count($transfer) === 1 && json_decode($transfer[0]['metadata'], true) === [
            'from_zone_id' => 3, 'to_zone_id' => $zoneId, 'reason_recorded' => true,
        ], 'Audit must preserve structural details and omit free-text reasons');

        $days = $pdo->query('SELECT CURRENT_DATE - INTERVAL 1 DAY AS `from`, CURRENT_DATE AS through')->fetch();
        $report = $admin->request('report', 200, null, $days + ['q' => "#$visitorSession"]);
        check((int) $report['entries'] >= 4 && (int) $report['exits'] >= 3, 'Daily totals missing entries or exits');
        check(count($report['sessions']) === 1, 'Visitor history missing');
        $session = $report['sessions'][0];
        check($session['id'] == $visitorSession && $session['checkout_at'] && $session['driver_type'] === 'visitor'
            && $session['vehicle_plate'] === null && $session['driver'] === null
            && array_intersect(['destination', 'purpose', 'id_inspected', 'id_retained'], array_keys($session)) === [],
            'Visitor history exposed private fields or lost its operational reference');
        check($admin->request('report', 200, null, $days + ['q' => 'DEMO 904'])['sessions'] === [], 'Visitor registration matched a report search');
        check(in_array($zoneId, array_column($report['history_zones'], 'id')), 'Inactive historical zone missing');
        $columns = $pdo->query('SHOW COLUMNS FROM visitors')->fetchAll(PDO::FETCH_COLUMN);
        check($columns === ['id', 'name', 'purpose', 'destination', 'id_inspected', 'id_retained'], 'Unexpected visitor fields');
        $security->request('report', 403);
        $security->request('audit', 403);
        return 'Admin found the visitor by session ID. Report fields and audit reasons withheld identifiers, while gate access stayed functional.';
    });
    scenario('R02', 'Report window boundaries, session filters and live occupancy', function () use ($admin, $pdo) {
        $window = ['from' => '2024-02-29', 'through' => '2024-03-01'];
        $baseline = $admin->request('report', 200, null, $window);
        $insert = $pdo->prepare('INSERT INTO parking_sessions (user_id, vehicle_plate, zone_id, checkin_at, checkout_at) VALUES (1, ?, ?, ?, ?)');
        $fixtures = [
            'carry' => ['RPTCARRY', 1, '2024-02-28 23:59:59', null],
            'overnight' => ['RPTNIGHT', 1, '2024-02-28 23:59:59', '2024-02-29 00:00:00'],
            'start' => ['RPTREPEAT', 1, '2024-02-29 00:00:00', '2024-03-01 12:00:00'],
            'end' => ['RPTREPEAT', 2, '2024-03-01 23:59:59', '2024-03-02 00:00:00'],
            'open' => ['RPTOPEN', 3, '2024-03-01 18:00:00', null],
            'after' => ['RPTAFTER', 2, '2024-03-02 00:00:00', '2024-03-02 00:00:01'],
            'spanning' => ['RPTSPAN', 1, '2024-02-28 20:00:00', '2024-03-02 00:00:00'],
        ];
        $ids = [];
        foreach ($fixtures as $key => $values) {
            $insert->execute($values);
            $ids[$key] = (int) $pdo->lastInsertId();
        }
        $report = $admin->request('report', 200, null, $window);
        check($report['window']['timezone'] === 'Africa/Nairobi' && $report['window']['start'] === '2024-02-29 00:00:00'
            && $report['window']['end'] === '2024-03-02 00:00:00', 'Unexpected reporting window');
        check((int) $report['entries'] === 3 && (int) $report['exits'] === 2, 'Incorrect counts at midnight boundaries');
        check(array_map('intval', array_column($report['sessions'], 'id')) === [$ids['end'], $ids['open'], $ids['start'], $ids['overnight']],
            'History missed a check-in or checkout in the window, or included an event outside it');
        check((int) $report['occupied'] === (int) $baseline['occupied'] + 2
            && (int) $report['available'] === (int) $baseline['available'] - 2,
            'Live occupancy ignored either the carry-in or the current open session');
        $filtered = $admin->request('report', 200, null, $window + ['q' => 'rpt repeat', 'zone_id' => 2, 'status' => 'closed']);
        check(array_map('intval', array_column($filtered['sessions'], 'id')) === [$ids['end']], 'History filters failed to intersect');
        check($filtered['entries'] === $report['entries'] && $filtered['exits'] === $report['exits'] && $filtered['occupied'] === $report['occupied'],
            'Session filters changed campus totals');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
        $default = $admin->request('report')['window'];
        check($default['from'] === $today && $default['through'] === $today, 'Report did not default to today in Nairobi');
        foreach ([['from' => '2024-02-29'], ['from' => '2023-02-29', 'through' => '2023-03-01'],
            ['from' => '2024-03-02', 'through' => '2024-03-01'], ['status' => 'unknown']] as $invalid) {
            $admin->request('report', 422, null, $invalid);
        }
        return 'Leap day, inclusive final day, exclusive next midnight, repeat entry, cross-midnight exit, filters and an independent live snapshot passed.';
    });
    scenario('R03', 'Audit applies date, event and session filters before the row limit', function () use ($admin, $pdo) {
        $window = ['from' => '2024-03-01', 'through' => '2024-03-01'];
        $insert = $pdo->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, created_at, metadata) VALUES (3, ?, ?, 42, ?, ?)');
        $meta = '{"zone_id":3,"reason":"Fictional visitor’s credential number","destination":"Private destination"}';
        $insert->execute(['vehicle_entry', 'parking_session', '2024-02-29 23:59:59', $meta]);
        $insert->execute(['vehicle_entry', 'parking_session', '2024-03-01 00:00:00', $meta]);
        $first = (int) $pdo->lastInsertId();
        $insert->execute(['vehicle_entry', 'parking_session', '2024-03-01 23:59:59', $meta]);
        $last = (int) $pdo->lastInsertId();
        $insert->execute(['vehicle_entry', 'parking_session', '2024-03-02 00:00:00', $meta]);
        for ($i = 0; $i < 101; $i++) {
            $insert->execute(['zone_updated', 'zone', '2024-03-01 23:59:59', '{}']);
        }
        $all = $admin->request('audit', 200, null, $window);
        check(count($all['logs']) === 100 && $all['more'], 'Audit result did not report truncation');
        $matching = $admin->request('audit', 200, null, $window + ['event' => 'vehicle_entry', 'session_id' => 42]);
        check(array_map('intval', array_column($matching['logs'], 'id')) === [$last, $first] && !$matching['more'],
            'Audit filters did not use inclusive day boundaries before applying the limit');
        check(json_decode($matching['logs'][0]['metadata'], true) === ['zone_id' => 3, 'reason_recorded' => true],
            'Legacy audit metadata leaked private fields');
        check($admin->request('audit', 200, null, $window + ['session_id' => 42, 'event' => 'zone_updated'])['logs'] === [],
            'Session filter included a zone entity with the same ID');
        $admin->request('audit', 422, null, ['through' => '2024-03-01']);
        return 'Two in-window matches survived 101 newer events, and only structural audit metadata was returned.';
    });
    scenario('S01', 'Input validation rejects malformed requests before saving', function () use ($admin, $security, $pdo) {
        $zoneCount = (int) $pdo->query('SELECT COUNT(*) FROM parking_zones')->fetchColumn();
        $visitorCount = (int) $pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
        $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        foreach (['[]', 'true', 'null', '{'] as $body) {
            $admin->rawPost('zone_create', $body, 'application/json', 400);
        }
        $admin->rawPost('zone_create', '{}', 'text/plain', 415);
        $admin->rawPost('zone_create', str_repeat(' ', 16385), 'application/json', 413);
        $admin->request('zone_create', 405);
        $admin->request('availability', 405, []);
        $admin->request('dashboard', 422, null, ['poll' => ['1']]);
        $admin->request('zone_create', 422, ['name' => "Invalid\0", 'capacity' => 3]);
        $admin->request('zone_create', 422, ['name' => 'Malformed', 'capacity' => '+3']);
        $admin->request('zone_create', 422, ['name' => 'Malformed', 'capacity' => 3, 'active' => null]);
        $admin->request('zone_create', 422, ['name' => 'Malformed', 'capacity' => 3, 'role' => 'admin']);
        $admin->request('account_status', 422, ['id' => 2]);
        $admin->request('account_status', 422, ['id' => 2, 'active' => 'false']);
        $security->request('checkin', 422, driver(1, 'BAD AP', 3) + ['name' => 'Unexpected visitor']);
        $security->request('checkin', 422, ['kind' => 'visitor', 'vehicle_plate' => 'BADVIS', 'zone_id' => 3,
            'name' => 'Visitor', 'purpose' => 'Sample', 'destination' => 'Office', 'national_id' => 'FICTIONAL']);
        $security->request('checkout', 422, ['id' => 1, 'expected_zone_id' => null]);
        $admin->request('bay_manage', 422, ['zone_id' => 3, 'bay_number' => 2, 'current' => 'available', 'status' => 'booked']);
        check((int) $pdo->query('SELECT COUNT(*) FROM parking_zones')->fetchColumn() === $zoneCount
            && (int) $pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn() === $visitorCount
            && (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditCount
            && (int) $pdo->query('SELECT active FROM users WHERE id = 2')->fetchColumn() === 1,
            'A rejected request changed persisted records');
        $zone = $admin->rawPost('zone_create', '{"name":"Inactive fixture","capacity":"3","active":false}',
            'application/json; charset=UTF-8', 200)['id'];
        $admin->request('zone_update', 200, ['id' => $zone, 'name' => 'Edited inactive fixture', 'capacity' => 4]);
        check(!(bool) zone($admin->request('zones'), $zone)['active'], 'Updating an inactive zone reactivated it');
        $admin->request('zone_delete', 200, ['id' => $zone]);
        return 'Rejected invalid method, media type, JSON shape, body size and fields without changing records. Inactive zone stayed inactive.';
    });
    scenario('S02', 'Account changes revoke prior authorization', function () use ($admin, $url) {
        $client = new Client($url);
        $client->request('state');
        $client->request('login', 422, ['email' => 'invalid-address', 'password' => DEMO_PASSWORD]);
        $client->request('login', 422, ['email' => 'staff@parking.test', 'password' => "test\0value"]);
        $client->login('staff');
        $client->request('ams', 403);
        $client->request('accounts', 403);
        $client->request('bay_occupy', 403, ['zone_id' => 3, 'bay_number' => 1, 'current' => 'available']);
        $admin->request('account_status', 409, ['id' => 4, 'active' => false]);
        $admin->request('account_status', 200, ['id' => 2, 'active' => true]);
        $client->request('dashboard');
        $other = new Client($url);
        $other->login('staff');
        $admin->request('account_status', 200, ['id' => 2, 'active' => false]);
        $client->request('dashboard', 401);
        $admin->request('account_status', 200, ['id' => 2, 'active' => true]);
        $other->request('dashboard', 401);
        check($other->request('state')['user'] === null, 'A status change left an authenticated session');
        $other->login('staff');
        $other->request('dashboard');
        return 'Forbidden role actions and self-deactivation were denied. Both existing sessions stayed revoked after reactivation, even without a request during suspension.';
    });
    scenario('S03', 'Sign-in and sign-out rotate cookies and CSRF tokens', function () use ($url) {
        $client = new Client($url);
        $client->request('state');
        $anonymous = $client->session();
        $login = $client->login('security');
        $securitySession = $client->session();
        check($securitySession[0] !== $anonymous[0] && $securitySession[1] !== $anonymous[1], 'Login did not rotate both identifiers');
        check(!isset($login['user']['password_hash']) && !isset($login['user']['authorization_version']), 'Login included internal authorization fields');
        $stale = new Client($url);
        $stale->useSession($anonymous);
        check($stale->request('state')['user'] === null, 'Anonymous cookie inherited sign-in');
        $client->login('admin');
        $adminSession = $client->session();
        $stale->useSession([$adminSession[0], $securitySession[1]]);
        $read = $stale->request('active', 403);
        $write = $stale->request('checkin', 403, driver(1, 'STALE02', 3));
        check($read === $write && str_starts_with($read['error'], 'Your session token'), 'Stale tab did not hit the session boundary');
        check($client->request('active', 200, null, ['q' => 'STALE02'])['sessions'] === [], 'Stale tab wrote under the replacement account');
        $stale->useSession($securitySession);
        $stale->request('active', 401);
        $client->request('logout', 200, []);
        $signedOut = $client->session();
        check($signedOut[0] !== $adminSession[0] && $signedOut[1] !== $adminSession[1], 'Logout did not rotate both identifiers');
        $stale->useSession($adminSession);
        $stale->request('accounts', 401);
        return 'Pre-login, replaced and signed-out sessions stayed anonymous. A shared browser cookie with a stale tab token could neither read gate data nor record an entry.';
    });
    scenario('S04', 'Passive requests and absolute session expiry', function () use ($schema) {
        $idle = new Client(startServer($schema, 5));
        $idle->login('student');
        usleep(2500000);
        check($idle->request('state')['user']['role'] === 'student', 'Session expired before its idle limit');
        $idle->request('dashboard', 200, null, ['poll' => '1']);
        sleep(3);
        $idle->request('dashboard', 401, null, ['poll' => '1']);

        $absolute = new Client(startServer($schema, 15, 4));
        $absolute->login('staff');
        sleep(2);
        $absolute->request('dashboard');
        sleep(2);
        $absolute->request('dashboard', 401);
        return 'State and automatic polling did not renew the idle clock. Manual dashboard use could not extend the absolute deadline.';
    });
    scenario('S05', 'The hosted mode permits only gate officers and administrators', function () use ($schema, $url) {
        $hosted = startServer($schema, publicDemo: true);
        $formerStudent = new Client($url);
        $formerStudent->login('student');
        $formerStudent->target($hosted);
        $formerStudent->request('dashboard', 401);
        $public = new Client($hosted);
        check(count($public->request('availability')['zones']) >= 3, 'Hosted guest availability broke');
        $public->request('state');
        $public->request('login', 403, ['email' => 'student@parking.test', 'password' => DEMO_PASSWORD]);
        $public->request('login', 403, ['email' => 'staff@parking.test', 'password' => DEMO_PASSWORD]);
        $public->login('security');
        $public->request('active');
        $public->request('audit', 403);
        $public->login('admin');
        $public->request('audit');
        return 'Hosted guest map and both operator sign-ins worked. Student and staff could not sign in, and a local student session was rejected in hosted mode.';
    });
    scenario('S06', 'Login rate limiting', function () use ($url, $pdo) {
        $pdo->exec('DELETE FROM login_attempts');
        $client = new Client($url);
        $client->request('state');
        for ($i = 0; $i < 8; $i++) {
            $client->request('login', 401, ['email' => 'security@parking.test', 'password' => 'wrong']);
        }
        $client->request('login', 429, ['email' => 'admin@parking.test', 'password' => DEMO_PASSWORD]);
        return 'Eight unsuccessful valid-form sign-in attempts triggered 429, including when a valid password was then supplied.';
    });
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, "SUITE ERROR: {$error->getMessage()}\n");
} finally {
    foreach ($servers as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($created) {
        $pdo->exec("DROP DATABASE `$schema`");
    }
}
echo "$passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
