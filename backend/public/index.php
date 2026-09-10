<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) {
        http_response_code(500);
        echo json_encode(['error' => 'DATABASE_URL não configurada']);
        exit;
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        http_response_code(500);
        echo json_encode(['error' => 'DATABASE_URL inválida']);
        exit;
    }

    $host = $parts['host'];
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';
    $name = ltrim($parts['path'] ?? '', '/');

    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    initDatabase($pdo);
    return $pdo;
}

function initDatabase(PDO $pdo): void {
    // Cria a estrutura automaticamente na primeira conexão. Isso evita a
    // necessidade de contratar pgAdmin ou executar SQL manualmente.
    $pdo->exec("DO $$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'appointment_status') THEN
            CREATE TYPE appointment_status AS ENUM ('pending','confirmed','cancelled','completed');
        END IF;
    END $$;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        phone VARCHAR(40) NOT NULL UNIQUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS barbers (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        specialty VARCHAR(180),
        rating NUMERIC(2,1) NOT NULL DEFAULT 5.0,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        duration INTEGER NOT NULL,
        price NUMERIC(10,2) NOT NULL,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
        id BIGSERIAL PRIMARY KEY,
        customer_id BIGINT NOT NULL REFERENCES customers(id),
        service_id BIGINT NOT NULL REFERENCES services(id),
        barber_id BIGINT NOT NULL REFERENCES barbers(id),
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status appointment_status NOT NULL DEFAULT 'pending',
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_appointments_date ON appointments(appointment_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_appointments_barber_date ON appointments(barber_id, appointment_date)");

    $services = [
        ['Corte Masculino', 30, 35.00, 1],
        ['Barba Tradicional', 20, 25.00, 2],
        ['Combo Corte + Barba', 50, 55.00, 3],
        ['Sobrancelha', 15, 15.00, 4],
        ['Pigmentação de Barba', 30, 40.00, 5],
    ];
    $stmt = $pdo->prepare("INSERT INTO services (name,duration,price,sort_order) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = ?)");
    foreach ($services as [$name,$duration,$price,$sort]) {
        $stmt->execute([$name,$duration,$price,$sort,$name]);
    }

    $barbers = [
        ['Lucas', 'Especialista em degradê', 4.9],
        ['Rafael', 'Barba e bigode', 4.8],
        ['Thiago', 'Corte masculino', 4.7],
        ['Matheus', 'Estilo clássico', 4.9],
    ];
    $stmt = $pdo->prepare("INSERT INTO barbers (name,specialty,rating) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM barbers WHERE name = ?)");
    foreach ($barbers as [$name,$specialty,$rating]) {
        $stmt->execute([$name,$specialty,$rating,$name]);
    }
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    if ($path === '/api/health') {
        out(['ok' => true, 'service' => 'Studio A.A API']);
    }

    if ($path === '/api/services' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT id, name, duration, price FROM services WHERE active = TRUE ORDER BY sort_order, id";
        out(db()->query($sql)->fetchAll());
    }

    if ($path === '/api/barbers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT id, name, specialty, rating FROM barbers WHERE active = TRUE ORDER BY name";
        out(db()->query($sql)->fetchAll());
    }

    if ($path === '/api/appointments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = body();
        foreach (['service_id','barber_id','customer_name','customer_phone','date','time'] as $key) {
            if (empty($d[$key])) out(['error' => "Campo obrigatório: {$key}"], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $lockKey = crc32((string)((int)$d['barber_id'] . '|' . $d['date'] . '|' . $d['time']));
        $pdo->prepare("SELECT pg_advisory_xact_lock(?)")->execute([$lockKey]);

        $q = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('pending','confirmed')");
        $q->execute([(int)$d['barber_id'], $d['date'], $d['time']]);
        if ((int)$q->fetchColumn() > 0) {
            $pdo->rollBack();
            out(['error' => 'Horário já ocupado'], 409);
        }

        $q = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
        $q->execute([$d['customer_phone']]);
        $customer = $q->fetch();
        if ($customer) {
            $customerId = (int)$customer['id'];
            $pdo->prepare("UPDATE customers SET name = ? WHERE id = ?")->execute([$d['customer_name'], $customerId]);
        } else {
            $q = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?) RETURNING id");
            $q->execute([$d['customer_name'], $d['customer_phone']]);
            $customerId = (int)$q->fetchColumn();
        }

        $q = $pdo->prepare("INSERT INTO appointments (customer_id, service_id, barber_id, appointment_date, appointment_time, status) VALUES (?, ?, ?, ?, ?, 'pending') RETURNING id");
        $q->execute([$customerId, (int)$d['service_id'], (int)$d['barber_id'], $d['date'], $d['time']]);
        $appointmentId = (int)$q->fetchColumn();
        $pdo->commit();
        out(['id' => $appointmentId, 'status' => 'pending'], 201);
    }

    if ($path === '/api/admin/dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $pdo = db();
        out([
            'today' => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn(),
            'clients' => (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
            'barbers' => (int)$pdo->query("SELECT COUNT(*) FROM barbers WHERE active = TRUE")->fetchColumn(),
            'services' => (int)$pdo->query("SELECT COUNT(*) FROM services WHERE active = TRUE")->fetchColumn()
        ]);
    }

    out(['error' => 'Rota não encontrada'], 404);
} catch (Throwable $e) {
    out(['error' => 'Erro interno', 'detail' => $e->getMessage()], 500);
}
