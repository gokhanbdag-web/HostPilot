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
    'app' => ['base_path'=>'', 'base_url'=>'http://127.0.0.1:8091', 'key'=>str_repeat('h',64)],
];
file_put_contents($storage . '/config.php', '<?php return ' . var_export($config, true) . ';');
file_put_contents($storage . '/installed.lock', "http-security-test\n");
@chmod($storage . '/config.php', 0600);
@chmod($storage . '/installed.lock', 0600);
$attachmentDir = $storage . '/private/ticket-attachments';
if (!is_dir($attachmentDir) && !mkdir($attachmentDir, 0700, true) && !is_dir($attachmentDir)) throw new RuntimeException('Cannot create attachment fixture directory.');
$stored = bin2hex(random_bytes(24)) . '.txt';
$fileContent = 'private attachment for customer B';
file_put_contents($attachmentDir . '/' . $stored, $fileContent);
$pdo->prepare("INSERT INTO ticket_attachments(ticket_id,message_id,user_id,original_name,stored_name,mime_type,file_size,sha256,created_at) VALUES(:ticket,:message,:user,'private-b.txt',:stored,'text/plain',:size,:sha,UTC_TIMESTAMP())")
    ->execute([':ticket'=>$ticketB, ':message'=>$messageB, ':user'=>$b, ':stored'=>$stored, ':size'=>strlen($fileContent), ':sha'=>hash('sha256',$fileContent)]);
$attachmentB = (int)$pdo->lastInsertId();

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
$request = static function (string $url, string $cookie, string $method='GET', array $data=[]) use ($base): array {
    $ch = curl_init($base . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>4,
        CURLOPT_COOKIEJAR=>$cookie, CURLOPT_COOKIEFILE=>$cookie, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
        CURLOPT_POST=>$method==='POST',
        CURLOPT_POSTFIELDS=>$method==='POST' ? http_build_query($data) : null,
        CURLOPT_HTTPGET=>$method==='GET',
        CURLOPT_HTTPHEADER=>$method==='POST' ? ['Content-Type: application/x-www-form-urlencoded'] : [],
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
try {
    for ($i=0;$i<20;$i++) { $probe=@fsockopen('127.0.0.1',8091,$errno,$errstr,0.2); if($probe){fclose($probe);break;} usleep(100000); }
    $login('/client/login','http-a@example.test',$cookieA);
    $login('/client/login','http-b@example.test',$cookieB);
    $login('/admin/login','http-staff@example.test',$cookieStaff);

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
    foreach ([$cookieA,$cookieB,$cookieStaff,$serverLog] as $path) if (is_file($path)) @unlink($path);
    @unlink($storage . '/config.php');
    @unlink($storage . '/installed.lock');
    if (is_file($attachmentDir . '/' . $stored)) @unlink($attachmentDir . '/' . $stored);
    @rmdir($attachmentDir);
    @rmdir(dirname($attachmentDir));
    $rateDir = $storage . '/auth-rate-limits';
    foreach (glob($rateDir . '/*') ?: [] as $rateFile) if (is_file($rateFile)) @unlink($rateFile);
    @rmdir($rateDir);
}
