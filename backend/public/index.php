<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function body(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) throw new Exception('DATABASE_URL não configurada.');

    $u = parse_url($url);
    if (!$u || empty($u['host']) || empty($u['user']) || !isset($u['pass'])) {
        throw new Exception('DATABASE_URL inválida.');
    }

    $dsn = 'pgsql:host=' . $u['host']
         . ';port=' . ($u['port'] ?? 5432)
         . ';dbname=' . ltrim($u['path'] ?? '', '/');

    $pdo = new PDO($dsn, $u['user'], $u['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Cria a estrutura sem apagar os dados existentes.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            phone TEXT UNIQUE NOT NULL,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS barbers (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            specialty TEXT DEFAULT '',
            rating NUMERIC(2,1) DEFAULT 5,
            active BOOLEAN DEFAULT TRUE
        );

        CREATE TABLE IF NOT EXISTS services (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            duration INTEGER NOT NULL DEFAULT 30,
            price NUMERIC(10,2) NOT NULL DEFAULT 0,
            active BOOLEAN DEFAULT TRUE
        );

        DO \$\$
        BEGIN
            CREATE TYPE appointment_status AS ENUM
            ('Pendente','Confirmado','Concluído','Cancelado');
        EXCEPTION
            WHEN duplicate_object THEN NULL;
        END
        \$\$;
    ");

    // CORREÇÃO: bancos antigos podem ter o enum sem "Cancelado".
    // Adicionamos somente o valor que estiver faltando, sem apagar agendamentos.
    $labels = $pdo->query("
        SELECT enumlabel
        FROM pg_enum e
        JOIN pg_type t ON t.oid = e.enumtypid
        WHERE t.typname = 'appointment_status'
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('Cancelado', $labels, true)) {
        $pdo->exec("ALTER TYPE appointment_status ADD VALUE 'Cancelado'");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER REFERENCES customers(id),
            barber_id INTEGER REFERENCES barbers(id),
            service_id INTEGER REFERENCES services(id),
            date DATE NOT NULL,
            time TIME NOT NULL,
            status appointment_status NOT NULL DEFAULT 'Pendente',
            created_at TIMESTAMPTZ DEFAULT NOW()
        );

        CREATE UNIQUE INDEX IF NOT EXISTS ux_appointment_slot
        ON appointments(barber_id, date, time)
        WHERE status <> 'Cancelado';

        CREATE TABLE IF NOT EXISTS barber_schedules (
            id SERIAL PRIMARY KEY,
            barber_id INTEGER REFERENCES barbers(id) ON DELETE CASCADE,
            day_of_week INTEGER NOT NULL,
            start_time TIME,
            end_time TIME,
            break_start TIME,
            break_end TIME,
            active BOOLEAN DEFAULT TRUE,
            UNIQUE(barber_id, day_of_week)
        );
    ");

    if (!(int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn()) {
        $pdo->exec("
            INSERT INTO services(name,duration,price) VALUES
            ('Corte Masculino',30,35),
            ('Barba Tradicional',20,25),
            ('Combo Corte + Barba',50,55),
            ('Sobrancelha',15,15),
            ('Pigmentação de Barba',30,40)
        ");
    }

    if (!(int)$pdo->query("SELECT COUNT(*) FROM barbers")->fetchColumn()) {
        $pdo->exec("
            INSERT INTO barbers(name,specialty,rating) VALUES
            ('Lucas','Especialista em degradê',4.9),
            ('Rafael','Barba e bigode',4.8),
            ('Thiago','Corte masculino',4.7),
            ('Matheus','Estilo clássico',4.9)
        ");
    }

    // Horário padrão somente para barbeiros/dias ainda não cadastrados.
    $pdo->exec("
        INSERT INTO barber_schedules
            (barber_id, day_of_week, start_time, end_time, break_start, break_end, active)
        SELECT b.id, d, '09:00', '18:00', '12:30', '14:00',
               (d BETWEEN 1 AND 6)
        FROM barbers b
        CROSS JOIN generate_series(0,6) d
        ON CONFLICT (barber_id, day_of_week) DO NOTHING
    ");

    return $pdo;
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($path === '/api/health') {
        out(['ok' => true, 'service' => 'Studio A.A API']);
    }

    if ($method === 'GET' && $path === '/api/services') {
        out($pdo->query("
            SELECT id,name,duration,price,active
            FROM services ORDER BY id
        ")->fetchAll());
    }

    if ($method === 'GET' && $path === '/api/barbers') {
        out($pdo->query("
            SELECT id,name,specialty,rating,active
            FROM barbers ORDER BY id
        ")->fetchAll());
    }

    if ($method === 'POST' && $path === '/api/barbers') {
        $x = body();
        if (trim($x['name'] ?? '') === '') out(['error'=>'Nome do barbeiro é obrigatório.'],422);

        $q = $pdo->prepare("
            INSERT INTO barbers(name,specialty,rating,active)
            VALUES(?,?,?,?)
            RETURNING *
        ");
        $q->execute([
            trim($x['name']),
            trim($x['specialty'] ?? ''),
            (float)($x['rating'] ?? 5),
            !empty($x['active'])
        ]);
        out($q->fetch(),201);
    }

    if ($method === 'PUT' && preg_match('#^/api/barbers/(\d+)$#', $path, $m)) {
        $x = body();
        $q = $pdo->prepare("
            UPDATE barbers
            SET name=?, specialty=?, rating=?, active=?
            WHERE id=?
            RETURNING *
        ");
        $q->execute([
            trim($x['name'] ?? ''),
            trim($x['specialty'] ?? ''),
            (float)($x['rating'] ?? 5),
            !empty($x['active']),
            (int)$m[1]
        ]);
        $row = $q->fetch();
        if (!$row) out(['error'=>'Barbeiro não encontrado.'],404);
        out($row);
    }

    if ($method === 'POST' && $path === '/api/services') {
        $x = body();
        if (trim($x['name'] ?? '') === '') out(['error'=>'Nome do serviço é obrigatório.'],422);

        $q = $pdo->prepare("
            INSERT INTO services(name,duration,price,active)
            VALUES(?,?,?,?)
            RETURNING *
        ");
        $q->execute([
            trim($x['name']),
            (int)($x['duration'] ?? 30),
            (float)($x['price'] ?? 0),
            !empty($x['active'])
        ]);
        out($q->fetch(),201);
    }

    if ($method === 'PUT' && preg_match('#^/api/services/(\d+)$#', $path, $m)) {
        $x = body();
        $q = $pdo->prepare("
            UPDATE services
            SET name=?, duration=?, price=?, active=?
            WHERE id=?
            RETURNING *
        ");
        $q->execute([
            trim($x['name'] ?? ''),
            (int)($x['duration'] ?? 30),
            (float)($x['price'] ?? 0),
            !empty($x['active']),
            (int)$m[1]
        ]);
        $row = $q->fetch();
        if (!$row) out(['error'=>'Serviço não encontrado.'],404);
        out($row);
    }

    if ($method === 'GET' && $path === '/api/schedules') {
        $barberId = (int)($_GET['barber_id'] ?? 0);
        $q = $pdo->prepare("
            SELECT day_of_week,
                   start_time::text,
                   end_time::text,
                   break_start::text,
                   break_end::text,
                   active
            FROM barber_schedules
            WHERE barber_id=?
            ORDER BY day_of_week
        ");
        $q->execute([$barberId]);
        out($q->fetchAll());
    }

    if ($method === 'PUT' && $path === '/api/schedules') {
        $x = body();
        $barberId = (int)($x['barber_id'] ?? 0);
        if (!$barberId) out(['error'=>'Barbeiro inválido.'],422);

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("DELETE FROM barber_schedules WHERE barber_id=?");
            $q->execute([$barberId]);

            $q = $pdo->prepare("
                INSERT INTO barber_schedules
                (barber_id,day_of_week,start_time,end_time,break_start,break_end,active)
                VALUES(?,?,?,?,?,?,?)
            ");

            foreach (($x['schedules'] ?? []) as $r) {
                $q->execute([
                    $barberId,
                    (int)$r['day_of_week'],
                    ($r['start_time'] ?? '') ?: null,
                    ($r['end_time'] ?? '') ?: null,
                    ($r['break_start'] ?? '') ?: null,
                    ($r['break_end'] ?? '') ?: null,
                    !empty($r['active'])
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        out(['ok'=>true]);
    }

    if ($method === 'GET' && $path === '/api/availability') {
        $q = $pdo->prepare("
            SELECT id, barber_id, date, time::text, status
            FROM appointments
            WHERE barber_id=? AND date=? AND status <> 'Cancelado'
            ORDER BY time
        ");
        $q->execute([
            (int)($_GET['barber_id'] ?? 0),
            $_GET['date'] ?? ''
        ]);
        out($q->fetchAll());
    }

    if ($method === 'POST' && $path === '/api/appointments') {
        $x = body();

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("
                SELECT id
                FROM appointments
                WHERE barber_id=? AND date=? AND time=?
                  AND status <> 'Cancelado'
                FOR UPDATE
            ");
            $q->execute([
                (int)$x['barber_id'],
                $x['date'],
                $x['time']
            ]);

            if ($q->fetch()) {
                $pdo->rollBack();
                out(['error'=>'Horário já ocupado.'],409);
            }

            $q = $pdo->prepare("
                INSERT INTO customers(name,phone)
                VALUES(?,?)
                ON CONFLICT(phone)
                DO UPDATE SET name=EXCLUDED.name
                RETURNING id
            ");
            $q->execute([
                trim($x['customer_name'] ?? ''),
                trim($x['customer_phone'] ?? '')
            ]);
            $customerId = $q->fetchColumn();

            $q = $pdo->prepare("
                INSERT INTO appointments(customer_id,barber_id,service_id,date,time)
                VALUES(?,?,?,?,?)
                RETURNING id
            ");
            $q->execute([
                $customerId,
                (int)$x['barber_id'],
                (int)$x['service_id'],
                $x['date'],
                $x['time']
            ]);
            $id = $q->fetchColumn();

            $pdo->commit();
            out(['id'=>(int)$id],201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'PATCH' && preg_match('#^/api/appointments/(\d+)$#', $path, $m)) {
        $x = body();
        $status = $x['status'] ?? 'Cancelado';

        $allowed = ['Pendente','Confirmado','Concluído','Cancelado'];
        if (!in_array($status, $allowed, true)) {
            out(['error'=>'Status inválido.'],422);
        }

        $q = $pdo->prepare("
            UPDATE appointments
            SET status=?
            WHERE id=?
            RETURNING id,status
        ");
        $q->execute([$status,(int)$m[1]]);
        $row = $q->fetch();
        if (!$row) out(['error'=>'Agendamento não encontrado.'],404);
        out($row);
    }

    if ($method === 'GET' && $path === '/api/admin/dashboard') {
        $today = date('Y-m-d');

        $data = [
            'today' => (int)$pdo->query("
                SELECT COUNT(*)
                FROM appointments
                WHERE date='{$today}' AND status <> 'Cancelado'
            ")->fetchColumn(),

            'clients' => (int)$pdo->query("
                SELECT COUNT(*) FROM customers
            ")->fetchColumn(),

            'barbers' => (int)$pdo->query("
                SELECT COUNT(*) FROM barbers WHERE active
            ")->fetchColumn(),

            'services' => (int)$pdo->query("
                SELECT COUNT(*) FROM services WHERE active
            ")->fetchColumn()
        ];

        $data['appointments'] = $pdo->query("
            SELECT
                a.id,
                a.date,
                a.time::text,
                a.status,
                c.name AS customer_name,
                c.phone AS customer_phone,
                b.name AS barber_name,
                b.id AS barber_id,
                s.name AS service_name
            FROM appointments a
            JOIN customers c ON c.id=a.customer_id
            JOIN barbers b ON b.id=a.barber_id
            JOIN services s ON s.id=a.service_id
            ORDER BY a.date DESC,a.time DESC
        ")->fetchAll();

        out($data);
    }

    out(['error'=>'Rota não encontrada'],404);

} catch (Throwable $e) {
    out(['error'=>$e->getMessage()],500);
}
?>
