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

function startServer(string $schema): string
{
    global $servers;
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    check($socket !== false, 'Could not reserve a local test port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment = array_merge(getenv(), [
        'PARKING_DB_NAME' => $schema, 'PARKING_TEST_MODE' => '1', 'PARKING_TEST_TIMEOUT' => '4', 'PARKING_PUBLIC_DEMO' => '0',
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
        if ($body !== null) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $body, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf ? $this->csrf : 'invalid')],
            ]);
        }
        return $curl;
    }

    public function request(string $action, int $expected = 200, ?array $body = null, array $query = [], bool $csrf = true): array
    {
        $curl = $this->handle($action, $body, $query, $csrf);
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
        $first = $security->request('checkin', 200, driver(1, 'DEMO 901', $zoneId))['id'];
        check((int) zone($security->request('dashboard'), $zoneId)['available'] === 1, 'Expected one free space');
        $security->request('checkin', 200, driver(2, 'DEMO902', $zoneId));
        check((int) zone($security->request('dashboard'), $zoneId)['available'] === 0, 'Expected no free spaces');
        return 'AMS located the student. Check-ins moved availability from 2 to 1 to 0.';
    });
    scenario('P02', 'Duplicate, capacity and historical integrity', function () use ($admin, $security, &$zoneId, &$first) {
        $security->request('checkin', 409, driver(1, 'demo901', 1));
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
        $security->request('transfer', 200, ['id' => $visitorSession, 'zone_id' => $zoneId, 'reason' => 'Officer verified the zone']);
        $dashboard = $security->request('dashboard');
        check((int) zone($dashboard, 3)['occupied'] === 0 && (int) zone($dashboard, $zoneId)['available'] === 0, 'Transfer occupancy incorrect');
        $security->request('correct_checkout', 422, ['id' => $visitorSession]);
        $closed = $security->request('correct_checkout', 200, ['id' => $visitorSession, 'reason' => 'Officer confirmed departure']);
        check($closed['return_id'] && (int) $admin->request('report')['active_visitors'] === 0, 'Missing ID reminder or visitor not closed');
        $pending = $security->request('active', 200, null, ['q' => 'DEMO902'])['sessions'][0];
        $security->request('checkout', 200, ['id' => $pending['id']]);
        $admin->request('zone_delete', 409, ['id' => $zoneId]);
        $admin->request('zone_update', 200, ['id' => $zoneId, 'name' => 'Lifecycle Demo', 'capacity' => 2, 'active' => false]);
        check(!in_array($zoneId, array_column($security->request('dashboard')['zones'], 'id')), 'Deactivated zone visible');
        return 'Validated ID flags; moved occupancy; corrected exit returned ID reminder; used zone deactivated with history intact.';
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
    scenario('R01', 'Audit, reporting and privacy', function () use ($admin, $security, $pdo, &$visitorSession) {
        $audit = $admin->request('audit')['logs'];
        $actions = array_column($audit, 'action');
        foreach (['login', 'logout', 'zone_created', 'zone_updated', 'zone_deleted', 'zone_deactivated',
            'vehicle_entry', 'vehicle_exit', 'zone_transfer', 'checkout_corrected'] as $action) {
            check(in_array($action, $actions, true), "Missing action $action");
        }
        $report = $admin->request('report', 200, null, ['q' => 'DEMO904']);
        check((int) $report['entries'] >= 4 && (int) $report['exits'] >= 3, 'Daily totals missing entries or exits');
        check(count($report['sessions']) === 1 && $report['sessions'][0]['id'] == $visitorSession
            && $report['sessions'][0]['checkout_at'], 'Visitor history missing');
        $columns = $pdo->query('SHOW COLUMNS FROM visitors')->fetchAll(PDO::FETCH_COLUMN);
        check($columns === ['id', 'name', 'purpose', 'destination', 'id_inspected', 'id_retained'], 'Unexpected visitor fields');
        $security->request('report', 403);
        return 'Found all required audit actions, daily counts and matched history. Visitor schema has no national ID number field.';
    });
    scenario('S01', 'Injection protection and account administration', function () use ($admin, $url) {
        $client = new Client($url);
        $client->request('state');
        $client->request('login', 401, ['email' => "' OR 1=1 --", 'password' => DEMO_PASSWORD]);
        $client->login('staff');
        $admin->request('account_status', 200, ['id' => 2, 'active' => false]);
        $client->request('dashboard', 401);
        $admin->request('account_status', 200, ['id' => 2, 'active' => true]);
        $client->login('staff');
        return 'SQL injection payload received 401; disabled staff access failed; staff signed in after reactivation.';
    });
    scenario('S02', 'Inactivity timeout and passive dashboard updates', function () use ($url) {
        $client = new Client($url);
        $client->login('student');
        sleep(2);
        $client->request('dashboard', 200, null, ['poll' => '1']);
        sleep(2);
        $client->request('dashboard', 401, null, ['poll' => '1']);
        return 'A poll during the four-second test timeout returned 200; the next poll returned 401 after inactivity.';
    });
    scenario('S03', 'Login rate limiting', function () use ($url) {
        $client = new Client($url);
        $client->request('state');
        for ($i = 0; $i < 6; $i++) {
            $client->request('login', 401, ['email' => 'security@parking.test', 'password' => 'wrong']);
        }
        $client->request('login', 429, ['email' => 'admin@parking.test', 'password' => DEMO_PASSWORD]);
        return 'Eight failed attempts across the suite triggered 429, including when a valid password was then supplied.';
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
