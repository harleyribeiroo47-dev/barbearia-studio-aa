<?php2
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Password, X-Barber-Token, X-Access-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
function out($d,int $s=200):void{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function body():array{$d=json_decode(file_get_contents('php://input')?:'{}',true);return is_array($d)?$d:[];}
function envv(string $key):string{
  $v=getenv($key);
  if($v!==false && trim((string)$v)!=='') return trim((string)$v);
  if(isset($_ENV[$key]) && trim((string)$_ENV[$key])!=='') return trim((string)$_ENV[$key]);
  if(isset($_SERVER[$key]) && trim((string)$_SERVER[$key])!=='') return trim((string)$_SERVER[$key]);
  return '';
}
function db():PDO{
  static $p=null;
  if($p instanceof PDO)return $p;
  $url=envv('DATABASE_URL');
  if($url===''){
    foreach(['DATABASE_INTERNAL_URL','DATABASE_PRIVATE_URL','POSTGRES_URL','POSTGRESQL_URL'] as $alias){$url=envv($alias);if($url!=='')break;}
  }
  if($url==='')out(['error'=>'DATABASE_URL não configurada'],500);
  // Render normalmente fornece postgresql://...; fazemos o parsing manualmente
  // para tolerar senhas com caracteres especiais que podem quebrar parse_url().
  $u=parse_url($url);
  $host=''; $port=5432; $db=''; $user=''; $pass='';
  if($u && !empty($u['host'])){
    $host=(string)$u['host']; $port=(int)($u['port']??5432);
    $db=ltrim((string)($u['path']??''),'/');
    $user=rawurldecode((string)($u['user']??'')); $pass=rawurldecode((string)($u['pass']??''));
  } else if(preg_match('/^postgres(?:ql)?:\/\/(.+)@([^\/]+)\/(.+)$/i',$url,$m)){
    $auth=$m[1]; $server=$m[2]; $db=explode('?', $m[3], 2)[0];
    $at=strrpos($auth,'@'); if($at!==false){ $auth=substr($auth,0,$at); }
    $colon=strpos($auth,':');
    if($colon===false){ $user=rawurldecode($auth); }
    else { $user=rawurldecode(substr($auth,0,$colon)); $pass=rawurldecode(substr($auth,$colon+1)); }
    if(strpos($server,':')!==false){ [$host,$ps]=strrpos($server,':')!==false? [substr($server,0,strrpos($server,':')),substr($server,strrpos($server,':')+1)] : [$server,'']; if(ctype_digit($ps))$port=(int)$ps; }
    else $host=$server;
  }
  if($host==='')out(['error'=>'DATABASE_URL inválida'],500);
  if($db==='')out(['error'=>'DATABASE_URL sem nome do banco'],500);
  $dsn='pgsql:host='.$host.';port='.$port.';dbname='.$db;
  $p=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  initDb($p);return $p;
}
function initDb(PDO $p):void{
$p->exec("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname='appointment_status') THEN CREATE TYPE appointment_status AS ENUM ('pending','confirmed','cancelled','completed'); END IF; END $$;");
$p->exec("CREATE TABLE IF NOT EXISTS customers(id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,phone VARCHAR(40) NOT NULL UNIQUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS email VARCHAR(254)");$p->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS photo_data TEXT");$p->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_email ON customers(LOWER(email)) WHERE email IS NOT NULL AND email<>''");
$p->exec("CREATE TABLE IF NOT EXISTS barbers(id BIGSERIAL PRIMARY KEY,name VARCHAR(120) NOT NULL,specialty VARCHAR(180),rating NUMERIC(2,1) NOT NULL DEFAULT 5.0,active BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");$p->exec("ALTER TABLE barbers ADD COLUMN IF NOT EXISTS photo_data TEXT");
$p->exec("CREATE TABLE IF NOT EXISTS services(id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,duration INTEGER NOT NULL,price NUMERIC(10,2) NOT NULL,active BOOLEAN NOT NULL DEFAULT TRUE,sort_order INTEGER NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS appointments(id BIGSERIAL PRIMARY KEY,customer_id BIGINT NOT NULL REFERENCES customers(id),service_id BIGINT NOT NULL REFERENCES services(id),barber_id BIGINT NOT NULL REFERENCES barbers(id),appointment_date DATE NOT NULL,appointment_time TIME NOT NULL,status appointment_status NOT NULL DEFAULT 'pending',created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE INDEX IF NOT EXISTS idx_appointments_date ON appointments(appointment_date)");$p->exec("CREATE INDEX IF NOT EXISTS idx_appointments_barber_date ON appointments(barber_id,appointment_date)");
$p->exec("CREATE TABLE IF NOT EXISTS client_accounts(id BIGSERIAL PRIMARY KEY,customer_id BIGINT NOT NULL UNIQUE REFERENCES customers(id) ON DELETE CASCADE,email VARCHAR(254) NOT NULL UNIQUE,password_hash TEXT NOT NULL,email_verified BOOLEAN NOT NULL DEFAULT FALSE,verification_code_hash TEXT,verification_expires_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS client_sessions(token_hash CHAR(64) PRIMARY KEY,customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),expires_at TIMESTAMPTZ NOT NULL)");
$p->exec("CREATE TABLE IF NOT EXISTS barber_ratings(id BIGSERIAL PRIMARY KEY,appointment_id BIGINT NOT NULL UNIQUE REFERENCES appointments(id) ON DELETE CASCADE,customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),comment TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS push_tokens(id BIGSERIAL PRIMARY KEY,phone VARCHAR(40) NOT NULL UNIQUE,expo_push_token TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS barber_push_tokens(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL UNIQUE REFERENCES barbers(id) ON DELETE CASCADE,expo_push_token TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS barber_accounts(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL UNIQUE REFERENCES barbers(id) ON DELETE CASCADE,password_hash TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$p->exec("CREATE TABLE IF NOT EXISTS barber_sessions(token_hash CHAR(64) PRIMARY KEY,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),expires_at TIMESTAMPTZ NOT NULL)");
$p->exec("CREATE TABLE IF NOT EXISTS studio_aa_schedules_v2(id BIGSERIAL PRIMARY KEY,barber_id BIGINT NOT NULL REFERENCES barbers(id) ON DELETE CASCADE,day_of_week INTEGER NOT NULL,start_time TIME,end_time TIME,break_start TIME,break_end TIME,active BOOLEAN NOT NULL DEFAULT TRUE,UNIQUE(barber_id,day_of_week))");
$p->exec("CREATE TABLE IF NOT EXISTS gallery_cuts(id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,category VARCHAR(80) NOT NULL DEFAULT 'Tendências',description VARCHAR(300),photo_data TEXT NOT NULL,active BOOLEAN NOT NULL DEFAULT TRUE,sort_order INTEGER NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
$services=[['Corte Masculino',30,35,1],['Barba Tradicional',20,25,2],['Combo Corte + Barba',50,55,3],['Sobrancelha',15,15,4],['Pigmentação de Barba',30,40,5]];$q=$p->prepare("INSERT INTO services(name,duration,price,sort_order) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM services WHERE name=? )");foreach($services as $x)$q->execute([$x[0],$x[1],$x[2],$x[3],$x[0]]);
$barbers=[['Lucas','Especialista em degradê',4.9],['Rafael','Barba e bigode',4.8],['Thiago','Corte masculino',4.7],['Matheus','Estilo clássico',4.9]];$q=$p->prepare("INSERT INTO barbers(name,specialty,rating) SELECT ?,?,? WHERE NOT EXISTS(SELECT 1 FROM barbers WHERE name=? )");foreach($barbers as $x)$q->execute([$x[0],$x[1],$x[2],$x[0]]);
$default=password_hash('1234',PASSWORD_DEFAULT);$q=$p->prepare('SELECT id FROM barbers WHERE UPPER(name)=? LIMIT 1');$ins=$p->prepare('INSERT INTO barber_accounts(barber_id,password_hash) SELECT ?,? WHERE NOT EXISTS(SELECT 1 FROM barber_accounts WHERE barber_id=?)');foreach(['ALBERI','ALEX'] as $n){$q->execute([$n]);$id=$q->fetchColumn();if($id!==false)$ins->execute([(int)$id,$default,(int)$id]);}
}
function boolv($v):bool{if(is_bool($v))return $v;$s=strtolower(trim((string)$v));return in_array($s,['1','true','on','yes','sim','aberto','active'],true);}
function timev($v):?string{$s=trim((string)$v);if($s==='')return null;if(preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/',$s)){[$h,$m]=array_map('intval',array_slice(explode(':',$s),0,2));if($h<=23&&$m<=59)return sprintf('%02d:%02d',$h,$m);}return null;}
function datev($v):?string{$s=trim((string)$v);if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)){ $d=DateTime::createFromFormat('Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s?$s:null;}if(preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$s,$m)){ $d=DateTime::createFromFormat('Y-m-d',"{$m[3]}-{$m[2]}-{$m[1]}");return $d?$d->format('Y-m-d'):null;}return null;}
function bearerToken():string{$hs=[];foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','HTTP_X_BARBER_TOKEN','HTTP_X_ACCESS_TOKEN'] as $k)if(!empty($_SERVER[$k]))$hs[]=(string)$_SERVER[$k];foreach($hs as $h){$h=trim($h);if(preg_match('/^Bearer\s+(.+)$/i',$h,$m))return trim($m[1]);if(preg_match('/^[A-Fa-f0-9]{40,}$/',$h))return $h;}return '';}
function clientAuth(PDO $p):array{$t=bearerToken();if($t==='')out(['error'=>'Não autenticado'],401);$q=$p->prepare("SELECT s.customer_id,c.name,c.phone,c.email,c.photo_data,ca.email_verified FROM client_sessions s JOIN customers c ON c.id=s.customer_id JOIN client_accounts ca ON ca.customer_id=c.id WHERE s.token_hash=? AND s.expires_at>NOW()");$q->execute([hash('sha256',$t)]);$r=$q->fetch();if(!$r)out(['error'=>'Sessão expirada ou inválida'],401);if(!(bool)$r['email_verified'])out(['error'=>'E-mail ainda não confirmado'],403);return ['customer_id'=>(int)$r['customer_id'],'name'=>$r['name'],'phone'=>$r['phone'],'email'=>$r['email'],'photo_data'=>$r['photo_data']];}
function barberAuth(PDO $p):array{$t=bearerToken();if($t==='')out(['error'=>'Não autenticado'],401);$q=$p->prepare("SELECT s.barber_id,b.name FROM barber_sessions s JOIN barbers b ON b.id=s.barber_id WHERE s.token_hash=? AND s.expires_at>NOW() AND b.active=TRUE");$q->execute([hash('sha256',$t)]);$r=$q->fetch();if(!$r)out(['error'=>'Sessão expirada ou inválida'],401);if(!in_array(strtoupper($r['name']),['ALBERI','ALEX'],true))out(['error'=>'Acesso não autorizado'],403);return ['barber_id'=>(int)$r['barber_id'],'name'=>strtoupper($r['name'])];}
function adminPasswordMatches(PDO $p,string $password):bool{
  $p->exec("CREATE TABLE IF NOT EXISTS admin_settings (id INTEGER PRIMARY KEY,password_hash TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
  $q=$p->query("SELECT password_hash FROM admin_settings WHERE id=1");
  $hash=$q->fetchColumn();
  if($hash!==false)return password_verify($password,(string)$hash);
  $configured=envv('ADMIN_PASSWORD')?:'studioaa123';
  return hash_equals($configured,$password);
}
function sendExpoPush(string $token,string $title,string $message,array $data=[]):void{if(!preg_match('/^ExponentPushToken\[.+\]$/',$token))return;$payload=json_encode(['to'=>$token,'title'=>$title,'body'=>$message,'sound'=>'default','data'=>$data],JSON_UNESCAPED_UNICODE);$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>$payload,'timeout'=>8,'ignore_errors'=>true]]);@file_get_contents('https://exp.host/--/api/v2/push/send',false,$ctx);}
function notifyAppointmentCustomer(PDO $p,int $id,string $status):void{try{$q=$p->prepare("SELECT a.id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') date,TO_CHAR(a.appointment_time,'HH24:MI') time,c.phone,s.name service_name,b.name barber_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id JOIN barbers b ON b.id=a.barber_id WHERE a.id=?");$q->execute([$id]);$a=$q->fetch();if(!$a)return;$q=$p->prepare('SELECT expo_push_token FROM push_tokens WHERE phone=?');$q->execute([$a['phone']]);$t=(string)($q->fetchColumn()?:'');$labels=['pending'=>'Pendente','confirmed'=>'Confirmado','cancelled'=>'Cancelado','completed'=>'Concluído'];sendExpoPush($t,'Agendamento '.($labels[$status]??$status),"{$a['service_name']} com {$a['barber_name']} — {$a['date']} às {$a['time']}",['appointment_id'=>(int)$id,'status'=>$status]);}catch(Throwable $e){error_log($e->getMessage());}}
function notifyAppointmentBarber(PDO $p,int $id):void{try{$q=$p->prepare("SELECT a.id,a.barber_id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') date,TO_CHAR(a.appointment_time,'HH24:MI') time,c.name customer_name,s.name service_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.id=?");$q->execute([$id]);$a=$q->fetch();if(!$a)return;$q=$p->prepare('SELECT expo_push_token FROM barber_push_tokens WHERE barber_id=?');$q->execute([(int)$a['barber_id']]);$t=(string)($q->fetchColumn()?:'');sendExpoPush($t,'🔔 Novo agendamento',"{$a['customer_name']} — {$a['service_name']} em {$a['date']} às {$a['time']}",['appointment_id'=>(int)$id,'status'=>'pending']);}catch(Throwable $e){error_log($e->getMessage());}}
function smtpRead($fp):array{
  $lines=[]; $code=0;
  while(($line=fgets($fp,8192))!==false){
    $line=rtrim($line,"\r\n"); $lines[]=$line;
    if(preg_match('/^(\d{3})([ -])/', $line,$m)){
      $code=(int)$m[1];
      if($m[2]===' ') break;
    }
  }
  return [$code,implode(" | ",$lines)];
}
function smtpCommand($fp,string $cmd,int $expect=250):array{
  fwrite($fp,$cmd."\r\n");
  [$code,$msg]=smtpRead($fp);
  return [$code,$msg,$code===$expect];
}
function smtpEscapeData(string $data):string{
  $data=str_replace(["\r\n","\r"],"\n",$data);
  $lines=explode("\n",$data);
  foreach($lines as &$line){ if(isset($line[0]) && $line[0]==='.') $line='.'. $line; }
  return implode("\r\n",$lines);
}
function sendVerificationEmail(string $email,string $name,string $code):bool{
  // V27: Brevo Transactional Email API over HTTPS.
  // This avoids SMTP egress restrictions on Render Free.
  $apiKey=trim(envv('BREVO_API_KEY'));
  $from=trim(envv('BREVO_FROM'));
  if($apiKey===''||$from===''){
    error_log('Studio A.A Brevo: BREVO_API_KEY e BREVO_FROM precisam estar configurados');
    return false;
  }
  $html="<div style='font-family:Arial;max-width:560px;margin:auto'><h2 style='color:#D9A928'>BARBEARIA STUDIO A.A</h2><p>Olá, ".htmlspecialchars($name,ENT_QUOTES,'UTF-8')."!</p><p>Seu código de confirmação é:</p><div style='font-size:32px;font-weight:bold;letter-spacing:8px;padding:16px;background:#111;color:#D9A928;text-align:center;border-radius:10px'>$code</div><p>O código expira em 15 minutos.</p></div>";
  $payload=[
    'sender'=>['name'=>'Barbearia Studio A.A','email'=>$from],
    'to'=>[['email'=>$email,'name'=>$name]],
    'subject'=>'Confirme seu e-mail — Barbearia Studio A.A',
    'htmlContent'=>$html,
    'textContent'=>"Barbearia Studio A.A\n\nOlá, {$name}!\n\nSeu código de confirmação é: {$code}\n\nO código expira em 15 minutos."
  ];
  $ch=curl_init('https://api.brevo.com/v3/smtp/email');
  if($ch===false){ error_log('Studio A.A Brevo: curl_init falhou'); return false; }
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>30,
    CURLOPT_HTTPHEADER=>[
      'accept: application/json',
      'api-key: '.$apiKey,
      'content-type: application/json'
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
  ]);
  $body=curl_exec($ch);
  $errno=curl_errno($ch);
  $err=curl_error($ch);
  $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($errno!==0){
    error_log("Studio A.A Brevo: cURL {$errno} {$err}");
    return false;
  }
  if($http<200||$http>=300){
    // Do not log the API key or sensitive credentials.
    error_log("Studio A.A Brevo: HTTP {$http} resposta=".substr((string)$body,0,1000));
    return false;
  }
  $decoded=json_decode((string)$body,true);
  if(!is_array($decoded)||empty($decoded['messageId'])){
    error_log("Studio A.A Brevo: resposta sem messageId HTTP {$http}");
    return false;
  }
  error_log('Studio A.A Brevo: e-mail aceito, messageId='.$decoded['messageId']);
  return true;
}
function scheduleRows(PDO $p,int $bid):array{$q=$p->prepare('SELECT day_of_week,active,start_time,end_time,break_start,break_end FROM studio_aa_schedules_v2 WHERE barber_id=? ORDER BY day_of_week');$q->execute([$bid]);return $q->fetchAll();}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD'];
try{if(str_starts_with($path,'/api/admin/')&&$path!=='/api/admin/login'&&$path!=='/api/admin/change-password'){
$adminPassword=(string)($_SERVER['HTTP_X_ADMIN_PASSWORD']??'');
if($adminPassword===''||!adminPasswordMatches(db(),$adminPassword))out(['error'=>'Não autorizado'],401);
}
if($path==='/api/health'&&$method==='GET')out(['ok'=>true,'service'=>'Studio A.A API','version'=>'client-email-ratings-photos-gallery-v25-gmail','database_configured'=>envv('DATABASE_URL')!=='' || envv('DATABASE_INTERNAL_URL')!=='' || envv('DATABASE_PRIVATE_URL')!=='' || envv('POSTGRES_URL')!=='' || envv('POSTGRESQL_URL')!=='']);
if($path==='/api/services'&&$method==='GET')out(db()->query("SELECT id,name,duration,price FROM services WHERE active=TRUE ORDER BY sort_order,id")->fetchAll());
if($path==='/api/barbers'&&$method==='GET')out(db()->query("SELECT id,name,specialty,rating,photo_data FROM barbers WHERE active=TRUE ORDER BY name")->fetchAll());
if($path==='/api/gallery'&&$method==='GET')out(db()->query("SELECT id,name,category,description,photo_data FROM gallery_cuts WHERE active=TRUE ORDER BY sort_order,id")->fetchAll());
if($path==='/api/appointments'&&$method==='POST'){
$d=body();$service=(int)($d['service_id']??$d['serviceId']??0);$barber=(int)($d['barber_id']??$d['barberId']??0);$name=trim((string)($d['customer_name']??$d['name']??''));$phone=trim((string)($d['customer_phone']??$d['phone']??''));$date=datev($d['date']??$d['appointment_date']??'');$time=timev($d['time']??$d['appointment_time']??'');if($service<=0||$barber<=0||$date===null||$time===null)out(['error'=>'Dados do agendamento inválidos'],422);$p=db();$client=null;if(bearerToken()!==''){try{$client=clientAuth($p);}catch(Throwable $e){}}if($client){$name=$client['name'];$phone=$client['phone'];}if($name===''||$phone==='')out(['error'=>'Nome e telefone são obrigatórios'],422);$p->beginTransaction();try{$lock=(int)sprintf('%u',crc32("$barber|$date|$time"));$p->query("SELECT pg_advisory_xact_lock($lock)");$q=$p->prepare("SELECT COUNT(*) FROM appointments WHERE barber_id=? AND appointment_date=? AND appointment_time=? AND status IN ('pending','confirmed')");$q->execute([$barber,$date,$time]);if((int)$q->fetchColumn()>0){$p->rollBack();out(['error'=>'Horário já ocupado'],409);}$q=$p->prepare('SELECT id FROM customers WHERE phone=?');$q->execute([$phone]);$c=$q->fetch();if($c){$cid=(int)$c['id'];$p->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$name,$cid]);}else{$q=$p->prepare('INSERT INTO customers(name,phone) VALUES(?,?) RETURNING id');$q->execute([$name,$phone]);$cid=(int)$q->fetchColumn();}$q=$p->prepare("INSERT INTO appointments(customer_id,service_id,barber_id,appointment_date,appointment_time,status) VALUES(?,?,?,?,?,'pending') RETURNING id");$q->execute([$cid,$service,$barber,$date,$time]);$id=(int)$q->fetchColumn();$p->commit();notifyAppointmentBarber($p,$id);out(['ok'=>true,'id'=>$id,'status'=>'pending'],201);}catch(Throwable $e){if($p->inTransaction())$p->rollBack();out(['error'=>'Não foi possível criar o agendamento'],500);}}
if($path==='/api/push/register'&&$method==='POST'){$d=body();$phone=trim((string)($d['phone']??''));$t=trim((string)($d['expo_push_token']??$d['expoPushToken']??''));if($phone===''||$t==='')out(['error'=>'phone e expo_push_token são obrigatórios'],422);$p=db();$q=$p->prepare("INSERT INTO push_tokens(phone,expo_push_token,updated_at) VALUES(?,?,NOW()) ON CONFLICT(phone) DO UPDATE SET expo_push_token=EXCLUDED.expo_push_token,updated_at=NOW()");$q->execute([$phone,$t]);out(['ok'=>true]);}
if($path==='/api/barber/push/register'&&$method==='POST'){$p=db();$a=barberAuth($p);$d=body();$bid=(int)($d['barber_id']??0);$t=trim((string)($d['expo_push_token']??$d['expoPushToken']??''));if($bid!==$a['barber_id'])out(['error'=>'Token não autorizado para este barbeiro'],403);if($t==='')out(['error'=>'Token Expo inválido'],422);$q=$p->prepare("INSERT INTO barber_push_tokens(barber_id,expo_push_token,updated_at) VALUES(?,?,NOW()) ON CONFLICT(barber_id) DO UPDATE SET expo_push_token=EXCLUDED.expo_push_token,updated_at=NOW()");$q->execute([$bid,$t]);out(['ok'=>true]);}
if($path==='/api/client/register'&&$method==='POST'){
$d=body();
$name=trim((string)($d['name']??''));
$phone=trim((string)($d['phone']??''));
$email=strtolower(trim((string)($d['email']??'')));
$pw=(string)($d['password']??'');
if($name===''||$phone===''||$email===''||$pw==='')out(['error'=>'Nome, telefone, e-mail e senha são obrigatórios'],422);
if(!filter_var($email,FILTER_VALIDATE_EMAIL))out(['error'=>'E-mail inválido'],422);
if(strlen($pw)<6)out(['error'=>'A senha deve ter pelo menos 6 caracteres'],422);
$p=db();

/* V24: se o e-mail já existe, só bloqueia quando a conta já foi confirmada.
   Se ainda não foi confirmada, gera um novo código e reenvia a confirmação. */
$q=$p->prepare('SELECT id,customer_id,email_verified FROM client_accounts WHERE LOWER(email)=LOWER(?)');
$q->execute([$email]);
$existing=$q->fetch();
if($existing){
  if((bool)$existing['email_verified'])out(['error'=>'Este e-mail já está cadastrado'],409);

  $cid=(int)$existing['customer_id'];
  $q=$p->prepare('SELECT name,phone FROM customers WHERE id=?');
  $q->execute([$cid]);
  $customer=$q->fetch();
  $emailName=$customer?(string)$customer['name']:$name;

  /* Atualiza a senha digitada no novo cadastro e substitui o código anterior. */
  $code=(string)random_int(100000,999999);
  $q=$p->prepare("UPDATE client_accounts SET password_hash=?,verification_code_hash=?,verification_expires_at=NOW()+INTERVAL '15 minutes',updated_at=NOW() WHERE id=?");
  $q->execute([password_hash($pw,PASSWORD_DEFAULT),password_hash($code,PASSWORD_DEFAULT),(int)$existing['id']]);

  if(!sendVerificationEmail($email,$emailName,$code)){
    out(['error'=>'Não foi possível enviar o e-mail de confirmação. Verifique SMTP_USER, SMTP_PASS e EMAIL_FROM no Render.'],503);
  }
  out(['ok'=>true,'message'=>'Sua conta já existe, mas ainda não foi confirmada. Um novo código foi enviado para seu e-mail.','code'=>'EMAIL_CONFIRMATION_RESENT'],200);
}

$q=$p->prepare('SELECT id FROM customers WHERE phone=?');
$q->execute([$phone]);
$c=$q->fetch();
if($c){
  $cid=(int)$c['id'];
  $q=$p->prepare('SELECT id FROM client_accounts WHERE customer_id=?');
  $q->execute([$cid]);
  if($q->fetch())out(['error'=>'Este telefone já possui uma conta'],409);
  $p->prepare('UPDATE customers SET name=?,email=? WHERE id=?')->execute([$name,$email,$cid]);
}else{
  $q=$p->prepare('INSERT INTO customers(name,phone,email) VALUES(?,?,?) RETURNING id');
  $q->execute([$name,$phone,$email]);
  $cid=(int)$q->fetchColumn();
}
$code=(string)random_int(100000,999999);
$q=$p->prepare("INSERT INTO client_accounts(customer_id,email,password_hash,verification_code_hash,verification_expires_at) VALUES(?,?,?,?,NOW()+INTERVAL '15 minutes')");
$q->execute([$cid,$email,password_hash($pw,PASSWORD_DEFAULT),password_hash($code,PASSWORD_DEFAULT)]);
if(!sendVerificationEmail($email,$name,$code)){
  $p->prepare('DELETE FROM client_accounts WHERE customer_id=?')->execute([$cid]);
  out(['error'=>'Não foi possível enviar o e-mail de confirmação. Verifique SMTP_USER, SMTP_PASS e EMAIL_FROM no Render.'],503);
}
out(['ok'=>true,'message'=>'Código enviado para seu e-mail'],201);
}
if($path==='/api/client/verify-email'&&$method==='POST'){
  $d=body();
  // V28: aceita os nomes usados por versões diferentes do app e preserva o mesmo código enviado.
  $email=strtolower(trim((string)($d['email']??$d['e-mail']??'')));
  $code=trim((string)($d['code']??$d['verification_code']??$d['verificationCode']??$d['codigo']??''));
  $p=db();
  if($email===''||$code==='')out(['error'=>'E-mail e código são obrigatórios','code'=>'VERIFICATION_DATA_MISSING'],422);
  $q=$p->prepare('SELECT ca.id,ca.customer_id,ca.verification_code_hash,ca.verification_expires_at,ca.email_verified,c.name,c.phone,c.email,c.photo_data FROM client_accounts ca JOIN customers c ON c.id=ca.customer_id WHERE LOWER(ca.email)=LOWER(?)');
  $q->execute([$email]);
  $a=$q->fetch();
  if(!$a)out(['error'=>'Conta não encontrada','code'=>'ACCOUNT_NOT_FOUND'],404);
  if((bool)$a['email_verified'])out(['ok'=>true,'verified'=>true,'message'=>'E-mail já confirmado']);
  $expires=$a['verification_expires_at']?strtotime((string)$a['verification_expires_at']):0;
  if((string)$a['verification_code_hash']===''||!password_verify($code,(string)$a['verification_code_hash'])||$expires<time())out(['error'=>'Código inválido ou expirado','code'=>'INVALID_VERIFICATION_CODE'],401);
  $p->beginTransaction();
  try{
    $p->prepare('UPDATE client_accounts SET email_verified=TRUE,verification_code_hash=NULL,verification_expires_at=NULL,updated_at=NOW() WHERE id=?')->execute([(int)$a['id']]);
    // Já autenticamos o cliente aqui: o app não precisa chamar cadastro novamente nem pedir outro código.
    $t=bin2hex(random_bytes(32));
    $p->prepare("INSERT INTO client_sessions(token_hash,customer_id,expires_at) VALUES(?,?,NOW()+INTERVAL '30 days')")->execute([hash('sha256',$t),(int)$a['customer_id']]);
    $p->commit();
    out(['ok'=>true,'verified'=>true,'authenticated'=>true,'token'=>$t,'client'=>['id'=>(int)$a['customer_id'],'name'=>$a['name'],'phone'=>$a['phone'],'email'=>$a['email'],'photo_data'=>$a['photo_data']]]);
  }catch(Throwable $e){
    if($p->inTransaction())$p->rollBack();
    error_log('Studio A.A V28 verify-email: '.$e->getMessage());
    out(['error'=>'Não foi possível concluir a confirmação'],500);
  }
}
if($path==='/api/client/resend-verification'&&$method==='POST'){$d=body();$email=strtolower(trim((string)($d['email']??'')));$p=db();$q=$p->prepare('SELECT id,customer_id,email_verified FROM client_accounts WHERE LOWER(email)=LOWER(?)');$q->execute([$email]);$a=$q->fetch();if(!$a)out(['error'=>'Conta não encontrada'],404);if((bool)$a['email_verified'])out(['ok'=>true,'message'=>'E-mail já confirmado']);$q=$p->prepare('SELECT name FROM customers WHERE id=?');$q->execute([(int)$a['customer_id']]);$name=(string)$q->fetchColumn();$code=(string)random_int(100000,999999);$p->prepare("UPDATE client_accounts SET verification_code_hash=?,verification_expires_at=NOW()+INTERVAL '15 minutes',updated_at=NOW() WHERE id=?")->execute([password_hash($code,PASSWORD_DEFAULT),(int)$a['id']]);if(!sendVerificationEmail($email,$name,$code))out(['error'=>'Não foi possível enviar o e-mail'],503);out(['ok'=>true]);}
if($path==='/api/client/login'&&$method==='POST'){$d=body();$email=strtolower(trim((string)($d['email']??'')));$pw=(string)($d['password']??'');$p=db();$q=$p->prepare('SELECT ca.customer_id,ca.password_hash,ca.email_verified,c.name,c.phone,c.email,c.photo_data FROM client_accounts ca JOIN customers c ON c.id=ca.customer_id WHERE LOWER(ca.email)=LOWER(?)');$q->execute([$email]);$a=$q->fetch();if(!$a||!password_verify($pw,(string)$a['password_hash']))out(['error'=>'E-mail ou senha incorretos'],401);if(!(bool)$a['email_verified'])out(['error'=>'Confirme seu e-mail antes de entrar','code'=>'EMAIL_NOT_VERIFIED'],403);$t=bin2hex(random_bytes(32));$p->prepare("INSERT INTO client_sessions(token_hash,customer_id,expires_at) VALUES(?,?,NOW()+INTERVAL '30 days')")->execute([hash('sha256',$t),(int)$a['customer_id']]);out(['ok'=>true,'token'=>$t,'client'=>['id'=>(int)$a['customer_id'],'name'=>$a['name'],'phone'=>$a['phone'],'email'=>$a['email'],'photo_data'=>$a['photo_data']]]);}
if($path==='/api/client/logout'&&$method==='POST'){$p=db();$t=bearerToken();if($t!=='')$p->prepare('DELETE FROM client_sessions WHERE token_hash=?')->execute([hash('sha256',$t)]);out(['ok'=>true]);}
if($path==='/api/client/me'&&$method==='GET')out(['ok'=>true,'client'=>clientAuth(db())]);
if($path==='/api/client/profile'&&$method==='PATCH'){$p=db();$a=clientAuth($p);$d=body();$name=trim((string)($d['name']??$a['name']));$phone=trim((string)($d['phone']??$a['phone']));$photo=(string)($d['photo_data']??$a['photo_data']??'');if($name==='')out(['error'=>'Nome obrigatório'],422);if(strlen($photo)>2500000)out(['error'=>'Foto muito grande'],422);$q=$p->prepare('SELECT id FROM customers WHERE phone=? AND id<>?');$q->execute([$phone,$a['customer_id']]);if($q->fetch())out(['error'=>'Este telefone já está em uso'],409);$p->prepare('UPDATE customers SET name=?,phone=?,photo_data=? WHERE id=?')->execute([$name,$phone,$photo,$a['customer_id']]);out(['ok'=>true,'client'=>['id'=>$a['customer_id'],'name'=>$name,'phone'=>$phone,'email'=>$a['email'],'photo_data'=>$photo]]);}
if($path==='/api/client/appointments'&&$method==='GET'){$p=db();$a=clientAuth($p);$q=$p->prepare("SELECT a.id,a.barber_id,a.service_id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') date,TO_CHAR(a.appointment_time,'HH24:MI') time,a.status,b.name barber_name,b.photo_data barber_photo,s.name service_name,s.duration,s.price,EXISTS(SELECT 1 FROM barber_ratings br WHERE br.appointment_id=a.id) rated FROM appointments a JOIN barbers b ON b.id=a.barber_id JOIN services s ON s.id=a.service_id WHERE a.customer_id=? ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC");$q->execute([$a['customer_id']]);out($q->fetchAll());}
if($path==='/api/client/rating'&&$method==='POST'){$p=db();$a=clientAuth($p);$d=body();$id=(int)($d['appointment_id']??0);$rating=(int)($d['rating']??0);$comment=trim((string)($d['comment']??''));if($rating<1||$rating>5)out(['error'=>'Escolha de 1 a 5 estrelas'],422);$q=$p->prepare('SELECT barber_id,status FROM appointments WHERE id=? AND customer_id=?');$q->execute([$id,$a['customer_id']]);$ap=$q->fetch();if(!$ap)out(['error'=>'Agendamento não encontrado'],404);if($ap['status']!=='completed')out(['error'=>'A avaliação fica disponível após o atendimento ser concluído'],422);$q=$p->prepare('SELECT id FROM barber_ratings WHERE appointment_id=?');$q->execute([$id]);if($q->fetch())out(['error'=>'Este atendimento já foi avaliado'],409);$p->prepare('INSERT INTO barber_ratings(appointment_id,customer_id,barber_id,rating,comment) VALUES(?,?,?,?,?)')->execute([$id,$a['customer_id'],(int)$ap['barber_id'],$rating,$comment]);$q=$p->prepare('SELECT ROUND(AVG(rating)::numeric,1) FROM barber_ratings WHERE barber_id=?');$q->execute([(int)$ap['barber_id']]);$avg=(float)($q->fetchColumn()?:5);$p->prepare('UPDATE barbers SET rating=? WHERE id=?')->execute([$avg,(int)$ap['barber_id']]);out(['ok'=>true,'barber_rating'=>$avg]);}
if($path==='/api/availability'&&$method==='GET'){$bid=(int)($_GET['barber_id']??0);$date=trim((string)($_GET['date']??''));if($bid<=0||$date==='')out(['error'=>'barber_id e date são obrigatórios'],422);$p=db();$q=$p->prepare("SELECT a.id,TO_CHAR(a.appointment_time,'HH24:MI') time,a.status FROM appointments a WHERE a.barber_id=? AND a.appointment_date=? AND a.status IN ('pending','confirmed') ORDER BY a.appointment_time");$q->execute([$bid,$date]);out(['appointments'=>$q->fetchAll(),'available_times'=>[]]);}
if($path==='/api/schedules'&&$method==='GET'){$bid=(int)($_GET['barber_id']??0);out(array_map(fn($r)=>['day_of_week'=>(int)$r['day_of_week'],'active'=>(bool)$r['active'],'start_time'=>$r['start_time'],'end_time'=>$r['end_time'],'break_start'=>$r['break_start'],'break_end'=>$r['break_end']],scheduleRows(db(),$bid)));}
if($path==='/api/barber/login'&&$method==='POST'){$d=body();$bid=(int)($d['barber_id']??0);$pw=(string)($d['password']??'');$p=db();$q=$p->prepare("SELECT b.id,b.name,ba.password_hash FROM barbers b JOIN barber_accounts ba ON ba.barber_id=b.id WHERE b.id=? AND b.active=TRUE");$q->execute([$bid]);$a=$q->fetch();if(!$a||!in_array(strtoupper($a['name']),['ALBERI','ALEX'],true)||!password_verify($pw,$a['password_hash']))out(['error'=>'Barbeiro ou senha incorretos'],401);$t=bin2hex(random_bytes(32));$p->prepare("INSERT INTO barber_sessions(token_hash,barber_id,expires_at) VALUES(?,?,NOW()+INTERVAL '30 days')")->execute([hash('sha256',$t),$bid]);out(['ok'=>true,'token'=>$t,'barber'=>['id'=>(int)$a['id'],'name'=>$a['name']]]);}
if($path==='/api/barber/change-password'&&$method==='POST'){$p=db();$a=barberAuth($p);$d=body();$cur=(string)($d['current_password']??'');$new=(string)($d['new_password']??'');$conf=(string)($d['confirm_password']??'');if(strlen($new)<4||$new!==$conf)out(['error'=>'Nova senha inválida ou confirmação diferente'],422);$q=$p->prepare('SELECT password_hash FROM barber_accounts WHERE barber_id=?');$q->execute([$a['barber_id']]);$h=(string)$q->fetchColumn();if(!password_verify($cur,$h))out(['error'=>'Senha atual incorreta'],401);if(password_verify($new,$h))out(['error'=>'A nova senha deve ser diferente da atual'],422);$p->prepare('UPDATE barber_accounts SET password_hash=?,updated_at=NOW() WHERE barber_id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$a['barber_id']]);out(['ok'=>true]);}
if($path==='/api/barber/logout'&&$method==='POST'){$p=db();$t=bearerToken();if($t!=='')$p->prepare('DELETE FROM barber_sessions WHERE token_hash=?')->execute([hash('sha256',$t)]);out(['ok'=>true]);}
if($path==='/api/barber/dashboard'&&$method==='GET'){$p=db();$a=barberAuth($p);$date=datev($_GET['date']??'');$q=$p->prepare("SELECT a.id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') date,TO_CHAR(a.appointment_time,'HH24:MI') time,a.status,c.name customer_name,c.phone customer_phone,s.name service_name,s.price FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.barber_id=? AND a.appointment_date=? ORDER BY a.appointment_time");$q->execute([$a['barber_id'],$date]);out(['appointments'=>$q->fetchAll(),'barber'=>$a]);}
if($path==='/api/barber/status'&&$method==='POST'){$p=db();$a=barberAuth($p);$d=body();$id=(int)($d['id']??0);$st=(string)($d['status']??'');if(!in_array($st,['confirmed','cancelled','completed'],true))out(['error'=>'Status inválido'],422);$q=$p->prepare('SELECT status FROM appointments WHERE id=? AND barber_id=?');$q->execute([$id,$a['barber_id']]);$old=$q->fetchColumn();if($old===false)out(['error'=>'Agendamento não encontrado'],404);$p->prepare('UPDATE appointments SET status=? WHERE id=?')->execute([$st,$id]);if($old!==$st)notifyAppointmentCustomer($p,$id,$st);out(['ok'=>true,'status'=>$st]);}
if($path==='/api/admin/login'&&$method==='POST'){
  $d=body();
  $password=(string)($d['password']??'');
  $p=db();
  $p->exec("CREATE TABLE IF NOT EXISTS admin_settings (id INTEGER PRIMARY KEY,password_hash TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
  $q=$p->query("SELECT password_hash FROM admin_settings WHERE id=1");
  $hash=$q->fetchColumn();
  if($hash!==false){
    if(!password_verify($password,(string)$hash))out(['error'=>'Senha incorreta'],401);
  }else{
    $configured=envv('ADMIN_PASSWORD')?:'studioaa123';
    if(!hash_equals($configured,$password))out(['error'=>'Senha incorreta'],401);
  }
  out(['ok'=>true]);
}
if($path==='/api/admin/dashboard'&&$method==='GET'){$p=db();$date=trim((string)($_GET['date']??''));$bid=(int)($_GET['barber_id']??0);$w=[];$pa=[];if($date!==''){$w[]='a.appointment_date=?';$pa[]=$date;}if($bid>0){$w[]='a.barber_id=?';$pa[]=$bid;}$sql="SELECT a.id,a.barber_id,TO_CHAR(a.appointment_date,'YYYY-MM-DD') date,TO_CHAR(a.appointment_time,'HH24:MI') time,a.status,c.name customer_name,c.phone customer_phone,b.name barber_name,s.name service_name FROM appointments a JOIN customers c ON c.id=a.customer_id JOIN barbers b ON b.id=a.barber_id JOIN services s ON s.id=a.service_id";if($w)$sql.=' WHERE '.implode(' AND ',$w);$sql.=' ORDER BY a.appointment_date DESC,a.appointment_time DESC,a.id DESC';$q=$p->prepare($sql);$q->execute($pa);$apps=$q->fetchAll();out(['stats'=>['today'=>(int)$p->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURRENT_DATE AND status IN ('pending','confirmed')")->fetchColumn(),'clients'=>(int)$p->query('SELECT COUNT(*) FROM customers')->fetchColumn(),'barbers'=>(int)$p->query('SELECT COUNT(*) FROM barbers WHERE active=TRUE')->fetchColumn(),'services'=>(int)$p->query('SELECT COUNT(*) FROM services WHERE active=TRUE')->fetchColumn()],'appointments'=>$apps]);}
if($path==='/api/admin/barbers'&&$method==='GET')out(db()->query('SELECT id,name,specialty,rating,active,photo_data FROM barbers ORDER BY name,id')->fetchAll());
if($path==='/api/admin/barbers'&&in_array($method,['POST','PUT'],true)){$d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$photo=(string)($d['photo_data']??'');if($name===''||strlen($photo)>2500000)out(['error'=>'Dados ou foto inválidos'],422);$p=db();$active=boolv($d['active']??true);$as=$active?'TRUE':'FALSE';if($id){$q=$p->prepare("UPDATE barbers SET name=?,specialty=?,rating=?,active=$as,photo_data=? WHERE id=? RETURNING id");$q->execute([$name,(string)($d['specialty']??''),(float)($d['rating']??5),$photo,$id]);}else{$q=$p->prepare("INSERT INTO barbers(name,specialty,rating,active,photo_data) VALUES(?,?,?,$as,?) RETURNING id");$q->execute([$name,(string)($d['specialty']??''),(float)($d['rating']??5),$photo]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);}
if($path==='/api/admin/services'&&$method==='GET')out(db()->query('SELECT id,name,duration,price,active,sort_order FROM services ORDER BY sort_order,id')->fetchAll());
if($path==='/api/admin/services'&&in_array($method,['POST','PUT'],true)){$d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$duration=(int)($d['duration']??0);$price=(float)($d['price']??0);$active=boolv($d['active']??true);$p=db();$as=$active?'TRUE':'FALSE';if($id){$q=$p->prepare("UPDATE services SET name=?,duration=?,price=?,active=$as,sort_order=? WHERE id=?");$q->execute([$name,$duration,$price,(int)($d['sort_order']??0),$id]);}else{$q=$p->prepare("INSERT INTO services(name,duration,price,active,sort_order) VALUES(?,?,?,$as,?) RETURNING id");$q->execute([$name,$duration,$price,(int)($d['sort_order']??0)]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);}
if($path==='/api/admin/schedule'&&$method==='GET')out(['schedule'=>scheduleRows(db(),(int)$_GET['barber_id'])]);
if($path==='/api/admin/schedule'&&in_array($method,['POST','PUT'],true)){
    $d=body();

    $bid=(int)($d['barber_id']??0);
    $rows=$d['schedule']??[];

    if($bid<=0){
        out(['error'=>'Barbeiro inválido'],422);
    }

    if(!is_array($rows)){
        out(['error'=>'Formato de horários inválido'],422);
    }

    /*
     * Normaliza os dias recebidos e elimina duplicidades.
     * Aceita tanto day_of_week quanto weekday para manter
     * compatibilidade com versões anteriores do painel.
     */
    $normalized=[];

    foreach($rows as $r){

        if(!is_array($r)){
            continue;
        }

        $day=$r['day_of_week']??$r['weekday']??null;

        if($day===null||$day===''){
            continue;
        }

        $day=(int)$day;

        if($day<0||$day>6){
            continue;
        }

        $normalized[$day]=[
            'day_of_week'=>$day,
            'active'=>boolv($r['active']??$r['open']??true),
            'start_time'=>timev($r['start_time']??$r['start']??''),
            'end_time'=>timev($r['end_time']??$r['end']??''),
            'break_start'=>timev($r['break_start']??$r['bs']??''),
            'break_end'=>timev($r['break_end']??$r['be']??'')
        ];
    }

    $p=db();

    try{
        $p->beginTransaction();

        /*
         * Remove os horários antigos desse barbeiro.
         * Depois gravamos somente um registro por dia.
         */
        $p->prepare(
            'DELETE FROM studio_aa_schedules_v2 WHERE barber_id=?'
        )->execute([$bid]);

        $q=$p->prepare(
            'INSERT INTO studio_aa_schedules_v2
            (barber_id,day_of_week,start_time,end_time,break_start,break_end,active)
            VALUES(?,?,?,?,?,?,?)'
        );

        foreach($normalized as $r){

            $q->execute([
                $bid,
                $r['day_of_week'],
                $r['start_time'],
                $r['end_time'],
                $r['break_start'],
                $r['break_end'],
                $r['active']
            ]);
        }

        $p->commit();

        out([
            'ok'=>true,
            'message'=>'Horários salvos com sucesso',
            'barber_id'=>$bid,
            'days_saved'=>count($normalized)
        ]);

    }catch(Throwable $e){

        if($p->inTransaction()){
            $p->rollBack();
        }

        error_log(
            'Studio A.A schedule save: '.$e->getMessage()
        );

        out([
            'error'=>'Não foi possível salvar os horários',
            'detail'=>$e->getMessage()
        ],500);
    }
}
if($path==='/api/admin/gallery'&&$method==='GET')out(db()->query('SELECT id,name,category,description,photo_data,active,sort_order FROM gallery_cuts ORDER BY sort_order,id')->fetchAll());
if($path==='/api/admin/gallery'&&in_array($method,['POST','PUT'],true)){$d=body();$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$cat=trim((string)($d['category']??'Tendências'));$desc=trim((string)($d['description']??''));$photo=(string)($d['photo_data']??'');if($name===''||$photo===''||strlen($photo)>2500000)out(['error'=>'Nome e foto são obrigatórios (até 2,5 MB)'],422);$p=db();$active=boolv($d['active']??true);$as=$active?'TRUE':'FALSE';if($id){$q=$p->prepare("UPDATE gallery_cuts SET name=?,category=?,description=?,photo_data=?,active=$as,sort_order=? WHERE id=?");$q->execute([$name,$cat,$desc,$photo,(int)($d['sort_order']??0),$id]);}else{$q=$p->prepare("INSERT INTO gallery_cuts(name,category,description,photo_data,active,sort_order) VALUES(?,?,?,?,${as},?) RETURNING id");$q->execute([$name,$cat,$desc,$photo,(int)($d['sort_order']??0)]);$id=(int)$q->fetchColumn();}out(['ok'=>true,'id'=>$id]);}
if($path==='/api/admin/clear-history'&&$method==='POST'){ $q=db()->query("DELETE FROM appointments WHERE status IN ('cancelled','completed') RETURNING id");$ids=$q->fetchAll(PDO::FETCH_COLUMN);out(['ok'=>true,'deleted'=>count($ids),'ids'=>array_map('intval',$ids)]);}
if($path==='/api/admin/status'&&$method==='POST'){$d=body();$id=(int)($d['id']??0);$st=(string)($d['status']??'');$p=db();$q=$p->prepare('SELECT status FROM appointments WHERE id=?');$q->execute([$id]);$old=$q->fetchColumn();if($old===false)out(['error'=>'Agendamento não encontrado'],404);$p->prepare('UPDATE appointments SET status=? WHERE id=?')->execute([$st,$id]);if($old!==$st)notifyAppointmentCustomer($p,$id,$st);out(['ok'=>true]);}
if($path==='/api/admin/change-password'&&$method==='POST'){
  $d=body();
  $current=(string)($d['current_password']??'');
  $new=(string)($d['new_password']??'');
  $confirm=(string)($d['confirm_password']??'');
  if(strlen($new)<8||$new!==$confirm)out(['error'=>'A nova senha deve ter pelo menos 8 caracteres e a confirmação deve ser igual'],422);
  $p=db();
  $p->exec("CREATE TABLE IF NOT EXISTS admin_settings (id INTEGER PRIMARY KEY,password_hash TEXT NOT NULL,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
  $q=$p->query("SELECT password_hash FROM admin_settings WHERE id=1");
  $hash=$q->fetchColumn();
  if($hash!==false){
    if(!password_verify($current,(string)$hash))out(['error'=>'Senha atual incorreta'],401);
  }else{
    $configured=envv('ADMIN_PASSWORD')?:'studioaa123';
    if(!hash_equals($configured,$current))out(['error'=>'Senha atual incorreta'],401);
  }
  $p->prepare("INSERT INTO admin_settings(id,password_hash,updated_at) VALUES(1,?,NOW()) ON CONFLICT(id) DO UPDATE SET password_hash=EXCLUDED.password_hash,updated_at=NOW()")->execute([password_hash($new,PASSWORD_DEFAULT)]);
  out(['ok'=>true,'message'=>'Senha administrativa alterada com sucesso']);
}
  out(['error'=>'Rota não encontrada'],404);
}catch(Throwable $e){error_log($e->getMessage());out(['error'=>'Erro interno','detail'=>$e->getMessage()],500);}
