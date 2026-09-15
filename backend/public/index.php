<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
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
    $p->exec("CREATE TABLE IF NOT EXISTS push_tokens(id BIGSERIAL PRIMARY KEY,phone VARCHAR(40) NOT NULL UNIQUE,expo_push_token TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE INDEX IF NOT EXISTS idx_push_tokens_phone ON push_tokens(phone)");
    $p->exec("CREATE TABLE IF NOT EXISTS barber_push_tokens(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL UNIQUE REFERENCES barbers(id) ON DELETE CASCADE,expo_push_token TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE TABLE IF NOT EXISTS barber_accounts(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL UNIQUE REFERENCES barbers(id) ON DELETE CASCADE,password_hash TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
    $p->exec("CREATE TABLE IF NOT EXISTS barber_sessions(token_hash CHAR(64) PRIMARY KEY,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),expires_at TIMESTAMPTZ NOT NULL)");
    $p->exec("CREATE INDEX IF NOT EXISTS idx_barber_push_tokens_barber ON barber_push_tokens(barber_id)");
    $p->exec("CREATE TABLE IF NOT EXISTS studio_aa_schedules_v2(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,day_of_week INTEGER NOT NULL,start_time TIME,end_time TIME,break_start TIME,break_end TIME,active BOOLEAN NOT NULL DEFAULT TRUE,UNIQUE(barber_id,day_of_week))");
    foreach ([
        'day_of_week'=>'INTEGER', 'start_time'=>'TIME', 'end_time'=>'TIME',
        'break_start'=>'TIME', 'break_end'=>'TIME', 'active'=>'BOOLEAN NOT NULL DEFAULT TRUE'
    ] as $col=>$type) { $p->exec("ALTER TABLE studio_aa_schedules_v2 ADD COLUMN IF NOT EXISTS $col $type"); }
    $services=[['Corte Masculino',30,35,1],['Barba Tradicional',20,25,2],['Combo Corte + Barba',50,55,3],['Sobrancelha',15,15,4],['Pigmentação de Barba',30,40,5]];
    $q=$p->prepare("INSERT INTO services(name,duration,price,sort_order) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM services WHERE name=? )"); foreach($services as $x)$q->execute([$x[0],$x[1],$x[2],$x[3],$x[0]]);
    $barbers=[['Lucas','Especialista em degradê',4.9],['Rafael','Barba e bigode',4.8],['Thiago','Corte masculino',4.7],['Matheus','Estilo clássico',4.9]];
    $q=$p->prepare("INSERT INTO barbers(name,specialty,rating) SELECT ?,?,? WHERE NOT EXISTS(SELECT 1 FROM barbers WHERE name=? )"); foreach($barbers as $x)$q->execute([$x[0],$x[1],$x[2],$x[0]]);
    // Cria contas individuais para ALBERI e ALEX, sem sobrescrever senhas já alteradas.
    $defaultHash=password_hash('1234', PASSWORD_DEFAULT);
    $q=$p->prepare("SELECT id FROM barbers WHERE UPPER(name)=? LIMIT 1");
    $ins=$p->prepare("INSERT INTO barber_accounts(barber_id,password_hash) SELECT ?,? WHERE NOT EXISTS(SELECT 1 FROM barber_accounts WHERE barber_id=?)");
    foreach(['ALBERI','ALEX'] as $nm){ $q->execute([$nm]); $bid=$q->fetchColumn(); if($bid!==false) $ins->execute([(int)$bid,$defaultHash,(int)$bid]); }
}
function boolv($v): bool { if(is_bool($v)) return $v; $s=strtolower(trim((string)$v)); return in_array($s,['1','true','on','yes','sim','aberto','active'],true); }
function timev($v): ?string { $s=trim((string)$v); if($s==='') return null; if(preg_match('/^\d{1,2}:\d{2}$/',$s)){ [$h,$m]=array_map('intval',explode(':',$s)); if($h>=0&&$h<=23&&$m>=0&&$m<=59) return sprintf('%02d:%02d',$h,$m); } if(preg_match('/^\d{1,2}:\d{2}:\d{2}$/',$s)){ [$h,$m,$sec]=array_map('intval',explode(':',$s)); if($h>=0&&$h<=23&&$m>=0&&$m<=59&&$sec>=0&&$sec<=59) return sprintf('%02d:%02d:%02d',$h,$m,$sec); } return null; }
function datev($v): ?string { $s=trim((string)$v); if($s==='') return null; if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)){ $dt=DateTime::createFromFormat('Y-m-d',$s); return ($dt&&$dt->format('Y-m-d')===$s)?$s:null; } if(preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$s,$m)){ $dt=DateTime::createFromFormat('Y-m-d',"{$m[3]}-{$m[2]}-{$m[1]}"); return $dt?$dt->format('Y-m-d'):null; } return null; }
function adminOk(): bool { return true; } // compatibilidade com o painel atual
function sendExpoPush(string $token, string $title, string $message, array $data=[]): void {
    if($token==='' || !preg_match('/^ExponentPushToken\[.+\]$/',$token)) return;
    $payload=json_encode(['to'=>$token,'title'=>$title,'body'=>$message,'sound'=>'default','data'=>$data],JSON_UNESCAPED_UNICODE);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>$payload,'timeout'=>8,'ignore_errors'=>true]]);
    @file_get_contents('https://exp.host/--/api/v2/push/send',false,$ctx);
}
function notifyAppointmentBarber(PDO $p,int $id): void {
    try {
        $q=$p->prepare("SELECT a.id,a.barber_id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,TO_CHAR(a.appointment_time,'HH24:MI') AS time,c.name AS customer_name,s.name AS service_name,b.name AS barber_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id JOIN barbers b ON b.id=a.barber_id WHERE a.id=?");
        $q->execute([$id]); $a=$q->fetch(); if(!$a) return;
        $q=$p->prepare('SELECT expo_push_token FROM barber_push_tokens WHERE barber_id=?'); $q->execute([(int)$a['barber_id']]); $token=(string)($q->fetchColumn()?:''); if($token==='') return;
        $title='🔔 Novo agendamento';
        $body="{$a['customer_name']} — {$a['service_name']} em {$a['date']} às {$a['time']}";
        sendExpoPush($token,$title,$body,['appointment_id'=>(int)$a['id'],'barber_id'=>(int)$a['barber_id'],'status'=>'pending']);
    } catch(Throwable $e) { error_log('Studio A.A barber push: '.$e->getMessage()); }
}
function notifyAppointmentCustomer(PDO $p,int $id,string $status): void {
    try {
        $q=$p->prepare("SELECT a.id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,TO_CHAR(a.appointment_time,'HH24:MI') AS time,c.phone,s.name AS service_name,b.name AS barber_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id JOIN barbers b ON b.id=a.barber_id WHERE a.id=?");
        $q->execute([$id]); $a=$q->fetch(); if(!$a) return;
        $q=$p->prepare('SELECT expo_push_token FROM push_tokens WHERE phone=?'); $q->execute([$a['phone']]); $token=(string)($q->fetchColumn()?:''); if($token==='') return;
        $labels=['pending'=>'Pendente','confirmed'=>'Confirmado','cancelled'=>'Cancelado','completed'=>'Concluído'];
        $label=$labels[$status]??$status;
        $title="Agendamento $label";
        $body="{$a['service_name']} com {$a['barber_name']} — {$a['date']} às {$a['time']}";
        sendExpoPush($token,$title,$body,['appointment_id'=>(int)$a['id'],'status'=>$status]);
    } catch(Throwable $e) { error_log('Studio A.A push: '.$e->getMessage()); }
}
function getSchedule(PDO $p,int $barber): array {
    $q=$p->prepare("SELECT day_of_week AS weekday, day_of_week, active AS open, active, start_time AS start, start_time, end_time AS end, end_time, break_start, break_end FROM studio_aa_schedules_v2 WHERE barber_id=? ORDER BY day_of_week");
    $q->execute([$barber]);
    return $q->fetchAll();
}
function bearerToken(): string {
    $headers=[];
    foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','HTTP_X_BARBER_TOKEN','HTTP_X_ACCESS_TOKEN'] as $k){ if(!empty($_SERVER[$k])) $headers[]=(string)$_SERVER[$k]; }
    foreach($headers as $h){
        if(preg_match('/^Bearer\s+(.+)$/i',trim($h),$m)) return trim($m[1]);
        if(preg_match('/^[A-Fa-f0-9]{40,}$/',trim($h))) return trim($h);
    }
    return '';
}
function barberAuth(PDO $p): array {
    $token=bearerToken();
    if($token==='') out(['error'=>'Não autenticado'],401);
    $hash=hash('sha256',$token);
    $q=$p->prepare("SELECT s.barber_id,b.name FROM barber_sessions s JOIN barbers b ON b.id=s.barber_id WHERE s.token_hash=? AND s.expires_at>NOW() AND b.active=TRUE");
    $q->execute([$hash]); $row=$q->fetch();
    if(!$row) out(['error'=>'Sessão expirada ou inválida'],401);
    $name=strtoupper(trim((string)$row['name']));
    if(!in_array($name,['ALBERI','ALEX'],true)) out(['error'=>'Acesso não autorizado'],403);
    return ['barber_id'=>(int)$row['barber_id'],'name'=>$name];
}
function barberOnlyName(PDO $p,int $barberId): void {
    $q=$p->prepare("SELECT UPPER(name) FROM barbers WHERE id=?"); $q->execute([$barberId]);
    $name=(string)($q->fetchColumn()?:'');
    if(!in_array($name,['ALBERI','ALEX'],true)) out(['error'=>'Acesso disponível apenas para ALBERI e ALEX nesta versão'],403);
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/'; $method=$_SERVER['REQUEST_METHOD'];
try {
    if($path==='/api/health' && $method==='GET') out(['ok'=>true,'service'=>'Studio A.A API','version'=>'push-notifications-v17-barber-auth']);
    if($path==='/api/services' && $method==='GET') out(db()->query("SELECT id,name,duration,price FROM services WHERE active=TRUE ORDER BY sort_order,id")->fetchAll());
    if($path==='/api/barbers' && $method==='GET') out(db()->query("SELECT id,name,specialty,rating FROM barbers WHERE active=TRUE ORDER BY name")->fetchAll());

    if($path==='/api/appointments' && $method==='POST') {
        $d=body();
        $service=(int)($d['service_id']??$d['serviceId']??$d['service']??0);
        $barber=(int)($d['barber_id']??$d['barberId']??$d['barber']??0);
        $name=trim((string)($d['customer_name']??$d['customerName']??$d['name']??$d['cliente_nome']??''));
        $phone=trim((string)($d['customer_phone']??$d['customerPhone']??$d['phone']??$d['telefone']??$d['cliente_telefone']??''));
        $date=datev($d['date']??$d['appointment_date']??$d['booking_date']??$d['data']??'');
        $time=timev($d['time']??$d['appointment_time']??$d['booking_time']??$d['horario']??'');
        if($service<=0) out(['error'=>'Serviço inválido'],422);
        if($barber<=0) out(['error'=>'Barbeiro inválido'],422);
        if($name==='') out(['error'=>'Nome do cliente é obrigatório'],422);
        if($phone==='') out(['error'=>'WhatsApp do cliente é obrigatório'],422);
        if($date===null) out(['error'=>'Data inválida'],422);
        if($time===null) out(['error'=>'Horário inválido'],422);
        $p=db();
        try {
            $lockKey=(int)sprintf('%u',crc32($barber.'|'.$date.'|'.$time));
            $p->beginTransaction();
            $p->query('SELECT pg_advisory_xact_lock('.$lockKey.')');
            $q=$p->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed')");
            $q->execute([$barber,$date,$time]);
            if((int)$q->fetchColumn()>0){$p->rollBack();out(['error'=>'Horário já ocupado'],409);}
            $q=$p->prepare('SELECT id FROM customers WHERE phone=?'); $q->execute([$phone]); $c=$q->fetch();
            if($c){$cid=(int)$c['id'];$p->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$name,$cid]);}
            else{$q=$p->prepare('INSERT INTO customers(name,phone) VALUES(?,?) RETURNING id');$q->execute([$name,$phone]);$cid=(int)$q->fetchColumn();}
            $q=$p->prepare("INSERT INTO appointments(customer_id,service_id,barber_id,appointment_date,appointment_time,status) VALUES(?,?,?,?,?,'pending') RETURNING id");
            $q->execute([$cid,$service,$barber,$date,$time]);
            $id=(int)$q->fetchColumn(); $p->commit(); notifyAppointmentBarber($p,$id); out(['ok'=>true,'id'=>$id,'status'=>'pending'],201);
        } catch(Throwable $e) {
            if($p->inTransaction())$p->rollBack();
            out(['error'=>'Não foi possível criar o agendamento','detail'=>$e->getMessage()],500);
        }
    }
    if($path==='/api/push/register' && $method==='POST') {
        $d=body(); $phone=trim((string)($d['phone']??'')); $token=trim((string)($d['expo_push_token']??$d['expoPushToken']??''));
        if($phone==='' || $token==='') out(['error'=>'phone e expo_push_token são obrigatórios'],422);
        if(!preg_match('/^ExponentPushToken\[.+\]$/',$token)) out(['error'=>'Token Expo inválido'],422);
        $p=db(); $q=$p->prepare("INSERT INTO push_tokens(phone,expo_push_token,updated_at) VALUES(?,?,NOW()) ON CONFLICT(phone) DO UPDATE SET expo_push_token=EXCLUDED.expo_push_token,updated_at=NOW()"); $q->execute([$phone,$token]);
        out(['ok'=>true]);
    }

    if($path==='/api/barber/push/register' && $method==='POST') {
        $p=db(); $auth=barberAuth($p);
        $d=body(); $barberId=(int)($d['barber_id']??0); $token=trim((string)($d['expo_push_token']??$d['expoPushToken']??''));
        if($barberId!==$auth['barber_id']) out(['error'=>'Token não autorizado para este barbeiro'],403);
        if($barberId<=0 || $token==='') out(['error'=>'barber_id e expo_push_token são obrigatórios'],422);
        if(!preg_match('/^ExponentPushToken\[.+\]$/',$token)) out(['error'=>'Token Expo inválido'],422);
        $p=db(); $check=$p->prepare('SELECT id FROM barbers WHERE id=? AND active=TRUE'); $check->execute([$barberId]); if(!$check->fetchColumn()) out(['error'=>'Barbeiro não encontrado ou inativo'],404);
        $q=$p->prepare("INSERT INTO barber_push_tokens(barber_id,expo_push_token,updated_at) VALUES(?,?,NOW()) ON CONFLICT(barber_id) DO UPDATE SET expo_push_token=EXCLUDED.expo_push_token,updated_at=NOW()"); $q->execute([$barberId,$token]);
        out(['ok'=>true,'barber_id'=>$barberId]);
    }

    // Cliente: consulta seus próprios agendamentos pelo telefone/WhatsApp
    if($path==='/api/client/appointments' && $method==='GET') {
        $phone=trim((string)($_GET['phone']??$_GET['telefone']??''));
        if($phone==='') out(['error'=>'Telefone é obrigatório'],422);
        $q=db()->prepare("SELECT a.id,a.barber_id,a.service_id,a.customer_id,
                    TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,
                    TO_CHAR(a.appointment_time,'HH24:MI') AS time,
                    a.status,c.name AS customer_name,c.phone AS customer_phone,
                    b.name AS barber_name,s.name AS service_name,s.duration,s.price
             FROM appointments a
             JOIN customers c ON c.id=a.customer_id
             JOIN barbers b ON b.id=a.barber_id
             JOIN services s ON s.id=a.service_id
             WHERE c.phone=?
             ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC");
        $q->execute([$phone]); out($q->fetchAll());
    }

    if($path==='/api/availability' && $method==='GET') {
        $bid=(int)($_GET['barber_id']??0);$date=trim((string)($_GET['date']??''));if($bid<=0||$date==='')out(['error'=>'barber_id e date são obrigatórios'],422);
        $q=db()->prepare("SELECT a.id,a.appointment_date AS date,TO_CHAR(a.appointment_time,'HH24:MI') AS time,a.barber_id,b.name AS barber_name,c.name AS customer_name,c.phone AS customer_phone,s.name AS service_name,a.status FROM appointments a JOIN barbers b ON b.id=a.barber_id JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.barber_id=? AND a.appointment_date=? AND a.status IN ('pending','confirmed') ORDER BY a.appointment_time");$q->execute([$bid,$date]);$appointments=$q->fetchAll();
        // O frontend atual espera um objeto com a chave appointments. Mantemos também available_times para futuras telas.
        $available=[];
        try { $dow=(int)(new DateTime($date))->format('w'); $sq=db()->prepare("SELECT active,start_time,end_time,break_start,break_end FROM studio_aa_schedules_v2 WHERE barber_id=? AND day_of_week=? LIMIT 1"); $sq->execute([$bid,$dow]); $sch=$sq->fetch(); if($sch && (bool)$sch['active']) { $start=$sch['start_time']; $end=$sch['end_time']; for($m=0;$m<1440;$m+=30){$hh=intdiv($m,60);$mm=$m%60;$t=sprintf('%02d:%02d',$hh,$mm); if($t<$start||$t>$end) continue; if($sch['break_start'] && $sch['break_end'] && $t>=$sch['break_start'] && $t<$sch['break_end']) continue; $busy=false; foreach($appointments as $a){if(substr((string)$a['time'],0,5)===$t){$busy=true;break;}} if(!$busy)$available[]=$t;}} } catch(Throwable $e) {}
        out(['appointments'=>$appointments,'available_times'=>$available]);
    }
    if($path==='/api/barber/login' && $method==='POST') {
        $d=body(); $barberId=(int)($d['barber_id']??0); $password=(string)($d['password']??'');
        if($barberId<=0 || $password==='') out(['error'=>'Barbeiro e senha são obrigatórios'],422);
        $p=db(); barberOnlyName($p,$barberId);
        $q=$p->prepare("SELECT b.id,b.name,b.active,ba.password_hash FROM barbers b JOIN barber_accounts ba ON ba.barber_id=b.id WHERE b.id=? AND b.active=TRUE");
        $q->execute([$barberId]); $a=$q->fetch();
        if(!$a || !password_verify($password,(string)$a['password_hash'])) out(['error'=>'Senha incorreta'],401);
        $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
        $q=$p->prepare("INSERT INTO barber_sessions(token_hash,barber_id,expires_at) VALUES(?,?,NOW()+INTERVAL '30 days')"); $q->execute([$hash,$barberId]);
        out(['ok'=>true,'token'=>$token,'barber'=>['id'=>(int)$a['id'],'name'=>$a['name']]]);
    }
    if($path==='/api/barber/change-password' && $method==='POST') {
        $p=db(); $auth=barberAuth($p); barberOnlyName($p,$auth['barber_id']); $d=body();
        $current=(string)($d['current_password']??''); $new=(string)($d['new_password']??''); $confirm=(string)($d['confirm_password']??'');
        if($current==='' || $new==='' || $confirm==='') out(['error'=>'Preencha todos os campos'],422);
        if(strlen($new)<4) out(['error'=>'A nova senha deve ter pelo menos 4 caracteres'],422);
        if($new!==$confirm) out(['error'=>'A confirmação da senha não confere'],422);
        $q=$p->prepare('SELECT password_hash FROM barber_accounts WHERE barber_id=?'); $q->execute([$auth['barber_id']]); $hash=(string)($q->fetchColumn()?:'');
        if($hash==='' || !password_verify($current,$hash)) out(['error'=>'Senha atual incorreta'],401);
        if(password_verify($new,$hash)) out(['error'=>'A nova senha deve ser diferente da senha atual'],422);
        $q=$p->prepare('UPDATE barber_accounts SET password_hash=?,updated_at=NOW() WHERE barber_id=?'); $q->execute([password_hash($new,PASSWORD_DEFAULT),$auth['barber_id']]);
        // Revoga outras sessões do mesmo barbeiro; mantém a sessão atual para evitar logout inesperado no aparelho que fez a troca.
        $current=bearerToken();
        if($current!=='') {
            $currentHash=hash('sha256',$current);
            $q=$p->prepare('DELETE FROM barber_sessions WHERE barber_id=? AND token_hash<>?');
            $q->execute([$auth['barber_id'],$currentHash]);
        } else {
            $p->prepare('DELETE FROM barber_sessions WHERE barber_id=?')->execute([$auth['barber_id']]);
        }
        out(['ok'=>true,'message'=>'Senha alterada com sucesso']);
    }
    if($path==='/api/barber/logout' && $method==='POST') {
        $p=db(); $token=bearerToken(); if($token!=='') $p->prepare('DELETE FROM barber_sessions WHERE token_hash=?')->execute([hash('sha256',$token)]); out(['ok'=>true]);
    }
    if($path==='/api/barber/me' && $method==='GET') { $p=db(); $auth=barberAuth($p); out(['ok'=>true,'barber'=>$auth]); }
    if($path==='/api/barber/dashboard' && $method==='GET') {
        $p=db(); $auth=barberAuth($p); $date=trim((string)($_GET['date']??'')); if($date==='' || datev($date)===null) out(['error'=>'Data inválida'],422);
        $q=$p->prepare("SELECT a.id,a.barber_id,a.service_id,a.customer_id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,TO_CHAR(a.appointment_time,'HH24:MI') AS time,a.status,c.name AS customer_name,c.phone AS customer_phone,s.name AS service_name,s.price,b.name AS barber_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN barbers b ON b.id=a.barber_id JOIN services s ON s.id=a.service_id WHERE a.barber_id=? AND a.appointment_date=? ORDER BY a.appointment_time,a.id");
        $q->execute([$auth['barber_id'],$date]); out(['appointments'=>$q->fetchAll(),'barber'=>$auth]);
    }
    if($path==='/api/barber/status' && $method==='POST') {
        $p=db(); $auth=barberAuth($p); $d=body(); $id=(int)($d['id']??0); $status=(string)($d['status']??'');
        if($id<=0 || !in_array($status,['confirmed','cancelled','completed'],true)) out(['error'=>'Status inválido'],422);
        $q=$p->prepare('SELECT status FROM appointments WHERE id=? AND barber_id=?'); $q->execute([$id,$auth['barber_id']]); $old=$q->fetchColumn();
        if($old===false) out(['error'=>'Agendamento não encontrado para este barbeiro'],404);
        $q=$p->prepare('UPDATE appointments SET status=? WHERE id=? AND barber_id=? RETURNING id'); $q->execute([$status,$id,$auth['barber_id']]); if(!$q->fetch()) out(['error'=>'Agendamento não encontrado'],404);
        if((string)$old!==$status) notifyAppointmentCustomer($p,$id,$status);
        out(['ok'=>true,'id'=>$id,'status'=>$status]);
    }

    if($path==='/api/admin/login' && $method==='POST') { $d=body();$configured=getenv('ADMIN_PASSWORD')?:'studioaa123';if(!hash_equals($configured,(string)($d['password']??'')))out(['error'=>'Senha incorreta'],401);out(['ok'=>true]); }

    // Painel: barbers - aceita POST e PUT, sem exigir senha no backend para compatibilidade
    if($path==='/api/admin/barbers' && $method==='GET') out(db()->query("SELECT id,name,specialty,rating,active FROM barbers ORDER BY name,id")->fetchAll());
    if($path==='/api/admin/barbers' && in_array($method,['POST','PUT'],true)) {
        $d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));if($name==='')out(['error'=>'Nome do barbeiro é obrigatório'],422);
        $spec=(string)($d['specialty']??'');$rating=(float)($d['rating']??5);$active=array_key_exists('active',$d)?boolv($d['active']):true;$p=db();
        if($id>0){$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=? ,active=$activeSql WHERE id=? RETURNING id");$q->execute([$name,$spec,$rating,$id]);if(!$q->fetch())out(['error'=>'Barbeiro não encontrado'],404);}else{$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("INSERT INTO barbers(name,specialty,rating,active) VALUES(?,?,?,$activeSql) RETURNING id");$q->execute([$name,$spec,$rating]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);
    }
    if(preg_match('#^/api/barbers/(\d+)$#',$path,$m) && $method==='PUT'){ $d=body();$name=trim((string)($d['name']??''));if($name==='')out(['error'=>'Nome do barbeiro é obrigatório'],422);$active=array_key_exists('active',$d)?boolv($d['active']):true;$activeSql=$active?'TRUE':'FALSE';$p=db();$q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=?,active=$activeSql WHERE id=? RETURNING id");$q->execute([$name,(string)($d['specialty']??''),(float)($d['rating']??5),(int)$m[1]]);if(!$q->fetch())out(['error'=>'Barbeiro não encontrado'],404);out(['ok'=>true,'id'=>(int)$m[1]]); }

    // Painel: services
    if($path==='/api/admin/services' && $method==='GET') out(db()->query('SELECT id,name,duration,price,active,sort_order FROM services ORDER BY sort_order,id')->fetchAll());
    if($path==='/api/admin/services' && in_array($method,['POST','PUT'],true)){
        $d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$duration=(int)($d['duration']??0);$price=(float)($d['price']??0);if($name===''||$duration<=0)out(['error'=>'Nome e duração são obrigatórios'],422);$active=array_key_exists('active',$d)?boolv($d['active']):true;$sort=(int)($d['sort_order']??0);$p=db();
        if($id>0){$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=$activeSql,sort_order=? WHERE id=? RETURNING id");$q->execute([$name,$duration,$price,$sort,$id]);if(!$q->fetch())out(['error'=>'Serviço não encontrado'],404);}else{$activeSql=$active?'TRUE':'FALSE';$q=$p->prepare("INSERT INTO services(name,duration,price,active,sort_order) VALUES(?,?,?,$activeSql,?) RETURNING id");$q->execute([$name,$duration,$price,$sort]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);
    }
    if(preg_match('#^/api/services/(\d+)$#',$path,$m) && $method==='PUT'){ $d=body();$name=trim((string)($d['name']??''));$duration=(int)($d['duration']??0);$price=(float)($d['price']??0);if($name===''||$duration<=0)out(['error'=>'Nome e duração são obrigatórios'],422);$active=array_key_exists('active',$d)?boolv($d['active']):true;$activeSql=$active?'TRUE':'FALSE';$p=db();$q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=$activeSql WHERE id=? RETURNING id");$q->execute([$name,$duration,$price,(int)$m[1]]);if(!$q->fetch())out(['error'=>'Serviço não encontrado'],404);out(['ok'=>true,'id'=>(int)$m[1]]);}

    // Horários: tabela isolada, payload flexível e sem bind de boolean/time problemático
    if(($path==='/api/admin/schedule'||$path==='/api/schedules') && $method==='GET'){ $bid=(int)($_GET['barber_id']??0);if($bid<=0)out(['error'=>'barber_id é obrigatório'],422);$rows=getSchedule(db(),$bid);if($path==='/api/schedules')out(array_map(function($r){return ['day_of_week'=>(int)$r['day_of_week'],'active'=>(bool)$r['active'],'start_time'=>$r['start_time'],'end_time'=>$r['end_time'],'break_start'=>$r['break_start'],'break_end'=>$r['break_end']];},$rows));out(['schedule'=>$rows]); }
    if(($path==='/api/admin/schedule'||$path==='/api/schedules') && in_array($method,['POST','PUT'],true)){
        $d=body();$bid=(int)($d['barber_id']??0);if($bid<=0)out(['error'=>'barber_id é obrigatório'],422);$rows=$d['schedule']??$d['schedules']??$d['days']??null;if(!is_array($rows))out(['error'=>'Horários inválidos'],422);$p=db();
        $p->beginTransaction();
        try{
            $p->prepare('DELETE FROM studio_aa_schedules_v2 WHERE barber_id=?')->execute([$bid]);
            foreach($rows as $key=>$r){
                if(!is_array($r))continue;
                $day=(int)($r['weekday']??$r['day_of_week']??$r['day']??$key); if($day<0||$day>6)continue;
                $active=array_key_exists('active',$r)?boolv($r['active']):(array_key_exists('open',$r)?boolv($r['open']):true);
                $start=timev($r['start_time']??$r['start']??$r['open_time']??''); $end=timev($r['end_time']??$r['end']??$r['close_time']??'');
                $bs=timev($r['break_start']??$r['pause_start']??$r['interval_start']??''); $be=timev($r['break_end']??$r['pause_end']??$r['interval_end']??'');
                $activeSql=$active?'TRUE':'FALSE';
                $q=$p->prepare("INSERT INTO studio_aa_schedules_v2(barber_id,day_of_week,start_time,end_time,break_start,break_end,active) VALUES(?, ?, NULLIF(?::text,'')::TIME, NULLIF(?::text,'')::TIME, NULLIF(?::text,'')::TIME, NULLIF(?::text,'')::TIME, $activeSql)");
                $q->execute([$bid,$day,$start,$end,$bs,$be]);
            }
            $p->commit(); out(['ok'=>true,'barber_id'=>$bid,'schedule'=>getSchedule($p,$bid)]);
        }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }


    if(preg_match('#^/api/appointments/(\d+)$#',$path,$m) && $method==='PATCH'){
        $d=body(); $status=(string)($d['status']??'');
        $map=['pending'=>'pending','confirmado'=>'confirmed','confirmed'=>'confirmed','cancelado'=>'cancelled','cancelled'=>'cancelled','concluido'=>'completed','concluído'=>'completed','completed'=>'completed'];
        $status=$map[strtolower(trim($status))]??'';
        if($status==='')out(['error'=>'Status inválido'],422);
        $p=db(); $id=(int)$m[1]; $oldq=$p->prepare('SELECT status FROM appointments WHERE id=?');$oldq->execute([$id]);$old=$oldq->fetchColumn();
        $q=$p->prepare('UPDATE appointments SET status=? WHERE id=? RETURNING id');$q->execute([$status,$id]);
        if(!$q->fetch())out(['error'=>'Agendamento não encontrado'],404);
        if((string)$old!==$status) notifyAppointmentCustomer($p,$id,$status);
        out(['ok'=>true,'id'=>$id,'status'=>$status]);
    }

    if($path==='/api/admin/dashboard' && $method==='GET'){
        $p=db();
        $date=trim((string)($_GET['date']??''));
        $barberId=(int)($_GET['barber_id']??0);
        $where=[];$params=[];
        if($date!==''){ $where[]='a.appointment_date=?'; $params[]=$date; }
        if($barberId>0){ $where[]='a.barber_id=?'; $params[]=$barberId; }
        $sql="SELECT a.id,a.barber_id,a.service_id,a.customer_id,
                     TO_CHAR(a.appointment_date,'YYYY-MM-DD') AS date,
                     TO_CHAR(a.appointment_time,'HH24:MI') AS time,
                     a.status,c.name AS customer_name,c.phone AS customer_phone,
                     b.name AS barber_name,s.name AS service_name
              FROM appointments a
              JOIN customers c ON c.id=a.customer_id
              JOIN barbers b ON b.id=a.barber_id
              JOIN services s ON s.id=a.service_id";
        if($where) $sql.=" WHERE ".implode(' AND ',$where);
        $sql.=" ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC";
        $q=$p->prepare($sql);$q->execute($params);$appointments=$q->fetchAll();
        $stats=['today'=>(int)$p->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn(),
                'clients'=>(int)$p->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
                'barbers'=>(int)$p->query('SELECT COUNT(*) FROM barbers WHERE active=TRUE')->fetchColumn(),
                'services'=>(int)$p->query('SELECT COUNT(*) FROM services WHERE active=TRUE')->fetchColumn()];
        out(['stats'=>$stats]+$stats+['appointments'=>$appointments]);
    }
    if(($path==='/api/admin/cancel'||$path==='/api/admin/reactivate') && $method==='POST'){$d=body();$id=(int)($d['id']??0);if($id<=0)out(['error'=>'ID inválido'],422);$status=$path==='/api/admin/cancel'?'cancelled':'pending';$p=db();$oldq=$p->prepare('SELECT status FROM appointments WHERE id=?');$oldq->execute([$id]);$old=$oldq->fetchColumn();$q=$p->prepare("UPDATE appointments SET status=? WHERE id=? RETURNING id");$q->execute([$status,$id]);if(!$q->fetch())out(['error'=>'Agendamento não encontrado'],404);if((string)$old!==$status)notifyAppointmentCustomer($p,$id,$status);out(['ok'=>true,'id'=>$id,'status'=>$status]);}
    if($path==='/api/admin/clear-history' && $method==='POST') {
        $p=db();
        $q=$p->query("DELETE FROM appointments WHERE status IN ('cancelled','completed') RETURNING id");
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);
        out(['ok'=>true,'deleted'=>(int)count($ids),'ids'=>array_map('intval',$ids)]);
    }
    if($path==='/api/admin/status' && $method==='POST'){$d=body();$id=(int)($d['id']??0);$status=(string)($d['status']??'');if($id<=0||!in_array($status,['pending','confirmed','cancelled','completed'],true))out(['error'=>'Status inválido'],422);$p=db();$oldq=$p->prepare('SELECT status FROM appointments WHERE id=?');$oldq->execute([$id]);$old=$oldq->fetchColumn();$q=$p->prepare('UPDATE appointments SET status=? WHERE id=? RETURNING id');$q->execute([$status,$id]);if(!$q->fetch())out(['error'=>'Agendamento não encontrado'],404);if((string)$old!==$status)notifyAppointmentCustomer($p,$id,$status);out(['ok'=>true,'id'=>$id,'status'=>$status]);}
    out(['error'=>'Rota não encontrada'],404);
}catch(Throwable $e){out(['error'=>'Erro interno','detail'=>$e->getMessage()],500);}
