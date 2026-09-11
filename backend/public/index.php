<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization');
header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out($data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function body(): array {
    $d=json_decode(file_get_contents('php://input') ?: '{}',true);
    return is_array($d)?$d:[];
}
function db(): PDO {
    static $pdo=null;
    if($pdo instanceof PDO)return $pdo;
    $url=getenv('DATABASE_URL');
    if(!$url) out(['error'=>'DATABASE_URL não configurada'],500);
    $u=parse_url($url);
    $dsn='pgsql:host='.$u['host'].';port='.($u['port']??5432).';dbname='.ltrim($u['path']??'','/');
    $pdo=new PDO($dsn,$u['user']??'',$u['pass']??'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    init($pdo);
    return $pdo;
}
function init(PDO $p): void {
    // NÃO recria nem altera a estrutura existente de appointments.
    // A base original usa appointment_date / appointment_time e status em inglês.
    $p->exec("CREATE TABLE IF NOT EXISTS barber_schedules(
        id BIGSERIAL PRIMARY KEY,
        barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,
        day_of_week INTEGER NOT NULL,
        start_time TIME,
        end_time TIME,
        break_start TIME,
        break_end TIME,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        UNIQUE(barber_id,day_of_week)
    )");
}
function mapStatusToDb(string $s): string {
    $s=mb_strtolower(trim($s));
    return match($s){
        'cancelado','cancelled' => 'cancelled',
        'confirmado','confirmed' => 'confirmed',
        'concluído','concluido','completed' => 'completed',
        default => 'pending'
    };
}
function mapStatusToUi(string $s): string {
    return match($s){
        'cancelled'=>'Cancelado',
        'confirmed'=>'Confirmado',
        'completed'=>'Concluído',
        default=>'Pendente'
    };
}

function checkAdmin(): void {
    $expected=getenv('ADMIN_PASSWORD') ?: 'studioaa123';
    $given=(string)($_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '');
    if($given==='' || !hash_equals($expected,$given)) out(['error'=>'Não autorizado'],401);
}
function boolValue($v): bool {
    if(is_bool($v)) return $v;
    if(is_int($v) || is_float($v)) return ((int)$v)===1;
    $s=mb_strtolower(trim((string)$v));
    return in_array($s,['1','true','t','yes','sim','on','aberto','ativo'],true);
}
function timeOrNull($v): ?string {
    $s=trim((string)($v ?? ''));
    return $s==='' ? null : $s;
}

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
try {
    $p=db();

    if($path==='/api/health' && $_SERVER['REQUEST_METHOD']==='GET')
        out(['ok'=>true,'service'=>'Studio A.A API']);

    if($path==='/api/admin/login' && $_SERVER['REQUEST_METHOD']==='POST'){
        $x=body();
        $expected=getenv('ADMIN_PASSWORD') ?: 'studioaa123';
        if(hash_equals($expected,(string)($x['password']??''))) out(['ok'=>true]);
        out(['error'=>'Senha incorreta'],401);
    }

    if($path==='/api/services' && $_SERVER['REQUEST_METHOD']==='GET')
        out($p->query("SELECT id,name,duration,price,active FROM services ORDER BY sort_order,id")->fetchAll());

    if($path==='/api/barbers' && $_SERVER['REQUEST_METHOD']==='GET')
        out($p->query("SELECT id,name,specialty,rating,active FROM barbers ORDER BY id")->fetchAll());

    if($path==='/api/barbers' && $_SERVER['REQUEST_METHOD']==='POST'){
        $x=body();
        if(trim($x['name']??'')==='')out(['error'=>'Nome do barbeiro é obrigatório.'],422);
        $q=$p->prepare("INSERT INTO barbers(name,specialty,rating,active) VALUES(?,?,?,?) RETURNING id,name,specialty,rating,active");
        $q->execute([trim($x['name']),trim($x['specialty']??''),(float)($x['rating']??5),!empty($x['active'])]);
        out($q->fetch(),201);
    }

    if(preg_match('#^/api/barbers/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='PUT'){
        $x=body();
        $q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=?,active=? WHERE id=? RETURNING id,name,specialty,rating,active");
        $q->execute([trim($x['name']??''),trim($x['specialty']??''),(float)($x['rating']??5),!empty($x['active']),(int)$m[1]]);
        $r=$q->fetch();
        if(!$r)out(['error'=>'Barbeiro não encontrado.'],404);
        out($r);
    }

    if($path==='/api/services' && $_SERVER['REQUEST_METHOD']==='POST'){
        $x=body();
        if(trim($x['name']??'')==='')out(['error'=>'Nome do serviço é obrigatório.'],422);
        $q=$p->prepare("INSERT INTO services(name,duration,price,active,sort_order) VALUES(?,?,?,?,COALESCE((SELECT MAX(sort_order)+1 FROM services),1)) RETURNING id,name,duration,price,active");
        $q->execute([trim($x['name']),(int)($x['duration']??30),(float)($x['price']??0),!empty($x['active'])]);
        out($q->fetch(),201);
    }

    if(preg_match('#^/api/services/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='PUT'){
        $x=body();
        $q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=? WHERE id=? RETURNING id,name,duration,price,active");
        $q->execute([trim($x['name']??''),(int)($x['duration']??30),(float)($x['price']??0),!empty($x['active']),(int)$m[1]]);
        $r=$q->fetch();
        if(!$r)out(['error'=>'Serviço não encontrado.'],404);
        out($r);
    }

    if($path==='/api/schedules' && $_SERVER['REQUEST_METHOD']==='GET'){
        $id=(int)($_GET['barber_id']??0);
        $q=$p->prepare("SELECT day_of_week,start_time::text,end_time::text,break_start::text,break_end::text,active FROM barber_schedules WHERE barber_id=? ORDER BY day_of_week");
        $q->execute([$id]);
        out($q->fetchAll());
    }

    if($path==='/api/schedules' && $_SERVER['REQUEST_METHOD']==='PUT'){
        checkAdmin();
        $x=body(); $id=(int)($x['barber_id']??0);
        if(!$id)out(['error'=>'Barbeiro inválido.'],422);
        $items=$x['schedules'] ?? [];
        if(!is_array($items)) $items=[];
        $p->beginTransaction();
        try{
            $q=$p->prepare("DELETE FROM barber_schedules WHERE barber_id=?");$q->execute([$id]);
            $q=$p->prepare("INSERT INTO barber_schedules(barber_id,day_of_week,start_time,end_time,break_start,break_end,active) VALUES(?,?,?,?,?,?,?)");
            foreach($items as $r){
                $day=(int)($r['day_of_week'] ?? $r['weekday'] ?? 0);
                $start=timeOrNull($r['start_time'] ?? $r['open_time'] ?? null);
                $end=timeOrNull($r['end_time'] ?? $r['close_time'] ?? null);
                $bs=timeOrNull($r['break_start'] ?? null);
                $be=timeOrNull($r['break_end'] ?? null);
                $active=boolValue($r['active'] ?? false);
                $q->execute([$id,$day,$start,$end,$bs,$be,$active]);
            }
            $p->commit(); out(['ok'=>true]);
        }catch(Throwable $e){$p->rollBack();throw $e;}
    }

    // Compatibilidade com a tela de gerenciamento que usa /api/admin/schedule.
    if($path==='/api/admin/schedule' && $_SERVER['REQUEST_METHOD']==='GET'){
        checkAdmin();
        $id=(int)($_GET['barber_id']??0);
        $q=$p->prepare("SELECT day_of_week AS weekday,start_time::text AS open_time,end_time::text AS close_time,break_start::text AS break_start,break_end::text AS break_end,active FROM barber_schedules WHERE barber_id=? ORDER BY day_of_week");
        $q->execute([$id]);
        $rows=$q->fetchAll();
        $by=[]; foreach($rows as $r) $by[(int)$r['weekday']=$r];
        $out=[];
        for($i=0;$i<7;$i++) $out[]=$by[$i] ?? ['weekday'=>$i,'open_time'=>'09:00','close_time'=>'18:00','break_start'=>'12:30','break_end'=>'14:00','active'=>false];
        out(['schedule'=>$out]);
    }

    if($path==='/api/admin/schedule' && $_SERVER['REQUEST_METHOD']==='POST'){
        $x=body(); $id=(int)($x['barber_id']??0);
        if(!$id)out(['error'=>'Barbeiro inválido.'],422);
        $items=$x['schedule'] ?? [];
        if(!is_array($items)) $items=[];
        $p->beginTransaction();
        try{
            $q=$p->prepare("DELETE FROM barber_schedules WHERE barber_id=?");$q->execute([$id]);
            $q=$p->prepare("INSERT INTO barber_schedules(barber_id,day_of_week,start_time,end_time,break_start,break_end,active) VALUES(?,?,?,?,?,?,?)");
            foreach($items as $r){
                $day=(int)($r['day_of_week'] ?? $r['weekday'] ?? 0);
                $start=timeOrNull($r['start_time'] ?? $r['open_time'] ?? null);
                $end=timeOrNull($r['end_time'] ?? $r['close_time'] ?? null);
                $bs=timeOrNull($r['break_start'] ?? null);
                $be=timeOrNull($r['break_end'] ?? null);
                $active=boolValue($r['active'] ?? false);
                $q->execute([$id,$day,$start,$end,$bs,$be,$active]);
            }
            $p->commit(); out(['ok'=>true]);
        }catch(Throwable $e){$p->rollBack();throw $e;}
    }

    if($path==='/api/availability' && $_SERVER['REQUEST_METHOD']==='GET'){
        $id=(int)($_GET['barber_id']??0);$date=trim((string)($_GET['date']??''));
        if(!$id||!$date)out(['error'=>'barber_id e date são obrigatórios'],422);
        $q=$p->prepare("SELECT a.id,
                TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,
                TO_CHAR(a.appointment_time,'HH24:MI') AS time,
                a.barber_id,a.status
            FROM appointments a
            WHERE a.barber_id=? AND a.appointment_date=?
              AND a.status IN ('pending','confirmed')
            ORDER BY a.appointment_time");
        $q->execute([$id,$date]);
        $rows=$q->fetchAll();
        foreach($rows as &$r)$r['status']=mapStatusToUi($r['status']);
        out($rows);
    }

    if($path==='/api/admin/barbers' && $_SERVER['REQUEST_METHOD']==='GET'){
        checkAdmin();
        out($p->query("SELECT id,name,specialty,rating,active FROM barbers ORDER BY id")->fetchAll());
    }
    if($path==='/api/admin/barbers' && $_SERVER['REQUEST_METHOD']==='POST'){
        checkAdmin(); $x=body(); $id=(int)($x['id']??0);
        if($id){
            $old=$p->prepare("SELECT active FROM barbers WHERE id=?");$old->execute([$id]);$oldActive=$old->fetchColumn();
            $active=array_key_exists('active',$x) ? boolValue($x['active']) : (bool)$oldActive;
            $q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=?,active=? WHERE id=? RETURNING id,name,specialty,rating,active");
            $q->execute([trim($x['name']??''),trim($x['specialty']??''),(float)($x['rating']??5),$active,$id]);
        }else{
            $q=$p->prepare("INSERT INTO barbers(name,specialty,rating,active) VALUES(?,?,?,?) RETURNING id,name,specialty,rating,active");
            $q->execute([trim($x['name']??''),trim($x['specialty']??''),(float)($x['rating']??5),boolValue($x['active']??true)]);
        }
        $r=$q->fetch(); if(!$r)out(['error'=>'Barbeiro não encontrado.'],404); out($r);
    }
    if($path==='/api/admin/services' && $_SERVER['REQUEST_METHOD']==='GET'){
        checkAdmin(); out($p->query("SELECT id,name,duration,price,active FROM services ORDER BY sort_order,id")->fetchAll());
    }
    if($path==='/api/admin/services' && $_SERVER['REQUEST_METHOD']==='POST'){
        checkAdmin(); $x=body(); $id=(int)($x['id']??0);
        if($id){
            $old=$p->prepare("SELECT active FROM services WHERE id=?");$old->execute([$id]);$oldActive=$old->fetchColumn();
            $active=array_key_exists('active',$x) ? boolValue($x['active']) : (bool)$oldActive;
            $q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=? WHERE id=? RETURNING id,name,duration,price,active");
            $q->execute([trim($x['name']??''),(int)($x['duration']??30),(float)($x['price']??0),$active,$id]);
        }else{
            $q=$p->prepare("INSERT INTO services(name,duration,price,active,sort_order) VALUES(?,?,?,?,COALESCE((SELECT MAX(sort_order)+1 FROM services),1)) RETURNING id,name,duration,price,active");
            $q->execute([trim($x['name']??''),(int)($x['duration']??30),(float)($x['price']??0),boolValue($x['active']??true)]);
        }
        $r=$q->fetch(); if(!$r)out(['error'=>'Serviço não encontrado.'],404); out($r);
    }

    if($path==='/api/appointments' && $_SERVER['REQUEST_METHOD']==='POST'){
        $x=body();
        foreach(['service_id','barber_id','customer_name','customer_phone','date','time'] as $k)
            if(empty($x[$k]))out(['error'=>"Campo obrigatório: {$k}"],422);
        $p->beginTransaction();
        try{
            $lock=sprintf('%u',crc32((string)((int)$x['barber_id'].'|'.$x['date'].'|'.$x['time'])));
            $p->prepare("SELECT pg_advisory_xact_lock(CAST(? AS bigint))")->execute([$lock]);

            $q=$p->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed')");
            $q->execute([(int)$x['barber_id'],$x['date'],$x['time']]);
            if((int)$q->fetchColumn()>0){$p->rollBack();out(['error'=>'Horário já ocupado'],409);}

            $q=$p->prepare("SELECT id FROM customers WHERE phone=?");$q->execute([$x['customer_phone']]);
            $c=$q->fetch();
            if($c){$cid=(int)$c['id'];$p->prepare("UPDATE customers SET name=? WHERE id=?")->execute([$x['customer_name'],$cid]);}
            else{$q=$p->prepare("INSERT INTO customers(name,phone) VALUES(?,?) RETURNING id");$q->execute([$x['customer_name'],$x['customer_phone']]);$cid=(int)$q->fetchColumn();}

            $q=$p->prepare("INSERT INTO appointments(customer_id,service_id,barber_id,appointment_date,appointment_time,status) VALUES(?,?,?,?,?,'pending') RETURNING id");
            $q->execute([$cid,(int)$x['service_id'],(int)$x['barber_id'],$x['date'],$x['time']]);
            $id=(int)$q->fetchColumn();$p->commit();
            out(['id'=>$id,'status'=>'Pendente'],201);
        }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }

    if(preg_match('#^/api/appointments/(\d+)$#',$path,$m) && $_SERVER['REQUEST_METHOD']==='PATCH'){
        $x=body();$status=mapStatusToDb((string)($x['status']??'Cancelado'));
        $q=$p->prepare("UPDATE appointments SET status=? WHERE id=? RETURNING id,status");
        $q->execute([$status,(int)$m[1]]);
        $r=$q->fetch();if(!$r)out(['error'=>'Agendamento não encontrado.'],404);
        $r['status']=mapStatusToUi($r['status']);out($r);
    }

    if($path==='/api/admin/cancel' && $_SERVER['REQUEST_METHOD']==='POST'){
        checkAdmin(); $x=body(); $q=$p->prepare("UPDATE appointments SET status='cancelled' WHERE id=? RETURNING id,status");$q->execute([(int)($x['id']??0)]);$r=$q->fetch();if(!$r)out(['error'=>'Agendamento não encontrado.'],404);$r['status']='Cancelado';out($r);
    }
    if($path==='/api/admin/reactivate' && $_SERVER['REQUEST_METHOD']==='POST'){
        checkAdmin(); $x=body(); $q=$p->prepare("UPDATE appointments SET status='pending' WHERE id=? RETURNING id,status");$q->execute([(int)($x['id']??0)]);$r=$q->fetch();if(!$r)out(['error'=>'Agendamento não encontrado.'],404);$r['status']='Pendente';out($r);
    }

    if($path==='/api/admin/dashboard' && $_SERVER['REQUEST_METHOD']==='GET'){
        $today=(int)$p->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn();
        $clients=(int)$p->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        $barbers=(int)$p->query("SELECT COUNT(*) FROM barbers WHERE active=TRUE")->fetchColumn();
        $services=(int)$p->query("SELECT COUNT(*) FROM services WHERE active=TRUE")->fetchColumn();

        $rows=$p->query("SELECT a.id,
                TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,
                TO_CHAR(a.appointment_time,'HH24:MI') AS time,
                a.status,c.name AS customer_name,c.phone AS customer_phone,
                b.name AS barber_name,b.id AS barber_id,s.name AS service_name
            FROM appointments a
            JOIN customers c ON c.id=a.customer_id
            JOIN barbers b ON b.id=a.barber_id
            JOIN services s ON s.id=a.service_id
            ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC")->fetchAll();
        foreach($rows as &$r)$r['status']=mapStatusToUi($r['status']);

        out(['today'=>$today,'clients'=>$clients,'barbers'=>$barbers,'services'=>$services,'appointments'=>$rows]);
    }

    out(['error'=>'Rota não encontrada'],404);
}catch(Throwable $e){
    out(['error'=>$e->getMessage()],500);
}
?>
