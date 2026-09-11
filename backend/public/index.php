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

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
try {
    $p=db();

    if($path==='/api/health' && $_SERVER['REQUEST_METHOD']==='GET')
        out(['ok'=>true,'service'=>'Studio A.A API']);

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
        $x=body(); $id=(int)($x['barber_id']??0);
        if(!$id)out(['error'=>'Barbeiro inválido.'],422);
        $p->beginTransaction();
        try{
            $q=$p->prepare("DELETE FROM barber_schedules WHERE barber_id=?");$q->execute([$id]);
            $q=$p->prepare("INSERT INTO barber_schedules(barber_id,day_of_week,start_time,end_time,break_start,break_end,active) VALUES(?,?,?,?,?,?,?)");
            foreach(($x['schedules']??[]) as $r){
                $q->execute([$id,(int)$r['day_of_week'],$r['start_time']?:null,$r['end_time']?:null,$r['break_start']?:null,$r['break_end']?:null,!empty($r['active'])]);
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
