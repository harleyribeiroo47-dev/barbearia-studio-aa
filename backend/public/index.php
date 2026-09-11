<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out($data, int $status=200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function body(): array { $d=json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($d)?$d:[]; }
function db(): PDO {
    static $pdo=null; if ($pdo instanceof PDO) return $pdo;
    $url=getenv('DATABASE_URL'); if(!$url) out(['error'=>'DATABASE_URL não configurada'],500);
    $p=parse_url($url); if(!$p || empty($p['host'])) out(['error'=>'DATABASE_URL inválida'],500);
    $pdo=new PDO('pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path']??'','/'),$p['user']??'',$p['pass']??'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    initDb($pdo); return $pdo;
}
function initDb(PDO $p): void {
    $p->exec("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname='appointment_status') THEN CREATE TYPE appointment_status AS ENUM ('pending','confirmed','cancelled','completed'); END IF; END $$;");
    $p->exec("CREATE TABLE IF NOT EXISTS customers(id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,phone VARCHAR(40) NOT NULL UNIQUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE TABLE IF NOT EXISTS barbers(id BIGSERIAL PRIMARY KEY,name VARCHAR(120) NOT NULL,specialty VARCHAR(180),rating NUMERIC(2,1) NOT NULL DEFAULT 5.0,active BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE TABLE IF NOT EXISTS services(id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,duration INTEGER NOT NULL,price NUMERIC(10,2) NOT NULL,active BOOLEAN NOT NULL DEFAULT TRUE,sort_order INTEGER NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE TABLE IF NOT EXISTS appointments(id BIGSERIAL PRIMARY KEY,customer_id BIGINT NOT NULL REFERENCES customers(id),service_id BIGINT NOT NULL REFERENCES services(id),barber_id BIGINT NOT NULL REFERENCES barbers(id),appointment_date DATE NOT NULL,appointment_time TIME NOT NULL,status appointment_status NOT NULL DEFAULT 'pending',created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE INDEX IF NOT EXISTS idx_appointments_date ON appointments(appointment_date)");
    $p->exec("CREATE INDEX IF NOT EXISTS idx_appointments_barber_date ON appointments(barber_id,appointment_date)");
    $p->exec("CREATE TABLE IF NOT EXISTS studio_aa_schedules(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,day_of_week INTEGER NOT NULL,start_time TIME,end_time TIME,break_start TIME,break_end TIME,active BOOLEAN NOT NULL DEFAULT TRUE,UNIQUE(barber_id,day_of_week))");
    foreach ([
        'day_of_week'=>'INTEGER', 'start_time'=>'TIME', 'end_time'=>'TIME',
        'break_start'=>'TIME', 'break_end'=>'TIME', 'active'=>'BOOLEAN NOT NULL DEFAULT TRUE'
    ] as $col=>$type) { $p->exec("ALTER TABLE studio_aa_schedules ADD COLUMN IF NOT EXISTS $col $type"); }
    $services=[['Corte Masculino',30,35,1],['Barba Tradicional',20,25,2],['Combo Corte + Barba',50,55,3],['Sobrancelha',15,15,4],['Pigmentação de Barba',30,40,5]];
    $q=$p->prepare("INSERT INTO services(name,duration,price,sort_order) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM services WHERE name=? )"); foreach($services as $x)$q->execute([$x[0],$x[1],$x[2],$x[3],$x[0]]);
    $barbers=[['Lucas','Especialista em degradê',4.9],['Rafael','Barba e bigode',4.8],['Thiago','Corte masculino',4.7],['Matheus','Estilo clássico',4.9]];
    $q=$p->prepare("INSERT INTO barbers(name,specialty,rating) SELECT ?,?,? WHERE NOT EXISTS(SELECT 1 FROM barbers WHERE name=? )"); foreach($barbers as $x)$q->execute([$x[0],$x[1],$x[2],$x[0]]);
}
function boolv($v): bool { if(is_bool($v)) return $v; $s=strtolower(trim((string)$v)); return in_array($s,['1','true','on','yes','sim','aberto','active'],true); }
function timev($v): ?string { $s=trim((string)$v); return $s===''?null:$s; }
function adminOk(): bool { return true; } // compatibilidade com o painel atual
function getSchedule(PDO $p,int $barber): array {
    $q=$p->prepare("SELECT day_of_week AS weekday, day_of_week, active AS open, active, start_time AS start, start_time, end_time AS end, end_time, break_start, break_end FROM studio_aa_schedules WHERE barber_id=? ORDER BY day_of_week");
    $q->execute([$barber]); return $q->fetchAll();
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/'; $method=$_SERVER['REQUEST_METHOD'];
try {
    if($path==='/api/health' && $method==='GET') out(['ok'=>true,'service'=>'Studio A.A API','version'=>'integrated-schedule-fix']);
    if($path==='/api/services' && $method==='GET') out(db()->query("SELECT id,name,duration,price FROM services WHERE active=TRUE ORDER BY sort_order,id")->fetchAll());
    if($path==='/api/barbers' && $method==='GET') out(db()->query("SELECT id,name,specialty,rating FROM barbers WHERE active=TRUE ORDER BY name")->fetchAll());

    if($path==='/api/appointments' && $method==='POST') {
        $d=body(); foreach(['service_id','barber_id','customer_name','customer_phone','date','time'] as $k) if(empty($d[$k])) out(['error'=>"Campo obrigatório: $k"],422);
        $p=db(); $p->beginTransaction(); try {
            $lock=sprintf('%u',crc32((string)((int)$d['barber_id'].'|'.$d['date'].'|'.$d['time']))); $p->prepare("SELECT pg_advisory_xact_lock(CAST(? AS bigint))")->execute([$lock]);
            $q=$p->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed')"); $q->execute([(int)$d['barber_id'],$d['date'],$d['time']]); if((int)$q->fetchColumn()>0){$p->rollBack();out(['error'=>'Horário já ocupado'],409);}
            $q=$p->prepare('SELECT id FROM customers WHERE phone=?');$q->execute([$d['customer_phone']]);$c=$q->fetch();
            if($c){$cid=(int)$c['id'];$p->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$d['customer_name'],$cid]);}
            else{$q=$p->prepare('INSERT INTO customers(name,phone) VALUES(?,?) RETURNING id');$q->execute([$d['customer_name'],$d['customer_phone']]);$cid=(int)$q->fetchColumn();}
            $q=$p->prepare("INSERT INTO appointments(customer_id,service_id,barber_id,appointment_date,appointment_time,status) VALUES(?,?,?,?,?,'pending') RETURNING id");$q->execute([$cid,(int)$d['service_id'],(int)$d['barber_id'],$d['date'],$d['time']]);$id=(int)$q->fetchColumn();$p->commit();out(['id'=>$id,'status'=>'pending'],201);
        }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    if($path==='/api/availability' && $method==='GET') {
        $bid=(int)($_GET['barber_id']??0);$date=trim((string)($_GET['date']??''));if($bid<=0||$date==='')out(['error'=>'barber_id e date são obrigatórios'],422);
        $q=db()->prepare("SELECT a.id,a.appointment_date AS date,a.appointment_time AS time,a.barber_id,b.name AS barber_name,c.name AS customer_name,c.phone AS customer_phone,s.name AS service_name,a.status FROM appointments a JOIN barbers b ON b.id=a.barber_id JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.barber_id=? AND a.appointment_date=? AND a.status IN ('pending','confirmed') ORDER BY a.appointment_time");$q->execute([$bid,$date]);out(['appointments'=>$q->fetchAll()]);
    }
    if($path==='/api/admin/login' && $method==='POST') { $d=body();$configured=getenv('ADMIN_PASSWORD')?:'studioaa123';if(!hash_equals($configured,(string)($d['password']??'')))out(['error'=>'Senha incorreta'],401);out(['ok'=>true]); }

    // Painel: barbers - aceita POST e PUT, sem exigir senha no backend para compatibilidade
    if($path==='/api/admin/barbers' && $method==='GET') out(db()->query("SELECT id,name,specialty,rating,active FROM barbers ORDER BY name,id")->fetchAll());
    if($path==='/api/admin/barbers' && in_array($method,['POST','PUT'],true)) {
        $d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));if($name==='')out(['error'=>'Nome do barbeiro é obrigatório'],422);
        $spec=(string)($d['specialty']??'');$rating=(float)($d['rating']??5);$active=array_key_exists('active',$d)?boolv($d['active']):true;$p=db();
        if($id>0){$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=? ,active=$activeSql WHERE id=? RETURNING id");$q->execute([$name,$spec,$rating,$id]);if(!$q->fetch())out(['error'=>'Barbeiro não encontrado'],404);}else{$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("INSERT INTO barbers(name,specialty,rating,active) VALUES(?,?,?,$activeSql) RETURNING id");$q->execute([$name,$spec,$rating]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);
    }
    if(preg_match('#^/api/barbers/(\d+)$#',$path,$m) && $method==='PUT'){ $d=body();$d['id']=(int)$m[1];$_POST=[]; $name=trim((string)($d['name']??''));if($name==='')out(['error'=>'Nome do barbeiro é obrigatório'],422);$p=db();$q=$p->prepare('UPDATE barbers SET name=?,specialty=?,rating=?,active=? WHERE id=? RETURNING id');$q->execute([$name,(string)($d['specialty']??''),(float)($d['rating']??5),array_key_exists('active',$d)?boolv($d['active']):true,(int)$m[1]]);if(!$q->fetch())out(['error'=>'Barbeiro não encontrado'],404);out(['ok'=>true,'id'=>(int)$m[1]]); }

    // Painel: services
    if($path==='/api/admin/services' && $method==='GET') out(db()->query('SELECT id,name,duration,price,active,sort_order FROM services ORDER BY sort_order,id')->fetchAll());
    if($path==='/api/admin/services' && in_array($method,['POST','PUT'],true)){
        $d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$duration=(int)($d['duration']??0);$price=(float)($d['price']??0);if($name===''||$duration<=0)out(['error'=>'Nome e duração são obrigatórios'],422);$active=array_key_exists('active',$d)?boolv($d['active']):true;$sort=(int)($d['sort_order']??0);$p=db();
        if($id>0){$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=$activeSql,sort_order=? WHERE id=? RETURNING id");$q->execute([$name,$duration,$price,$sort,$id]);if(!$q->fetch())out(['error'=>'Serviço não encontrado'],404);}else{$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("INSERT INTO services(name,duration,price,active,sort_order) VALUES(?,?,?,$activeSql,?) RETURNING id");$q->execute([$name,$duration,$price,$sort]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);
    }
    if(preg_match('#^/api/services/(\d+)$#',$path,$m) && $method==='PUT'){ $d=body();$p=db();$q=$p->prepare('UPDATE services SET name=?,duration=?,price=?,active=? WHERE id=? RETURNING id');$q->execute([trim((string)($d['name']??'')),(int)($d['duration']??0),(float)($d['price']??0),array_key_exists('active',$d)?boolv($d['active']):true,(int)$m[1]]);if(!$q->fetch())out(['error'=>'Serviço não encontrado'],404);out(['ok'=>true,'id'=>(int)$m[1]]);}

    // Horários: tabela isolada, payload flexível e sem bind de boolean/time problemático
    if(($path==='/api/admin/schedule'||$path==='/api/schedules') && $method==='GET'){ $bid=(int)($_GET['barber_id']??0);if($bid<=0)out(['error'=>'barber_id é obrigatório'],422);out(['schedule'=>getSchedule(db(),$bid)]); }
    if(($path==='/api/admin/schedule'||$path==='/api/schedules') && in_array($method,['POST','PUT'],true)){
        $d=body();$bid=(int)($d['barber_id']??0);if($bid<=0)out(['error'=>'barber_id é obrigatório'],422);$rows=$d['schedule']??$d['schedules']??$d['days']??null;if(!is_array($rows))out(['error'=>'Horários inválidos'],422);$p=db();
        $p->beginTransaction();
        try{
            $p->prepare('DELETE FROM studio_aa_schedules WHERE barber_id=?')->execute([$bid]);
            foreach($rows as $key=>$r){
                if(!is_array($r))continue;
                $day=(int)($r['weekday']??$r['day_of_week']??$r['day']??$key); if($day<0||$day>6)continue;
                $active=array_key_exists('active',$r)?boolv($r['active']):(array_key_exists('open',$r)?boolv($r['open']):true);
                $start=timev($r['start_time']??$r['start']??$r['open_time']??''); $end=timev($r['end_time']??$r['end']??$r['close_time']??'');
                $bs=timev($r['break_start']??$r['pause_start']??$r['interval_start']??''); $be=timev($r['break_end']??$r['pause_end']??$r['interval_end']??'');
                $activeSql=$active?'TRUE':'FALSE';
                $q=$p->prepare("INSERT INTO studio_aa_schedules(barber_id,day_of_week,start_time,end_time,break_start,break_end,active) VALUES(?,?,?,?,CAST(? AS TIME),CAST(? AS TIME),$activeSql)");
                $q->execute([$bid,$day,$start,$end,$bs,$be]);
            }
            $p->commit(); out(['ok'=>true,'barber_id'=>$bid,'schedule'=>getSchedule($p,$bid)]);
        }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }

    if($path==='/api/admin/dashboard' && $method==='GET'){
        $p=db();$appointments=$p->query("SELECT a.id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,TO_CHAR(a.appointment_time,'HH24:MI') AS time,a.status,c.name AS customer_name,c.phone AS customer_phone,b.name AS barber_name,s.name AS service_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN barbers b ON b.id=a.barber_id JOIN services s ON s.id=a.service_id ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC")->fetchAll();$stats=['today'=>(int)$p->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn(),'clients'=>(int)$p->query('SELECT COUNT(*) FROM customers')->fetchColumn(),'barbers'=>(int)$p->query('SELECT COUNT(*) FROM barbers WHERE active=TRUE')->fetchColumn(),'services'=>(int)$p->query('SELECT COUNT(*) FROM services WHERE active=TRUE')->fetchColumn()];out(['stats'=>$stats]+$stats+['appointments'=>$appointments]);
    }
    if(($path==='/api/admin/cancel'||$path==='/api/admin/reactivate') && $method==='POST'){$d=body();$id=(int)($d['id']??0);if($id<=0)out(['error'=>'ID inválido'],422);$status=$path==='/api/admin/cancel'?'cancelled':'pending';$q=db()->prepare("UPDATE appointments SET status=? WHERE id=? RETURNING id");$q->execute([$status,$id]);if(!$q->fetch())out(['error'=>'Agendamento não encontrado'],404);out(['ok'=>true,'id'=>$id,'status'=>$status]);}
    if($path==='/api/admin/status' && $method==='POST'){$d=body();$id=(int)($d['id']??0);$status=(string)($d['status']??'');if($id<=0||!in_array($status,['pending','confirmed','cancelled','completed'],true))out(['error'=>'Status inválido'],422);$q=db()->prepare('UPDATE appointments SET status=? WHERE id=? RETURNING id');$q->execute([$status,$id]);if(!$q->fetch())out(['error'=>'Agendamento não encontrado'],404);out(['ok'=>true,'id'=>$id,'status'=>$status]);}
    out(['error'=>'Rota não encontrada'],404);
}catch(Throwable $e){out(['error'=>'Erro interno','detail'=>$e->getMessage()],500);}
