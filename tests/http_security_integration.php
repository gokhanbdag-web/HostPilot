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
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_POSTFIELDS=>$method==='POST' ? http_build_query($data) : null,
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
$login = static function (string $route, string $email, string $cookie) use ($request, $getToken, $password, $assert): void {
    $page=$request($route,$cookie);
    $token=$getToken($page['body']);
    $result=$request($route,$cookie,'POST',['_token'=>$token,'email'=>$email,'password'=>$password]);
    $assert($result['status']===200 && (str_contains($result['body'],'HOST') || str_contains($result['body'],'Müşteri')), 'HTTP login for '.$email);
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
    $r=$request('/client/services',$cookieA);
    $assert(!str_contains($r['body'],'private-customer-b.example'),'customer A service list excludes customer B service');
    $r=$request('/client/domains',$cookieA);
    $assert(!str_contains($r['body'],'private-customer-b-domain.example'),'customer A domain list excludes customer B domain');
    $r=$request('/client/domains/sync',$cookieA,'POST',['domain_id'=>(string)$domainB,'_token'=>'']);
    $assert($r['status']===419,'domain mutation rejects missing CSRF token');
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
