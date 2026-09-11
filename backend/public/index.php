<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) out(['error' => 'DATABASE_URL não configurada'], 500);

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) out(['error' => 'DATABASE_URL inválida'], 500);

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

function adminPassword(): string {
    $configured = getenv('ADMIN_PASSWORD');
    return ($configured !== false && $configured !== '') ? $configured : 'studioaa123';
}

function suppliedAdminPassword(): string {
    $header = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
    if ($header !== '') return $header;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return $m[1];
    return '';
}

function requireAdmin(): void {
    if (!hash_equals(adminPassword(), suppliedAdminPassword())) {
        out(['error' => 'Não autorizado'], 401);
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    if ($path === '/api/health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        out(['ok' => true, 'service' => 'Studio A.A API']);
    }

    if ($path === '/api/services' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        out(db()->query("SELECT id,name,duration,price FROM services WHERE active=TRUE ORDER BY sort_order,id")->fetchAll());
    }

    if ($path === '/api/barbers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        out(db()->query("SELECT id,name,specialty,rating FROM barbers WHERE active=TRUE ORDER BY name")->fetchAll());
    }

    if ($path === '/api/appointments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = body();
        foreach (['service_id','barber_id','customer_name','customer_phone','date','time'] as $key) {
            if (empty($d[$key])) out(['error' => "Campo obrigatório: {$key}"], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lockKey = sprintf('%u', crc32((string)((int)$d['barber_id'].'|'.$d['date'].'|'.$d['time'])));
            $pdo->prepare("SELECT pg_advisory_xact_lock(CAST(? AS bigint))")->execute([$lockKey]);

            $q = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed')");
            $q->execute([(int)$d['barber_id'],$d['date'],$d['time']]);
            if ((int)$q->fetchColumn() > 0) {
                $pdo->rollBack();
                out(['error'=>'Horário já ocupado'],409);
            }

            $q = $pdo->prepare("SELECT id FROM customers WHERE phone=?");
            $q->execute([$d['customer_phone']]);
            $customer = $q->fetch();
            if ($customer) {
                $customerId = (int)$customer['id'];
                $pdo->prepare("UPDATE customers SET name=? WHERE id=?")->execute([$d['customer_name'],$customerId]);
            } else {
                $q = $pdo->prepare("INSERT INTO customers(name,phone) VALUES(?,?) RETURNING id");
                $q->execute([$d['customer_name'],$d['customer_phone']]);
                $customerId = (int)$q->fetchColumn();
            }

            $q = $pdo->prepare("INSERT INTO appointments(customer_id,service_id,barber_id,appointment_date,appointment_time,status) VALUES(?,?,?,?,?,'pending') RETURNING id");
            $q->execute([$customerId,(int)$d['service_id'],(int)$d['barber_id'],$d['date'],$d['time']]);
            $id = (int)$q->fetchColumn();
            $pdo->commit();
            out(['id'=>$id,'status'=>'pending'],201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($path === '/api/availability' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $barberId = (int)($_GET['barber_id'] ?? 0);
        $date = trim((string)($_GET['date'] ?? ''));
        if ($barberId <= 0 || $date === '') out(['error'=>'barber_id e date são obrigatórios'],422);
        $q = db()->prepare("SELECT a.id, a.appointment_date AS date, a.appointment_time AS time, a.barber_id,
                                   b.name AS barber_name, c.name AS customer_name, c.phone AS customer_phone,
                                   s.name AS service_name, a.status
                            FROM appointments a
                            JOIN barbers b ON b.id=a.barber_id
                            JOIN customers c ON c.id=a.customer_id
                            JOIN services s ON s.id=a.service_id
                            WHERE a.barber_id=? AND a.appointment_date=? AND a.status IN ('pending','confirmed')
                            ORDER BY a.appointment_time");
        $q->execute([$barberId,$date]);
        out(['appointments'=>$q->fetchAll()]);
    }

    if ($path === '/api/admin/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = body();
        if (!hash_equals(adminPassword(), (string)($d['password'] ?? ''))) out(['error'=>'Senha incorreta'],401);
        out(['ok'=>true]);
    }

    if ($path === '/api/admin/dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAdmin();
        $pdo = db();
        $appointments = $pdo->query("SELECT a.id, a.barber_id, a.service_id, s.price AS service_price,
                    TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,
                    TO_CHAR(a.appointment_time,'HH24:MI') AS time,
                    a.status, c.name AS customer_name, c.phone AS customer_phone,
                    b.name AS barber_name, s.name AS service_name
                FROM appointments a
                JOIN customers c ON c.id=a.customer_id
                JOIN barbers b ON b.id=a.barber_id
                JOIN services s ON s.id=a.service_id
                ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC")->fetchAll();
        out([
            'stats'=>[
                'today'=>(int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn(),
                'clients'=>(int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
                'barbers'=>(int)$pdo->query("SELECT COUNT(*) FROM barbers WHERE active=TRUE")->fetchColumn(),
                'services'=>(int)$pdo->query("SELECT COUNT(*) FROM services WHERE active=TRUE")->fetchColumn()
            ],
            'appointments'=>$appointments
        ]);
    }

    if ($path === '/api/admin/status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d = body();
        $id = (int)($d['id'] ?? 0);
        $status = (string)($d['status'] ?? '');
        $allowed = ['pending','confirmed','completed','cancelled'];
        if ($id <= 0 || !in_array($status, $allowed, true)) out(['error'=>'ID ou status inválido'],422);
        $q = db()->prepare("UPDATE appointments SET status=? WHERE id=? RETURNING id,status");
        $q->execute([$status,$id]);
        $row = $q->fetch();
        if (!$row) out(['error'=>'Agendamento não encontrado'],404);
        out(['ok'=>true,'id'=>(int)$row['id'],'status'=>$row['status']]);
    }

    if ($path === '/api/admin/cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $id = (int)(body()['id'] ?? 0);
        if ($id <= 0) out(['error'=>'ID inválido'],422);
        $q = db()->prepare("UPDATE appointments SET status='cancelled' WHERE id=? AND status IN ('pending','confirmed') RETURNING id");
        $q->execute([$id]);
        if (!$q->fetch()) out(['error'=>'Agendamento não encontrado ou já cancelado'],404);
        out(['ok'=>true,'id'=>$id,'status'=>'cancelled']);
    }

    if ($path === '/api/admin/reactivate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $id = (int)(body()['id'] ?? 0);
        if ($id <= 0) out(['error'=>'ID inválido'],422);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT barber_id, appointment_date, appointment_time, status FROM appointments WHERE id=? FOR UPDATE");
            $q->execute([$id]);
            $a = $q->fetch();
            if (!$a) { $pdo->rollBack(); out(['error'=>'Agendamento não encontrado'],404); }
            if ($a['status'] !== 'cancelled') { $pdo->rollBack(); out(['error'=>'Somente agendamentos cancelados podem ser reativados'],409); }
            $q = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed') AND id<>?");
            $q->execute([(int)$a['barber_id'],$a['appointment_date'],$a['appointment_time'],$id]);
            if ((int)$q->fetchColumn() > 0) { $pdo->rollBack(); out(['error'=>'O horário já foi ocupado por outro agendamento'],409); }
            $q = $pdo->prepare("UPDATE appointments SET status='pending' WHERE id=? RETURNING id");
            $q->execute([$id]);
            $pdo->commit();
            out(['ok'=>true,'id'=>$id,'status'=>'pending']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    out(['error'=>'Rota não encontrada'],404);
} catch (Throwable $e) {
    out(['error'=>'Erro interno','detail'=>$e->getMessage()],500);
}
