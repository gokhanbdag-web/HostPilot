<?php
declare(strict_types=1);

/**
 * Disposable-database HTTP integration tests for real HostPilot routes.
 * Refuses to run unless the database is exactly hostpilot_ci.
 */
$root = dirname(__DIR__);
$host = getenv('HOSTPILOT_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('HOSTPILOT_DB_PORT') ?: 3306);
$name = getenv('HOSTPILOT_DB_NAME') ?: '';
$dbUser = getenv('HOSTPILOT_DB_USER') ?: '';
$dbPass = getenv('HOSTPILOT_DB_PASSWORD') ?: '';
if ($name !== 'hostpilot_ci' || $dbUser === '') throw new RuntimeException('Refusing HTTP security tests outside hostpilot_ci.');
if (!extension_loaded('curl')) throw new RuntimeException('The cURL extension is required.');

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
$now = gmdate('Y-m-d H:i:s');
// The migration harness starts from a deliberately minimal legacy users table.
// Restore the baseline columns/tables required by the actual HTTP application.
$hasLastLogin = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='last_login_at'")->fetchColumn();
if ((int)$hasLastLogin === 0) $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL');
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    setting_group VARCHAR(80) NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value LONGTEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_group, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('demo','seed_version','1',0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
$password = 'HostPilot-HTTP-Test-Password-2026!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$insertUser = $pdo->prepare("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:name,:email,:username,:hash,:role,'active',:created,:updated)");
$makeUser = static function (string $display, string $email, string $username, string $role) use ($insertUser, $pdo, $hash, $now): int {
    $insertUser->execute([':name'=>$display, ':email'=>$email, ':username'=>$username, ':hash'=>$hash, ':role'=>$role, ':created'=>$now, ':updated'=>$now]);
    return (int)$pdo->lastInsertId();
};
$a = $makeUser('HTTP Customer A', 'http-a@example.test', 'http-customer-a', 'customer');
$b = $makeUser('HTTP Customer B', 'http-b@example.test', 'http-customer-b', 'customer');
$staff = $makeUser('HTTP Staff', 'http-staff@example.test', 'http-staff', 'staff');
$admin = $makeUser('HTTP Admin', 'http-admin@example.test', 'http-admin', 'admin');
$financeStaff = $makeUser('HTTP Finance Staff', 'http-finance@example.test', 'http-finance', 'staff');
$c = $makeUser('HTTP Erasure Customer', 'http-c@example.test', 'http-customer', 'customer');
$pdo->prepare("INSERT INTO staff_permissions(user_id,permission,created_at) VALUES(:id,'support.view',UTC_TIMESTAMP())")->execute([':id'=>$staff]);
$pdo->prepare("INSERT INTO staff_permissions(user_id,permission,created_at) VALUES(:id,'dashboard.view',UTC_TIMESTAMP())")->execute([':id'=>$staff]);
$pdo->prepare("INSERT INTO staff_departments(user_id,department,created_at) VALUES(:id,'General Support',UTC_TIMESTAMP())")->execute([':id'=>$staff]);

$pdo->exec("INSERT INTO products(name,slug,product_type,status,featured,sort_order,created_at,updated_at) VALUES('HTTP Test Hosting','http-test-hosting','hosting','active',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$product = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-A-ORDER',:user,'paid','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$a]);
$orderA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-B-ORDER',:user,'paid','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b]);
$orderB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO services(user_id,product_id,order_id,domain,status,billing_cycle,amount,created_at,updated_at) VALUES(:user,:product,:order_id,:domain,'active','monthly',100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b, ':product'=>$product, ':order_id'=>$orderB, ':domain'=>'private-customer-b.example']);
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-B-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderB, ':user'=>$b]);
$invoiceB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-A-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderA, ':user'=>$a]);
$invoiceA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-C-ORDER',:user,'paid','TRY',50,0,50,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$c]);
$orderC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-C-INV',:order_id,:user,'paid','TRY',50,0,50,50,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderC, ':user'=>$c]);
$invoiceC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'manual',:key,'success','TRY',50,'HTTP-C-PAID',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':invoice'=>$invoiceC,':user'=>$c,':key'=>hash('sha256','http-c-paid-payment')]);
require_once $root . '/app/Core/SecretVault.php';
$testVault = new \HostPilot\Core\SecretVault(str_repeat('h', 64));
$paymentSettings = [
    'paytr_enabled'=>'1',
    'paytr_test_mode'=>'1',
    'paytr_merchant_id'=>'ci-merchant',
    'paytr_merchant_key'=>$testVault->encrypt('ci-merchant-key'),
    'paytr_merchant_salt'=>$testVault->encrypt('ci-merchant-salt'),
];
foreach ($paymentSettings as $settingKey=>$settingValue) {
    $pdo->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('payment',:key,:value,:secret,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret)")
        ->execute([':key'=>$settingKey,':value'=>$settingValue,':secret'=>str_contains($settingKey,'key')||str_contains($settingKey,'salt')?1:0]);
}
$domainSettings=[
    'enabled'=>'1',
    'environment'=>'sandbox',
    'user_id'=>'123456',
    'api_key_enc'=>$testVault->encrypt('ci-domain-api-key'),
];
foreach($domainSettings as $settingKey=>$settingValue){
    $pdo->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('domain_registrar',:key,:value,:secret,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret)")
        ->execute([':key'=>$settingKey,':value'=>$settingValue,':secret'=>$settingKey==='api_key_enc'?1:0]);
}
$pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'pending','TRY',100,'HTTP-EXISTING-PENDING',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':invoice'=>$invoiceA,':user'=>$a,':key'=>hash('sha256','http-existing-pending-payment')]);
$pdo->prepare("INSERT INTO domain_registrations(user_id,order_id,invoice_id,domain_name,years,registrar_payload_enc,idempotency_key,status,available_at,created_at,updated_at) VALUES(:user,:order_id,:invoice_id,'private-customer-b-domain.example',1,'{}',:key,'awaiting_payment',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':user'=>$b, ':order_id'=>$orderB, ':invoice_id'=>$invoiceB, ':key'=>hash('sha256','http-test-domain-b')]);
$domainB = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO tickets(ticket_number,user_id,department,subject,priority,status,last_reply_at,created_at,updated_at) VALUES('HTTP-B-TICKET',:user,'Sales','Private customer B ticket','normal','open',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b]);
$ticketB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:ticket,:user,'Private customer B message',0,UTC_TIMESTAMP())")->execute([':ticket'=>$ticketB, ':user'=>$b]);
$messageB = (int)$pdo->lastInsertId();

$storage = $root . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) throw new RuntimeException('Cannot create test storage.');
$config = [
    'database' => ['host'=>$host, 'port'=>$port, 'database'=>$name, 'username'=>$dbUser, 'password'=>$dbPass, 'charset'=>'utf8mb4'],
    'app' => ['base_path'=>'', 'base_url'=>'https://127.0.0.1:8091', 'key'=>str_repeat('h',64)],
];
file_put_contents($storage . '/config.php', '<?php return ' . var_export($config, true) . ';');
file_put_contents($storage . '/installed.lock', "http-security-test\n");
@chmod($storage . '/config.php', 0600);
@chmod($storage . '/installed.lock', 0600);
$pdo->prepare("INSERT INTO tickets(ticket_number,user_id,department,subject,priority,status,last_reply_at,created_at,updated_at) VALUES('HTTP-C-TICKET',:user,'General Support','Customer C private subject','normal','open',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$c]);
$ticketC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:ticket,:user,'Customer C private message',0,UTC_TIMESTAMP())")->execute([':ticket'=>$ticketC, ':user'=>$c]);
$messageC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO privacy_requests(user_id,request_type,status,request_details,created_at,updated_at) VALUES(:user,'erasure','approved','Approved by CI fixture.',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b]);
$privacyB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO privacy_requests(user_id,request_type,status,request_details,created_at,updated_at) VALUES(:user,'erasure','approved','Approved by CI fixture.',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$c]);
$privacyC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO email_outbox(idempotency_key,event_type,recipient,subject,body,status,available_at,created_at,updated_at) VALUES(:key,'general','http-c@example.test','Private notice','Personal email content','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':key'=>hash('sha256','http-c-outbox')]);
$attachmentDir = $storage . '/private/ticket-attachments';
if (!is_dir($attachmentDir) && !mkdir($attachmentDir, 0700, true) && !is_dir($attachmentDir)) throw new RuntimeException('Cannot create attachment fixture directory.');
$stored = bin2hex(random_bytes(24)) . '.txt';
$fileContent = 'private attachment for customer B';
file_put_contents($attachmentDir . '/' . $stored, $fileContent);
$pdo->prepare("INSERT INTO ticket_attachments(ticket_id,message_id,user_id,original_name,stored_name,mime_type,file_size,sha256,created_at) VALUES(:ticket,:message,:user,'private-b.txt',:stored,'text/plain',:size,:sha,UTC_TIMESTAMP())")
    ->execute([':ticket'=>$ticketB, ':message'=>$messageB, ':user'=>$b, ':stored'=>$stored, ':size'=>strlen($fileContent), ':sha'=>hash('sha256',$fileContent)]);
$attachmentB = (int)$pdo->lastInsertId();
$storedC = bin2hex(random_bytes(24)) . '.txt';
$fileContentC = 'private attachment for erasure customer';
file_put_contents($attachmentDir . '/' . $storedC, $fileContentC);
$pdo->prepare("INSERT INTO ticket_attachments(ticket_id,message_id,user_id,original_name,stored_name,mime_type,file_size,sha256,created_at) VALUES(:ticket,:message,:user,'private-c.txt',:stored,'text/plain',:size,:sha,UTC_TIMESTAMP())")
    ->execute([':ticket'=>$ticketC, ':message'=>$messageC, ':user'=>$c, ':stored'=>$storedC, ':size'=>strlen($fileContentC), ':sha'=>hash('sha256',$fileContentC)]);
$attachmentC = (int)$pdo->lastInsertId();

$serverLog = sys_get_temp_dir() . '/hostpilot-http-security-' . getmypid() . '.log';
$command = [PHP_BINARY, '-S', '127.0.0.1:8091', '-t', $root];
$descriptors = [0=>['pipe','r'], 1=>['file',$serverLog,'a'], 2=>['file',$serverLog,'a']];
$process = proc_open($command, $descriptors, $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Could not start PHP HTTP server.');
fclose($pipes[0]);
$base = 'http://127.0.0.1:8091';
$assert = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
};
$request = static function (string $url, string $cookie, string $method='GET', array $data=[], array $headers=[]) use ($base): array {
    $ch = curl_init($base . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>4,
        CURLOPT_COOKIEJAR=>$cookie, CURLOPT_COOKIEFILE=>$cookie, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
        CURLOPT_POST=>$method==='POST',
        CURLOPT_POSTFIELDS=>$method==='POST' ? http_build_query($data) : null,
        CURLOPT_HTTPGET=>$method==='GET',
        CURLOPT_HTTPHEADER=>array_merge($method==='POST' ? ['Content-Type: application/x-www-form-urlencoded'] : [],$headers),
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $error=curl_error($ch); curl_close($ch); throw new RuntimeException('HTTP request failed: '.$error); }
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status'=>$status,'body'=>(string)$body];
};
$getToken = static function (string $body): string {
    if (!preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $body, $m)) throw new RuntimeException('CSRF token not found in form.');
    return $m[1];
};
$login = static function (string $route, string $email, string $cookie) use ($request, $getToken, $password, $assert, $serverLog): void {
    $page=$request($route,$cookie);
    $token=$getToken($page['body']);
    $result=$request($route,$cookie,'POST',['_token'=>$token,'email'=>$email,'password'=>$password]);
    if ($result['status']!==200 || (!str_contains($result['body'],'HOST') && !str_contains($result['body'],'Müşteri'))) {
        throw new RuntimeException('HTTP login failed for '.$email.'; status='.$result['status'].'; body='.substr(strip_tags($result['body']),0,500).'; server-log='.(is_file($serverLog)?substr((string)file_get_contents($serverLog),-4000):'missing'));
    }
    $assert(true, 'HTTP login for '.$email);
};
$cookieA = sys_get_temp_dir().'/hp-http-a-'.getmypid().'.cookie';
$cookieB = sys_get_temp_dir().'/hp-http-b-'.getmypid().'.cookie';
$cookieStaff = sys_get_temp_dir().'/hp-http-staff-'.getmypid().'.cookie';
$cookieAdmin = sys_get_temp_dir().'/hp-http-admin-'.getmypid().'.cookie';
$cookieFinance = sys_get_temp_dir().'/hp-http-finance-'.getmypid().'.cookie';
$cookieCron = sys_get_temp_dir().'/hp-http-cron-'.getmypid().'.cookie';
try {
    for ($i=0;$i<20;$i++) { $probe=@fsockopen('127.0.0.1',8091,$errno,$errstr,0.2); if($probe){fclose($probe);break;} usleep(100000); }
    $login('/client/login','http-a@example.test',$cookieA);
    $login('/client/login','http-b@example.test',$cookieB);
    $login('/admin/login','http-staff@example.test',$cookieStaff);
    $login('/admin/login','http-admin@example.test',$cookieAdmin);

    $staffPermissionForm=$request('/admin/staff',$cookieAdmin);
    $staffPermissionToken=$getToken($staffPermissionForm['body']);
    $assignablePermissions=['dashboard.view','payments.view','servers.view','services.view','domains.orders.view','domains.operations.view'];
    $r=$request('/admin/staff/save',$cookieAdmin,'POST',[
        '_token'=>$staffPermissionToken,
        'user_id'=>$financeStaff,
        'permissions'=>array_merge($assignablePermissions,['payments.manage','servers.manage','domains.registrar.manage','privacy.requests.manage','automation.manage']),
        'departments'=>['Billing'],
    ]);
    $savedPermissions=$pdo->prepare('SELECT permission FROM staff_permissions WHERE user_id=:id ORDER BY permission');
    $savedPermissions->execute([':id'=>$financeStaff]);$savedPermissionList=$savedPermissions->fetchAll(PDO::FETCH_COLUMN);
    $permissionAudit=$pdo->prepare("SELECT COUNT(*) FROM security_audit_logs WHERE user_id=:actor AND event_key='staff.permissions_updated' AND metadata_json LIKE :target");
    $permissionAudit->execute([':actor'=>$admin,':target'=>'%"target_user_id":'.$financeStaff.'%']);
    $assert($r['status']===200 && count($savedPermissionList)===count($assignablePermissions)
        && count(array_intersect($assignablePermissions,$savedPermissionList))===count($assignablePermissions)
        && (int)$permissionAudit->fetchColumn()===1,
        'admin can assign the complete safe staff catalog and permission changes are audited');
    $login('/admin/login','http-finance@example.test',$cookieFinance);

    $cronKey=str_repeat('h',64);
    $cronCases=[
        ['/cron/provision','HostPilot provisioning cron v1'],
        ['/cron/email','HostPilot email cron v1'],
        ['/cron/domains','HostPilot domain cron v1'],
        ['/cron/domain-renewals','HostPilot domain cron v1'],
        ['/cron/domain-expiry-sync','HostPilot domain sync cron v1'],
    ];
    foreach($cronCases as [$cronPath,$purpose]){
        $queryToken=hash_hmac('sha256',$purpose,$cronKey);
        $r=$request($cronPath.'?token='.$queryToken,$cookieCron);
        $assert($r['status']===403,'cron endpoint rejects bearer secret in query string: '.$cronPath);
    }

    $paymentForm=$request('/client/invoices/'.$invoiceA.'/pay',$cookieA);
    $paymentToken=$getToken($paymentForm['body']);
    $beforePaymentAttempts=(int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE invoice_id=".$invoiceA)->fetchColumn();
    $r=$request('/client/invoices/'.$invoiceA.'/pay',$cookieA,'POST',[
        '_token'=>$paymentToken,'gateway'=>'paytr','phone'=>'+905551112233','address'=>'Test address','city'=>'Ankara','zip'=>'06000'
    ]);
    $afterPaymentAttempts=(int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE invoice_id=".$invoiceA)->fetchColumn();
    $assert($r['status']===409 && str_contains($r['body'],'bekleyen bir ödeme işlemi') && $afterPaymentAttempts===$beforePaymentAttempts,
        'customer cannot create a second gateway attempt while an invoice payment is pending');

    $r=$request('/client/tickets/'.$ticketB,$cookieA);
    $assert($r['status']===404 && !str_contains($r['body'],'Private customer B message'),'customer A cannot read customer B ticket over HTTP');
    $r=$request('/client/tickets/attachment/'.$attachmentB,$cookieA);
    $assert($r['status']===404 && !str_contains($r['body'],$fileContent),'customer A cannot download customer B attachment over HTTP');
    $r=$request('/client/invoices/'.$invoiceB.'/pay',$cookieA);
    $assert($r['status']===404 && !str_contains($r['body'],'HTTP-B-INV'),'customer A cannot open customer B invoice payment over HTTP');
    $packageForm=$request('/client/tickets/new',$cookieA);
    $packageToken=$getToken($packageForm['body']);
    $beforeChanges=(int)$pdo->query("SELECT COUNT(*) FROM service_package_changes WHERE service_id IN (SELECT id FROM services WHERE user_id=".$b.")")->fetchColumn();
    $r=$request('/client/services/change',$cookieA,'POST',[
        '_token'=>$packageToken,'service_id'=>(string)$pdo->query("SELECT id FROM services WHERE user_id=".$b." LIMIT 1")->fetchColumn(),'product_id'=>(string)$product
    ]);
    $afterChanges=(int)$pdo->query("SELECT COUNT(*) FROM service_package_changes WHERE service_id IN (SELECT id FROM services WHERE user_id=".$b.")")->fetchColumn();
    $assert($afterChanges===$beforeChanges,'customer A cannot create package change for customer B service over HTTP');
    $r=$request('/client/invoices/'.$invoiceB.'/pay',$cookieA,'POST',['_token'=>$packageToken]);
    $assert($r['status']===404 && !str_contains($r['body'],'HTTP-B-INV'),'customer A cannot POST payment initiation for customer B invoice');
    $r=$request('/admin/payments',$cookieA,'POST',['_token'=>$packageToken,'paytr_enabled'=>'1','paytr_merchant_id'=>'attacker']);
    $assert($r['status']===403,'customer cannot POST admin payment gateway settings');
    $r=$request('/admin/payments',$cookieStaff);
    $assert($r['status']===403,'support staff without finance permission cannot view payment gateway settings');
    $r=$request('/admin/payments',$cookieStaff,'POST',['_token'=>$packageToken,'paytr_enabled'=>'1','paytr_merchant_id'=>'staff-attacker']);
    $assert($r['status']===403,'support staff without finance permission cannot change payment gateway settings');
    $r=$request('/admin/payment-transactions',$cookieStaff);
    $assert($r['status']===403,'support staff without finance permission cannot view payment transaction records');
    foreach (['/admin/servers','/admin/services','/admin/settings','/admin/email-queue','/admin/automation','/admin/updates','/admin/products','/admin/orders','/admin/invoices','/admin/database-upgrade','/admin/privacy'] as $restrictedRoute) {
        $r=$request($restrictedRoute,$cookieStaff);
        $assert($r['status']===403,'support-only staff cannot access '.$restrictedRoute);
    }
    foreach ([
        ['/admin/servers/save',['_token'=>$packageToken,'name'=>'attacker']],
        ['/admin/services/terminate',['_token'=>$packageToken,'service_id'=>'1','confirm_domain'=>'example.test']],
        ['/admin/settings',['_token'=>$packageToken,'action'=>'save','host'=>'attacker.test']],
        ['/admin/automation',['_token'=>$packageToken,'billing_grace_days'=>'1']],
        ['/admin/database-upgrade',['_token'=>$packageToken,'confirm_upgrade'=>'1']],
        ['/admin/privacy',['_token'=>$packageToken,'action'=>'export']],
    ] as [$restrictedRoute,$payload]) {
        $r=$request($restrictedRoute,$cookieStaff,'POST',$payload);
        $assert($r['status']===403,'support-only staff cannot POST '.$restrictedRoute);
    }



    $r=$request('/client/services',$cookieA);
    $assert(!str_contains($r['body'],'private-customer-b.example'),'customer A service list excludes customer B service');
    $r=$request('/client/domains',$cookieA);
    $assert(!str_contains($r['body'],'private-customer-b-domain.example'),'customer A domain list excludes customer B domain');
    $r=$request('/client/domains/sync',$cookieA,'POST',['domain_id'=>(string)$domainB,'_token'=>'']);
    $assert($r['status']===419,'domain mutation rejects missing CSRF token');
    // A valid CSRF token must not let customer A mutate customer B's active domain.
    $pdo->prepare("UPDATE domain_registrations SET status='active',registrar_order_id='HTTP-REMOTE-B' WHERE id=:id")->execute([':id'=>$domainB]);
    $domainForm=$request('/client/tickets/new',$cookieA);
    $domainToken=$getToken($domainForm['body']);
    $r=$request('/client/domains/nameservers',$cookieA,'POST',[
        '_token'=>$domainToken,'domain_id'=>(string)$domainB,'nameservers'=>"ns1.example.net\nns2.example.net"
    ]);
    $domainAfter=$pdo->prepare('SELECT pending_nameservers_enc,nameserver_status FROM domain_registrations WHERE id=:id');
    $domainAfter->execute([':id'=>$domainB]);
    $domainState=$domainAfter->fetch(PDO::FETCH_ASSOC);
    $assert($r['status']===200 && empty($domainState['pending_nameservers_enc']) && empty($domainState['nameserver_status']),
        'customer A cannot mutate customer B domain even with valid CSRF token');
    $privacyForm=$request('/admin/privacy',$cookieAdmin);
    $privacyToken=$getToken($privacyForm['body']);
    $r=$request('/admin/privacy',$cookieAdmin,'POST',['_token'=>$privacyToken,'action'=>'complete','request_id'=>$privacyB]);
    $privacyBStatus=$pdo->prepare('SELECT status FROM privacy_requests WHERE id=:id');
    $privacyBStatus->execute([':id'=>$privacyB]);$privacyBCurrent=(string)$privacyBStatus->fetchColumn();
    $privacyBodyText=strip_tags((string)preg_replace('#<style[^>]*>.*?</style>#s','',$r['body']));
    $assert($r['status']===200 && str_contains($privacyBodyText,'Aktif veya sonlandırılmamış hizmetler var') && $privacyBCurrent==='approved',
        'erasure completion is blocked while customer has an active hosting service; HTTP '.$r['status'].'; request='.$privacyBCurrent.'; body='.substr($privacyBodyText,0,600));

    $r=$request('/admin/privacy',$cookieAdmin,'POST',['_token'=>$privacyToken,'action'=>'complete','request_id'=>$privacyC]);
    $erased=$pdo->prepare('SELECT name,email,username,status,auth_version FROM users WHERE id=:id');
    $erased->execute([':id'=>$c]);$erasedUser=$erased->fetch(PDO::FETCH_ASSOC);
    $requestStatus=$pdo->prepare('SELECT status FROM privacy_requests WHERE id=:id');$requestStatus->execute([':id'=>$privacyC]);
    $redacted=$pdo->prepare('SELECT subject FROM tickets WHERE id=:id');$redacted->execute([':id'=>$ticketC]);
    $redactedMessage=$pdo->prepare('SELECT message FROM ticket_messages WHERE id=:id');$redactedMessage->execute([':id'=>$messageC]);
    $remainingAttachments=$pdo->prepare('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id=:id');$remainingAttachments->execute([':id'=>$ticketC]);
    $remainingOutbox=(int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE recipient='http-c@example.test'")->fetchColumn();
    $retainedInvoice=$pdo->prepare('SELECT COUNT(*) FROM invoices WHERE id=:id AND user_id=:user AND status=\'paid\'');$retainedInvoice->execute([':id'=>$invoiceC,':user'=>$c]);
    $retainedPayment=$pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE invoice_id=:id AND status=\'success\'');$retainedPayment->execute([':id'=>$invoiceC]);
    $assert($r['status']===200 && str_contains($r['body'],'Hesap anonimleştirildi') && ($erasedUser['status']??'')==='deleted'
        && ($erasedUser['email']??'')==='deleted-user-'.$c.'@privacy.invalid' && ($erasedUser['username']??'')==='deleted_user_'.$c
        && (int)($erasedUser['auth_version']??0)>1 && $requestStatus->fetchColumn()==='completed',
        'approved erasure anonymizes the account, revokes sessions and completes the request');
    $assert($redacted->fetchColumn()==='[İçerik silindi - KVKK]' && $redactedMessage->fetchColumn()==='[İçerik silindi - KVKK]'
        && (int)$remainingAttachments->fetchColumn()===0 && !is_file($attachmentDir.'/'.$storedC) && $remainingOutbox===0,
        'erasure redacts support content and deletes private attachments and queued email');
    $assert((int)$retainedInvoice->fetchColumn()===1 && (int)$retainedPayment->fetchColumn()===1,
        'paid invoice and successful payment evidence remain for statutory retention');

    $domainSyncToken=hash_hmac('sha256','HostPilot domain sync cron v1',$cronKey);
    $r=$request('/cron/domain-expiry-sync',$cookieCron,'GET',[],['X-HostPilot-Cron-Token: '.$domainSyncToken]);
    $assert($r['status']===200 && str_contains($r['body'],'"ok":true'),'cron worker accepts a valid dedicated token header over HTTP; status='.$r['status'].'; body='.substr(strip_tags($r['body']),0,400));
    $r=$request('/cron/domain-expiry-sync',$cookieCron,'GET',[],['X-HostPilot-Cron-Token: '.str_repeat('0',64)]);
    $assert($r['status']===403,'cron worker rejects an invalid dedicated token header over HTTP');

    $r=$request('/admin/payment-transactions',$cookieStaff);
    $assert($r['status']===403,'support-only staff cannot view payment transactions');
    $r=$request('/admin/payment-transactions',$cookieFinance);
    $assert($r['status']===200 && str_contains($r['body'],'HTTP-EXISTING-PENDING'),'finance staff can view payment transactions with payments.view');
    $r=$request('/admin/payments',$cookieFinance);
    $assert($r['status']===403,'payments.view does not grant payment gateway settings access');
    $r=$request('/admin/servers',$cookieFinance);
    $assert($r['status']===200,'staff with servers.view can view server inventory');
    $r=$request('/admin/services',$cookieFinance);
    $assert($r['status']===200,'staff with services.view can view hosting services');
    $r=$request('/admin/domains/orders',$cookieFinance);
    $assert($r['status']===200,'staff with domains.orders.view can view domain orders');
    $r=$request('/admin/domains/renewals',$cookieFinance);
    $assert($r['status']===200,'staff with domains.operations.view can view domain renewals');
    $r=$request('/admin/domains/registrar',$cookieFinance);
    $assert($r['status']===403,'domain read permissions do not grant registrar credential settings');
    $r=$request('/admin/staff',$cookieFinance);
    $assert($r['status']===403,'staff cannot manage staff permissions');
    $r=$request('/admin',$cookieFinance);
    $assert($r['status']===200 && str_contains($r['body'],'/admin/payment-transactions') && str_contains($r['body'],'/admin/servers')
        && !str_contains($r['body'],'/admin/payments') && !str_contains($r['body'],'/admin/domains/registrar'),
        'staff dashboard navigation shows only assigned modules');

    $r=$request('/admin',$cookieA);
    $assert($r['status']===403,'customer cannot access admin route over HTTP');

    $r=$request('/admin/tickets/'.$ticketB,$cookieStaff);
    $assert($r['status']===404 && !str_contains($r['body'],'Private customer B message'),'staff cannot read ticket outside assigned department');
    $r=$request('/admin/tickets/attachment/'.$attachmentB,$cookieStaff);
    $assert($r['status']===404 && !str_contains($r['body'],$fileContent),'staff cannot download attachment outside assigned department');

    $before=(int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE user_id=".$a)->fetchColumn();
    $form=$request('/client/tickets/new',$cookieA);
    $r=$request('/client/tickets/new',$cookieA,'POST',['subject'=>'CSRF missing token','message'=>'This request must be rejected without a token.','department'=>'General Support']);
    $after=(int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE user_id=".$a)->fetchColumn();
    $assert($after===$before && str_contains($r['body'],'Güvenlik doğrulaması başarısız'),'ticket creation rejects missing CSRF token without database mutation');

    $token=$getToken($form['body']);
    $xssSubject='<img src=x onerror=alert(1)>';
    $xssMessage='<script>alert("hostpilot-xss")</script>';
    $r=$request('/client/tickets/new',$cookieA,'POST',['_token'=>$token,'subject'=>$xssSubject,'message'=>$xssMessage,'department'=>'General Support']);
    $assert($r['status']===200 && str_contains($r['body'],'&lt;img') && str_contains($r['body'],'&lt;script&gt;') && !str_contains($r['body'],'<script>alert("hostpilot-xss")</script>'),'stored ticket XSS payloads are escaped in real HTTP response');

    echo "HostPilot disposable HTTP security integration passed.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach ([$cookieA,$cookieB,$cookieStaff,$cookieAdmin,$cookieFinance,$cookieCron,$serverLog] as $path) if (is_file($path)) @unlink($path);
    @unlink($storage . '/config.php');
    @unlink($storage . '/installed.lock');
    if (is_file($attachmentDir . '/' . $stored)) @unlink($attachmentDir . '/' . $stored);
    if (isset($storedC) && is_file($attachmentDir . '/' . $storedC)) @unlink($attachmentDir . '/' . $storedC);
    @rmdir($attachmentDir);
    @rmdir(dirname($attachmentDir));
    $rateDir = $storage . '/auth-rate-limits';
    foreach (glob($rateDir . '/*') ?: [] as $rateFile) if (is_file($rateFile)) @unlink($rateFile);
    @rmdir($rateDir);
}
