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
$superAdmin = $makeUser('HTTP Super Admin', 'http-super@example.test', 'http-super', 'super_admin');
$financeStaff = $makeUser('HTTP Finance Staff', 'http-finance@example.test', 'http-finance', 'staff');
$c = $makeUser('HTTP Erasure Customer', 'http-c@example.test', 'http-customer', 'customer');
$pdo->prepare("INSERT INTO staff_permissions(user_id,permission,created_at) VALUES(:id,'support.view',UTC_TIMESTAMP())")->execute([':id'=>$staff]);
$pdo->prepare("INSERT INTO staff_permissions(user_id,permission,created_at) VALUES(:id,'dashboard.view',UTC_TIMESTAMP())")->execute([':id'=>$staff]);
$pdo->prepare("INSERT INTO staff_departments(user_id,department,created_at) VALUES(:id,'General Support',UTC_TIMESTAMP())")->execute([':id'=>$staff]);

$pdo->exec("INSERT INTO products(name,slug,product_type,status,featured,sort_order,created_at,updated_at) VALUES('HTTP Test Hosting','http-test-hosting','hosting','active',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$product = (int)$pdo->lastInsertId();

$cartProductInsert=$pdo->prepare("INSERT INTO products(name,slug,product_type,status,featured,sort_order,created_at,updated_at) VALUES(:name,:slug,:type,'active',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$cartPriceInsert=$pdo->prepare("INSERT INTO product_pricing(product_id,currency,billing_cycle,price,setup_fee,is_active,created_at,updated_at) VALUES(:product,:currency,:cycle,:price,:setup,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$makeCartProduct=static function(string $name,string $slug,string $type,string $currency,string $cycle,string $price,string $setup)use($pdo,$cartProductInsert,$cartPriceInsert):int{
    $cartProductInsert->execute([':name'=>$name,':slug'=>$slug,':type'=>$type]);$id=(int)$pdo->lastInsertId();
    $cartPriceInsert->execute([':product'=>$id,':currency'=>$currency,':cycle'=>$cycle,':price'=>$price,':setup'=>$setup]);return $id;
};
$cartHostingA=$makeCartProduct('HTTP Cart Hosting A','http-cart-hosting-a','hosting','TRY','monthly','100.00','25.00');
$cartHostingB=$makeCartProduct('HTTP Cart Hosting B','http-cart-hosting-b','hosting','TRY','annually','200.00','10.00');
$cartUsdProduct=$makeCartProduct('HTTP Cart USD Product','http-cart-usd','ssl','USD','monthly','50.00','0.00');
$cartDomainA=$makeCartProduct('HTTP Cart Domain A','http-cart-domain-a','domain','TRY','annually','500.00','0.00');
$cartDomainB=$makeCartProduct('HTTP Cart Domain B','http-cart-domain-b','domain','TRY','annually','600.00','0.00');

$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-A-ORDER',:user,'paid','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$a]);
$orderA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-B-ORDER',:user,'paid','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b]);
$orderB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO services(user_id,product_id,order_id,domain,status,billing_cycle,amount,created_at,updated_at) VALUES(:user,:product,:order_id,:domain,'active','monthly',100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$b, ':product'=>$product, ':order_id'=>$orderB, ':domain'=>'private-customer-b.example']);
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-B-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderB, ':user'=>$b]);
$invoiceB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,'Customer B confidential line',1,100,0,0,100,UTC_TIMESTAMP())")->execute([':invoice'=>$invoiceB]);
$pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,response_code,safe_message,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'review','TRY',100,'HTTP-B-REVIEW','overpayment_review','Provider-confirmed duplicate capture awaiting review',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':invoice'=>$invoiceB,':user'=>$b,':key'=>hash('sha256','http-b-review-payment')]);
$reviewPaymentId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-A-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderA, ':user'=>$a]);
$invoiceA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,'Customer A hosting plan',1,100,0,0,100,UTC_TIMESTAMP())")->execute([':invoice'=>$invoiceA]);
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-C-ORDER',:user,'paid','TRY',50,0,50,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$c]);
$orderC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-C-INV',:order_id,:user,'paid','TRY',50,0,50,50,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$orderC, ':user'=>$c]);
$invoiceC = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,'Customer C paid hosting plan',1,50,0,0,50,UTC_TIMESTAMP())")->execute([':invoice'=>$invoiceC]);
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
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-STALE-ORDER',:user,'pending','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$a]);
$staleOrder = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-STALE-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$staleOrder,':user'=>$a]);
$staleInvoice = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'pending','TRY',100,'HTTP-STALE-PENDING',UTC_TIMESTAMP()-INTERVAL 45 MINUTE,UTC_TIMESTAMP()-INTERVAL 45 MINUTE)")
    ->execute([':invoice'=>$staleInvoice,':user'=>$a,':key'=>hash('sha256','http-stale-pending-payment')]);
$stalePaymentId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES('HTTP-DUPLICATE-ORDER',:user,'pending','TRY',100,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':user'=>$a]);
$duplicateOrder = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,created_at,updated_at) VALUES('HTTP-DUPLICATE-INV',:order_id,:user,'unpaid','TRY',100,0,100,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([':order_id'=>$duplicateOrder,':user'=>$a]);
$duplicateInvoice = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'pending','TRY',100,'HTTP-DUPLICATE-SUCCESS',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':invoice'=>$duplicateInvoice,':user'=>$a,':key'=>hash('sha256','http-duplicate-success-payment')]);
$duplicatePaymentId = (int)$pdo->lastInsertId();
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
$cookieC = sys_get_temp_dir().'/hp-http-c-'.getmypid().'.cookie';
$cookieStaff = sys_get_temp_dir().'/hp-http-staff-'.getmypid().'.cookie';
$cookieAdmin = sys_get_temp_dir().'/hp-http-admin-'.getmypid().'.cookie';
$cookieSuper = sys_get_temp_dir().'/hp-http-super-'.getmypid().'.cookie';
$cookieFinance = sys_get_temp_dir().'/hp-http-finance-'.getmypid().'.cookie';
$cookieCron = sys_get_temp_dir().'/hp-http-cron-'.getmypid().'.cookie';
try {
    for ($i=0;$i<20;$i++) { $probe=@fsockopen('127.0.0.1',8091,$errno,$errstr,0.2); if($probe){fclose($probe);break;} usleep(100000); }
    // A guest can build a cart before login; the session cart must survive customer authentication.
    $guestProductPage=$request('/product/http-cart-hosting-a',$cookieA);
    $guestAdd=$request('/cart/add',$cookieA,'POST',[
        '_token'=>$getToken($guestProductPage['body']),'product_id'=>$cartHostingA,'billing_cycle'=>'monthly',
    ]);
    $assert($guestAdd['status']===200&&str_contains($guestAdd['body'],'Alışveriş sepetiniz')&&str_contains($guestAdd['body'],'HTTP Cart Hosting A'),
        'guest can add a product to the session cart before login');
    $login('/client/login','http-a@example.test',$cookieA);
    $cartAfterLogin=$request('/cart',$cookieA);
    $assert($cartAfterLogin['status']===200&&str_contains($cartAfterLogin['body'],'HTTP Cart Hosting A'),
        'guest cart is preserved after customer login');
    $productBPage=$request('/product/http-cart-hosting-b',$cookieA);
    $addB=$request('/cart/add',$cookieA,'POST',[
        '_token'=>$getToken($productBPage['body']),'product_id'=>$cartHostingB,'billing_cycle'=>'annually',
    ]);
    $assert($addB['status']===200&&str_contains($addB['body'],'HTTP Cart Hosting A')&&str_contains($addB['body'],'HTTP Cart Hosting B'),
        'cart accepts multiple products in a single currency');
    $duplicateAdd=$request('/cart/add',$cookieA,'POST',[
        '_token'=>$getToken($productBPage['body']),'product_id'=>$cartHostingA,'billing_cycle'=>'monthly',
    ]);
    $assert($duplicateAdd['status']===409,'cart prevents duplicate product and billing-cycle entries');
    $usdPage=$request('/product/http-cart-usd',$cookieA);
    $currencyAdd=$request('/cart/add',$cookieA,'POST',[
        '_token'=>$getToken($usdPage['body']),'product_id'=>$cartUsdProduct,'billing_cycle'=>'monthly',
    ]);
    $assert($currencyAdd['status']===409,'cart prevents mixed-currency orders');
    $domainPage=$request('/product/http-cart-domain-a',$cookieA);
    $domainFields=['domain_name'=>'cart-domain.example','addressLine1'=>'Test Address 1','city'=>'Ankara','country'=>'TR','zipcode'=>'06000','phoneCountryCode'=>'90','phone'=>'5321234567'];
    $domainAdd=$request('/cart/add',$cookieA,'POST',array_merge(['_token'=>$getToken($domainPage['body']),'product_id'=>$cartDomainA,'billing_cycle'=>'annually'],$domainFields));
    $assert($domainAdd['status']===200&&str_contains($domainAdd['body'],'cart-domain.example'),
        'cart accepts one domain with validated registrant details');
    $domainPageB=$request('/product/http-cart-domain-b',$cookieA);
    $domainSecond=$request('/cart/add',$cookieA,'POST',array_merge(['_token'=>$getToken($domainPageB['body']),'product_id'=>$cartDomainB,'billing_cycle'=>'annually'],array_merge($domainFields,['domain_name'=>'cart-domain-two.example'])));
    $assert($domainSecond['status']===409,'cart prevents a second domain in the same order');
    $invalidRemove=$request('/cart/remove',$cookieA,'POST',['_token'=>'invalid','cart_key'=>$cartDomainA.':annually']);
    $assert($invalidRemove['status']===400,'cart removal rejects invalid CSRF tokens');
    $cartPageBeforeRemove=$request('/cart',$cookieA);
    $removeDomain=$request('/cart/remove',$cookieA,'POST',['_token'=>$getToken($cartPageBeforeRemove['body']),'cart_key'=>$cartDomainA.':annually']);
    $assert($removeDomain['status']===200&&!str_contains($removeDomain['body'],'cart-domain.example'),
        'customer can remove a domain from the cart using CSRF protection');
    // Price and setup-fee changes made after adding items are reflected at checkout.
    $pdo->prepare("UPDATE product_pricing SET price='220.00',setup_fee='15.00' WHERE product_id=:id AND billing_cycle='annually'")->execute([':id'=>$cartHostingB]);
    $cartPageBeforeCheckout=$request('/cart',$cookieA);
    $assert(str_contains($cartPageBeforeCheckout['body'],'235,00')&&str_contains($cartPageBeforeCheckout['body'],'15,00'),
        'cart re-reads current product pricing and setup fees');
    $cartCheckout=$request('/order/create',$cookieA,'POST',['_token'=>$getToken($cartPageBeforeCheckout['body'])]);
    if($cartCheckout['status']!==200||!str_contains($cartCheckout['body'],'Faturalarım')){
        throw new RuntimeException('Cart checkout HTTP failure; status='.$cartCheckout['status'].'; body='.substr(strip_tags($cartCheckout['body']),0,1200).'; server-log='.(is_file($serverLog)?substr((string)file_get_contents($serverLog),-6000):'missing'));
    }
    $assert(true,'customer can create one consolidated order and invoice from multiple cart items');
    $cartOrderQuery=$pdo->prepare('SELECT id,subtotal,total,currency FROM orders WHERE user_id=:user ORDER BY id DESC LIMIT 1');
    $cartOrderQuery->execute([':user'=>$a]);$cartOrder=$cartOrderQuery->fetch(PDO::FETCH_ASSOC);
    $cartOrderItems=$pdo->prepare('SELECT COUNT(*) AS item_count,SUM(unit_price) AS unit_total,SUM(line_total) AS line_total FROM order_items WHERE order_id=:id');
    $cartOrderItems->execute([':id'=>$cartOrder['id']]);$cartOrderItemTotals=$cartOrderItems->fetch(PDO::FETCH_ASSOC);
    $cartInvoiceQuery=$pdo->prepare('SELECT id,subtotal,total FROM invoices WHERE order_id=:id LIMIT 1');
    $cartInvoiceQuery->execute([':id'=>$cartOrder['id']]);$cartInvoice=$cartInvoiceQuery->fetch(PDO::FETCH_ASSOC);
    $cartInvoiceItems=$pdo->prepare('SELECT COUNT(*) AS item_count,SUM(line_total) AS line_total FROM invoice_items WHERE invoice_id=:id');
    $cartInvoiceItems->execute([':id'=>$cartInvoice['id']]);$cartInvoiceItemTotals=$cartInvoiceItems->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($cartOrder)&&is_array($cartInvoice)&&($cartOrder['currency']??'')==='TRY'
        &&(float)$cartOrder['subtotal']===360.0&&(float)$cartOrder['total']===360.0
        &&(int)$cartOrderItemTotals['item_count']===2&&(float)$cartOrderItemTotals['unit_total']===320.0&&(float)$cartOrderItemTotals['line_total']===320.0
        &&(float)$cartInvoice['subtotal']===360.0&&(float)$cartInvoice['total']===360.0
        &&(int)$cartInvoiceItemTotals['item_count']===4&&(float)$cartInvoiceItemTotals['line_total']===360.0,
        'recurring order items exclude one-time setup fees while invoice lines reconcile to the full multi-item total');
    $cartAfterCheckout=$request('/cart',$cookieA);
    $assert(str_contains($cartAfterCheckout['body'],'Sepetiniz şu anda boş'),
        'successful checkout clears the session cart');
    $domainPage=$request('/product/http-cart-domain-a',$cookieA);
    $domainAdd=$request('/cart/add',$cookieA,'POST',array_merge(['_token'=>$getToken($domainPage['body']),'product_id'=>$cartDomainA,'billing_cycle'=>'annually'],$domainFields));
    $domainCartPage=$request('/cart',$cookieA);
    $domainCheckout=$request('/order/create',$cookieA,'POST',['_token'=>$getToken($domainCartPage['body'])]);
    $domainOrderQuery=$pdo->prepare('SELECT id FROM orders WHERE user_id=:user ORDER BY id DESC LIMIT 1');
    $domainOrderQuery->execute([':user'=>$a]);$domainOrderId=(int)$domainOrderQuery->fetchColumn();
    $domainRegistration=$pdo->prepare('SELECT domain_name,years,status,registrar_payload_enc FROM domain_registrations WHERE order_id=:id LIMIT 1');
    $domainRegistration->execute([':id'=>$domainOrderId]);$domainRegistrationRow=$domainRegistration->fetch(PDO::FETCH_ASSOC);
    $assert($domainCheckout['status']===200&&is_array($domainRegistrationRow)
        &&$domainRegistrationRow['domain_name']==='cart-domain.example'&&(int)$domainRegistrationRow['years']===1
        &&$domainRegistrationRow['status']==='awaiting_payment'&&$domainRegistrationRow['registrar_payload_enc']!=='',
        'single-domain checkout preserves encrypted registrar payload and creates one pending domain registration');

    $login('/client/login','http-b@example.test',$cookieB);
    $login('/client/login','http-b@example.test',$cookieB);
    $login('/client/login','http-c@example.test',$cookieC);
    // Customer MFA enrollment, login challenge, TOTP replay prevention and recovery-code use.
    require_once $root . '/app/Core/Mfa.php';
    $customerMfaPage=$request('/client/security/mfa',$cookieA);
    $assert($customerMfaPage['status']===200 && str_contains($customerMfaPage['body'],'Durum: Kapalı'),
        'customer can open optional MFA settings while MFA is disabled');
    $startCustomerMfa=$request('/client/security/mfa',$cookieA,'POST',[
        '_token'=>$getToken($customerMfaPage['body']),'action'=>'start','password'=>$password,
    ]);
    $assert($startCustomerMfa['status']===200 && preg_match('/<div class="secret">([^<]+)<\/div>/',$startCustomerMfa['body'],$customerSecretMatch)===1,
        'customer MFA enrollment requires password re-entry and issues a TOTP secret');
    $customerSecret=html_entity_decode($customerSecretMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
    $customerEnrollmentCode=\HostPilot\Core\Mfa::codeAt($customerSecret,time());
    if(!is_string($customerEnrollmentCode))throw new RuntimeException('Unable to generate customer enrollment TOTP.');
    $customerEnabled=$request('/client/security/mfa',$cookieA,'POST',[
        '_token'=>$getToken($startCustomerMfa['body']),'action'=>'enable','password'=>$password,'code'=>$customerEnrollmentCode,
    ]);
    $assert($customerEnabled['status']===200 && str_contains($customerEnabled['body'],'Kurtarma kodlarınız')
        && preg_match('/<pre class="codes">([^<]+)<\/pre>/',$customerEnabled['body'],$customerCodesMatch)===1,
        'customer MFA enrollment verifies TOTP and displays one-time recovery codes');
    $customerRecoveryCodes=preg_split('/\s+/',trim(html_entity_decode($customerCodesMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8')))?:[];
    if(count($customerRecoveryCodes)!==10)throw new RuntimeException('Customer MFA did not create ten recovery codes.');
    $customerMfaState=$pdo->prepare('SELECT secret_enc,last_used_step,enabled_at FROM customer_mfa WHERE user_id=:id');
    $customerMfaState->execute([':id'=>$a]);$customerMfaRow=$customerMfaState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($customerMfaRow)&&$customerMfaRow['enabled_at']!==null&&$customerMfaRow['secret_enc']!==$customerSecret
        &&(int)$pdo->query('SELECT COUNT(*) FROM customer_mfa_recovery_codes WHERE user_id='.(int)$a.' AND used_at IS NULL')->fetchColumn()===10,
        'customer MFA secret is encrypted at rest and ten recovery-code hashes are stored');
    $r=$request('/admin/security/mfa',$cookieA);
    $assert($r['status']===403,'customer cannot access staff MFA settings');
    $r=$request('/client/security/mfa',$cookieStaff);
    $assert($r['status']===403,'staff cannot access customer MFA settings');
    $request('/client/logout',$cookieA,'POST',['_token'=>$getToken($customerEnabled['body'])]);
    $customerLoginPage=$request('/client/login',$cookieA);
    $customerChallenge=$request('/client/login',$cookieA,'POST',[
        '_token'=>$getToken($customerLoginPage['body']),'email'=>'http-a@example.test','password'=>$password,
    ]);
    $assert($customerChallenge['status']===200&&str_contains($customerChallenge['body'],'Doğrulama kodu')
        &&!str_contains($customerChallenge['body'],'MÜŞTERİ ALANI'),
        'customer with MFA enabled must pass a second factor after the correct password');
    $customerTotpStep=intdiv(time(),30)+1;
    $customerLoginCode=\HostPilot\Core\Mfa::codeAt($customerSecret,$customerTotpStep*30);
    if(!is_string($customerLoginCode))throw new RuntimeException('Unable to generate customer login TOTP.');
    $customerLoginResult=$request('/client/login',$cookieA,'POST',[
        '_token'=>$getToken($customerChallenge['body']),'code'=>$customerLoginCode,
    ]);
    $assert($customerLoginResult['status']===200&&str_contains($customerLoginResult['body'],'MÜŞTERİ ALANI'),
        'customer login completes only after a valid TOTP challenge');
    $request('/client/logout',$cookieA,'POST',['_token'=>$getToken($customerLoginResult['body'])]);
    $customerReplayLogin=$request('/client/login',$cookieA);
    $customerReplayChallenge=$request('/client/login',$cookieA,'POST',[
        '_token'=>$getToken($customerReplayLogin['body']),'email'=>'http-a@example.test','password'=>$password,
    ]);
    $customerReplay=$request('/client/login',$cookieA,'POST',[
        '_token'=>$getToken($customerReplayChallenge['body']),'code'=>$customerLoginCode,
    ]);
    $assert(str_contains($customerReplay['body'],'daha önce kullanılmış')&&!str_contains($customerReplay['body'],'MÜŞTERİ ALANI'),
        'customer TOTP code cannot be replayed in a later login challenge');
    $customerMfaState->execute([':id'=>$a]);$customerReplayState=$customerMfaState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($customerReplayState)&&(int)$customerReplayState['last_used_step']===$customerTotpStep,
        'replayed customer TOTP does not advance the already-consumed authenticator time step');
    $customerRecoveryLogin=$request('/client/login',$cookieA,'POST',[
        '_token'=>$getToken($customerReplay['body']),'code'=>$customerRecoveryCodes[0],
    ]);
    $customerRecoveryHash=\HostPilot\Core\Mfa::recoveryCodeHash($customerRecoveryCodes[0]);
    $customerRecoveryUsed=$pdo->prepare('SELECT used_at FROM customer_mfa_recovery_codes WHERE user_id=:id AND code_hash=:hash');
    $customerRecoveryUsed->execute([':id'=>$a,':hash'=>$customerRecoveryHash]);
    $assert($customerRecoveryLogin['status']===200&&str_contains($customerRecoveryLogin['body'],'MÜŞTERİ ALANI')
        &&$customerRecoveryUsed->fetchColumn()!==null,
        'customer can recover access with a single-use code and that code is consumed');
    $customerSecurityPage=$request('/client/security/mfa',$cookieA);
    $customerDisable=$request('/client/security/mfa',$cookieA,'POST',[
        '_token'=>$getToken($customerSecurityPage['body']),'action'=>'disable','password'=>$password,'code'=>$customerRecoveryCodes[1],
    ]);
    $customerMfaState->execute([':id'=>$a]);
    $assert($customerDisable['status']===200&&str_contains($customerDisable['body'],'İki aşamalı doğrulama kapatıldı')
        &&$customerMfaState->fetch(PDO::FETCH_ASSOC)===false,
        'customer can disable MFA only with password plus an unused recovery code');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM security_audit_logs WHERE user_id=".(int)$a." AND event_key LIKE 'auth.customer_mfa_%'")->fetchColumn()>=4,
        'customer MFA enrollment, challenge and recovery actions are security-audited');
    $login('/admin/login','http-staff@example.test',$cookieStaff);
    $login('/admin/login','http-admin@example.test',$cookieAdmin);
    $login('/admin/login','http-super@example.test',$cookieSuper);
    $reportsPage=$request('/admin/reports',$cookieAdmin);
    $assert($reportsPage['status']===200&&str_contains($reportsPage['body'],'İşletme raporları')
        &&str_contains($reportsPage['body'],'Fatura ve tahsilat özeti')
        &&!str_contains($reportsPage['body'],'demo.musteri01@example.invalid'),
        'admin reports page renders real aggregate data without exposing demo customer identities');

    // A stale provider session is never auto-retried: it becomes review-only until an admin checks the provider.
    $staleAdminPage=$request('/admin/payment-transactions',$cookieAdmin);
    $staleRow=$pdo->prepare('SELECT status,response_code FROM payment_transactions WHERE id=:id');$staleRow->execute([':id'=>$stalePaymentId]);$staleState=$staleRow->fetch(PDO::FETCH_ASSOC);
    $assert(($staleState['status']??'')==='review' && ($staleState['response_code']??'')==='stale_pending_review'
        && str_contains($staleAdminPage['body'],'Tahsilat olmadığını doğrula ve kapat'),
        'payment sessions older than 30 minutes move to admin review instead of being retried automatically');
    $staleToken=$getToken($staleAdminPage['body']);
    $r=$request('/admin/payment-transactions/reconcile',$cookieAdmin,'POST',[
        '_token'=>$staleToken,'payment_transaction_id'=>$stalePaymentId,'decision'=>'no_capture_confirmed',
        'provider_reference'=>'CI-NO-CAPTURE-CHECK','reason'=>'CI verified no capture and no active session in provider dashboard',
        'password'=>$password,'confirm_external_action'=>'1'
    ]);
    $staleClosed=$pdo->prepare('SELECT status FROM payment_transactions WHERE id=:id');$staleClosed->execute([':id'=>$stalePaymentId]);
    $staleRecord=$pdo->prepare('SELECT decision,provider_reference FROM payment_reconciliations WHERE payment_transaction_id=:id');$staleRecord->execute([':id'=>$stalePaymentId]);$staleRecordRow=$staleRecord->fetch(PDO::FETCH_ASSOC);
    $assert(($staleClosed->fetchColumn()??'')==='cancelled' && ($staleRecordRow['decision']??'')==='no_capture_confirmed'
        && ($staleRecordRow['provider_reference']??'')==='CI-NO-CAPTURE-CHECK',
        'admin no-capture confirmation closes stale payment attempt and stores reconciliation evidence');
    $lateOid='HTTP-STALE-PENDING';$lateStatus='success';$lateAmount='10000';
    $lateHash=base64_encode(hash_hmac('sha256',$lateOid.'ci-merchant-salt'.$lateStatus.$lateAmount,'ci-merchant-key',true));
    $r=$request('/payment/paytr/callback',$cookieCron,'POST',['merchant_oid'=>$lateOid,'status'=>$lateStatus,'total_amount'=>$lateAmount,'hash'=>$lateHash]);
    $staleInvoiceState=$pdo->prepare('SELECT amount_paid,status FROM invoices WHERE id=:id');$staleInvoiceState->execute([':id'=>$staleInvoice]);$staleInvoiceRow=$staleInvoiceState->fetch(PDO::FETCH_ASSOC);
    $staleFinal=$pdo->prepare('SELECT status FROM payment_transactions WHERE id=:id');$staleFinal->execute([':id'=>$stalePaymentId]);
    $assert($r['status']===200 && $r['body']==='OK' && ($staleFinal->fetchColumn()??'')==='cancelled'
        && (float)($staleInvoiceRow['amount_paid']??-1)===0.0 && ($staleInvoiceRow['status']??'')==='unpaid',
        'late verified provider callback cannot settle an attempt explicitly closed after no-capture verification');

    // The same valid success notification can be delivered more than once by a gateway.
    $duplicateOid='HTTP-DUPLICATE-SUCCESS';$duplicateStatus='success';$duplicateAmount='10000';
    $duplicateHash=base64_encode(hash_hmac('sha256',$duplicateOid.'ci-merchant-salt'.$duplicateStatus.$duplicateAmount,'ci-merchant-key',true));
    $firstCallback=$request('/payment/paytr/callback',$cookieCron,'POST',['merchant_oid'=>$duplicateOid,'status'=>$duplicateStatus,'total_amount'=>$duplicateAmount,'hash'=>$duplicateHash]);
    $secondCallback=$request('/payment/paytr/callback',$cookieCron,'POST',['merchant_oid'=>$duplicateOid,'status'=>$duplicateStatus,'total_amount'=>$duplicateAmount,'hash'=>$duplicateHash]);
    $duplicateInvoiceState=$pdo->prepare('SELECT amount_paid,status FROM invoices WHERE id=:id');$duplicateInvoiceState->execute([':id'=>$duplicateInvoice]);$duplicateInvoiceRow=$duplicateInvoiceState->fetch(PDO::FETCH_ASSOC);
    $duplicateFinal=$pdo->prepare('SELECT status,gateway_transaction_id FROM payment_transactions WHERE id=:id');$duplicateFinal->execute([':id'=>$duplicatePaymentId]);$duplicateFinalRow=$duplicateFinal->fetch(PDO::FETCH_ASSOC);
    $assert($firstCallback['status']===200 && $firstCallback['body']==='OK' && $secondCallback['status']===200 && $secondCallback['body']==='OK'
        && (float)($duplicateInvoiceRow['amount_paid']??-1)===100.0 && ($duplicateInvoiceRow['status']??'')==='paid'
        && ($duplicateFinalRow['status']??'')==='success' && ($duplicateFinalRow['gateway_transaction_id']??'')===$duplicateOid,
        'replayed valid PayTR success callback is acknowledged twice but credits the invoice exactly once');

    // A signed PayTR success may report a collected amount different from the requested amount.
    // Preserve it for manual review and never credit the invoice with an unverified amount.
    $amountMismatchOid='HTTP-PAYTR-AMOUNT-MISMATCH';
    $pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'pending','TRY',100,:reference,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([':invoice'=>$invoiceA,':user'=>$a,':key'=>hash('sha256','http-paytr-amount-mismatch'),':reference'=>$amountMismatchOid]);
    $amountMismatchId=(int)$pdo->lastInsertId();
    $amountMismatchHash=base64_encode(hash_hmac('sha256',$amountMismatchOid.'ci-merchant-salt'.'success'.'10500','ci-merchant-key',true));
    $amountMismatchCallback=$request('/payment/paytr/callback',$cookieCron,'POST',[
        'merchant_oid'=>$amountMismatchOid,'status'=>'success','total_amount'=>'10500','currency'=>'TL','hash'=>$amountMismatchHash
    ]);
    $amountMismatchState=$pdo->prepare('SELECT status,response_code FROM payment_transactions WHERE id=:id');
    $amountMismatchState->execute([':id'=>$amountMismatchId]);$amountMismatchRow=$amountMismatchState->fetch(PDO::FETCH_ASSOC);
    $amountMismatchInvoice=$pdo->prepare('SELECT amount_paid,status FROM invoices WHERE id=:id');
    $amountMismatchInvoice->execute([':id'=>$invoiceA]);$amountMismatchInvoiceRow=$amountMismatchInvoice->fetch(PDO::FETCH_ASSOC);
    $assert($amountMismatchCallback['status']===200&&$amountMismatchCallback['body']==='OK'
        &&($amountMismatchRow['status']??'')==='review'&&($amountMismatchRow['response_code']??'')==='amount_mismatch_review'
        &&(float)($amountMismatchInvoiceRow['amount_paid']??-1)===0.0&&($amountMismatchInvoiceRow['status']??'')==='unpaid',
        'verified PayTR success with collected-amount mismatch is retained for review without invoice credit');

    $r=$request('/admin/payment-transactions/reconcile',$cookieA,'POST',[
        'payment_transaction_id'=>$reviewPaymentId,'decision'=>'refund_confirmed','provider_reference'=>'CI-CUSTOMER-FORBIDDEN',
        'reason'=>'customer must not reconcile','password'=>$password,'confirm_external_action'=>'1'
    ]);
    $assert($r['status']===403,'customer cannot reconcile reviewed payment over HTTP');
    $r=$request('/admin/payment-transactions/reconcile',$cookieStaff,'POST',[
        'payment_transaction_id'=>$reviewPaymentId,'decision'=>'refund_confirmed','provider_reference'=>'CI-STAFF-FORBIDDEN',
        'reason'=>'support staff must not reconcile','password'=>$password,'confirm_external_action'=>'1'
    ]);
    $assert($r['status']===403,'support staff cannot reconcile reviewed payment over HTTP');
    $reviewPage=$request('/admin/payment-transactions',$cookieAdmin);
    $reviewToken=$getToken($reviewPage['body']);
    $r=$request('/admin/payment-transactions/reconcile',$cookieAdmin,'POST',[
        '_token'=>$reviewToken,'payment_transaction_id'=>$reviewPaymentId,'decision'=>'refund_confirmed',
        'provider_reference'=>'CI-PROVIDER-REFUND-100','reason'=>'CI verified full refund in provider dashboard',
        'password'=>$password,'confirm_external_action'=>'1'
    ]);
    $reviewStatus=$pdo->prepare('SELECT status FROM payment_transactions WHERE id=:id');$reviewStatus->execute([':id'=>$reviewPaymentId]);
    $reviewRefund=$pdo->prepare("SELECT decision,provider_reference,amount FROM payment_reconciliations WHERE payment_transaction_id=:id");$reviewRefund->execute([':id'=>$reviewPaymentId]);$reviewRefundRow=$reviewRefund->fetch(PDO::FETCH_ASSOC);
    $reviewInvoice=$pdo->prepare('SELECT amount_paid,status FROM invoices WHERE id=:id');$reviewInvoice->execute([':id'=>$invoiceB]);$reviewInvoiceRow=$reviewInvoice->fetch(PDO::FETCH_ASSOC);
    $refundAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='payment.manual_refund_confirmed' ORDER BY id DESC LIMIT 1");$refundAudit->execute([':actor'=>$admin]);$refundAuditMetadata=json_decode((string)$refundAudit->fetchColumn(),true);
    $assert(($reviewStatus->fetchColumn()??'')==='refunded'
        && ($reviewRefundRow['decision']??'')==='refund_confirmed'
        && ($reviewRefundRow['provider_reference']??'')==='CI-PROVIDER-REFUND-100'
        && (float)($reviewRefundRow['amount']??0)===100.0
        && (float)($reviewInvoiceRow['amount_paid']??-1)===0.0
        && ($reviewInvoiceRow['status']??'')==='unpaid'
        && ($refundAuditMetadata['action']??'')==='refund_confirmed',
        'admin can record a verified external refund for an uncredited review capture without changing invoice balance');

    $pdo->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,response_code,safe_message,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'review','TRY',100,'HTTP-B-REVIEW-APPLY','overpayment_review','Provider payment awaiting manual confirmation',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([':invoice'=>$invoiceB,':user'=>$b,':key'=>hash('sha256','http-b-review-payment-apply')]);
    $reviewApplyId=(int)$pdo->lastInsertId();
    $reviewPage=$request('/admin/payment-transactions',$cookieAdmin);
    $reviewToken=$getToken($reviewPage['body']);
    $r=$request('/admin/payment-transactions/reconcile',$cookieAdmin,'POST',[
        '_token'=>$reviewToken,'payment_transaction_id'=>$reviewApplyId,'decision'=>'payment_confirmed',
        'provider_reference'=>'CI-PROVIDER-PAYMENT-100','reason'=>'CI verified payment capture in provider dashboard',
        'password'=>$password,'confirm_external_action'=>'1'
    ]);
    $applyStatus=$pdo->prepare('SELECT status FROM payment_transactions WHERE id=:id');$applyStatus->execute([':id'=>$reviewApplyId]);
    $applyInvoice=$pdo->prepare('SELECT amount_paid,status FROM invoices WHERE id=:id');$applyInvoice->execute([':id'=>$invoiceB]);$applyInvoiceRow=$applyInvoice->fetch(PDO::FETCH_ASSOC);
    $applyRecord=$pdo->prepare("SELECT decision,provider_reference,amount FROM payment_reconciliations WHERE payment_transaction_id=:id");$applyRecord->execute([':id'=>$reviewApplyId]);$applyRecordRow=$applyRecord->fetch(PDO::FETCH_ASSOC);
    $applyAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='payment.transaction_reconciled' ORDER BY id DESC LIMIT 1");$applyAudit->execute([':actor'=>$admin]);$applyAuditMetadata=json_decode((string)$applyAudit->fetchColumn(),true);
    $assert(($applyStatus->fetchColumn()??'')==='success'
        && ($applyRecordRow['decision']??'')==='payment_confirmed'
        && ($applyRecordRow['provider_reference']??'')==='CI-PROVIDER-PAYMENT-100'
        && (float)($applyInvoiceRow['amount_paid']??0)===100.0
        && ($applyInvoiceRow['status']??'')==='paid'
        && ($applyAuditMetadata['action']??'')==='payment_confirmed',
        'admin can reconcile a provider-confirmed capture through the normal invoice settlement workflow');

    $r=$request('/admin/database-restore',$cookieAdmin);
    $assert($r['status']===403,'ordinary admin cannot access destructive database restore screen');
    $r=$request('/admin/database-restore',$cookieSuper);
    $assert($r['status']===200 && str_contains($r['body'],'Veritabanı yedeğini geri yükle'),'super admin can access verified database restore screen');

    $paymentSettingsForm=$request('/admin/payments',$cookieAdmin);
    $paymentSettingsToken=$getToken($paymentSettingsForm['body']);
    $r=$request('/admin/payments',$cookieAdmin,'POST',[
        '_token'=>$paymentSettingsToken,'paytr_enabled'=>'1','paytr_test_mode'=>'1','paytr_merchant_id'=>'ci-merchant',
        'iyzico_enabled'=>'0','iyzico_test_mode'=>'1'
    ]);
    $paymentAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='payment.gateway_settings_updated' ORDER BY id DESC LIMIT 1");
    $paymentAudit->execute([':actor'=>$admin]);$paymentAuditMetadata=json_decode((string)$paymentAudit->fetchColumn(),true);
    $assert($r['status']===200 && ($paymentAuditMetadata['action']??'')==='updated'
        && !str_contains(json_encode($paymentAuditMetadata),'ci-merchant'),
        'payment gateway setting changes are audited without credentials or merchant identifiers');

    $serverForm=$request('/admin/servers',$cookieAdmin);
    $serverToken=$getToken($serverForm['body']);
    $r=$request('/admin/servers/save',$cookieAdmin,'POST',[
        '_token'=>$serverToken,'name'=>'CI Audit Server','panel_type'=>'cpanel','base_url'=>'https://cpanel.example.com:2087',
        'username'=>'ci-admin','secret'=>'ci-server-secret','auth_mode'=>'password'
    ]);
    $auditServer=$pdo->prepare("SELECT id FROM servers WHERE name='CI Audit Server' ORDER BY id DESC LIMIT 1");
    $auditServer->execute();$auditServerId=(int)$auditServer->fetchColumn();
    $serverAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='server.created' ORDER BY id DESC LIMIT 1");
    $serverAudit->execute([':actor'=>$admin]);$serverAuditMetadata=json_decode((string)$serverAudit->fetchColumn(),true);
    $assert($r['status']===200 && $auditServerId>0 && (int)($serverAuditMetadata['resource_id']??0)===$auditServerId
        && ($serverAuditMetadata['action']??'')==='created' && !str_contains(json_encode($serverAuditMetadata),'ci-server-secret'),
        'server creation is audited with a resource id and without the server secret');

    $domainSettingsForm=$request('/admin/domains/registrar',$cookieAdmin);
    $domainSettingsToken=$getToken($domainSettingsForm['body']);
    $r=$request('/admin/domains/registrar',$cookieAdmin,'POST',[
        '_token'=>$domainSettingsToken,'enabled'=>'1','environment'=>'sandbox','user_id'=>'123456','api_key'=>'ci-domain-api-key'
    ]);
    $domainAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='domain.registrar_settings_updated' ORDER BY id DESC LIMIT 1");
    $domainAudit->execute([':actor'=>$admin]);$domainAuditMetadata=json_decode((string)$domainAudit->fetchColumn(),true);
    $assert($r['status']===200 && ($domainAuditMetadata['action']??'')==='updated'
        && !str_contains(json_encode($domainAuditMetadata),'ci-domain-api-key'),
        'registrar settings changes are audited without API credentials');

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
    $permissionAudit=$pdo->prepare("SELECT metadata_json FROM security_audit_logs WHERE user_id=:actor AND event_key='staff.permissions_updated' ORDER BY id DESC LIMIT 1");
    $permissionAudit->execute([':actor'=>$admin]);$permissionAuditMetadata=json_decode((string)$permissionAudit->fetchColumn(),true);
    $assert($r['status']===200 && count($savedPermissionList)===count($assignablePermissions)
        && count(array_intersect($assignablePermissions,$savedPermissionList))===count($assignablePermissions)
        && (int)($permissionAuditMetadata['target_user_id']??0)===$financeStaff,
        'admin can assign the complete safe staff catalog and permission changes are audited; status='.$r['status'].'; permissions='.json_encode($savedPermissionList).' ; metadata='.json_encode($permissionAuditMetadata));
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
    $hasActivePaymentNotice=str_contains($paymentForm['body'],'Bu fatura için bir ödeme işlemi bekliyor')
        ||str_contains($paymentForm['body'],'Bu ödeme işlemi manuel incelemede');
    $assert($paymentForm['status']===200 && $hasActivePaymentNotice
        && !str_contains($paymentForm['body'],'<form method="post">'),
        'customer payment page explains pending/review state and suppresses another payment form');
    $paymentSecurityPage=$request('/client/security/mfa',$cookieA);
    $paymentToken=$getToken($paymentSecurityPage['body']);
    $beforePaymentAttempts=(int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE invoice_id=".$invoiceA)->fetchColumn();
    $r=$request('/client/invoices/'.$invoiceA.'/pay',$cookieA,'POST',[
        '_token'=>$paymentToken,'gateway'=>'paytr','phone'=>'+905551112233','address'=>'Test address','city'=>'Ankara','zip'=>'06000'
    ]);
    $afterPaymentAttempts=(int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE invoice_id=".$invoiceA)->fetchColumn();
    $assert($r['status']===409 && (str_contains($r['body'],'Bu fatura için bir ödeme işlemi bekliyor')||str_contains($r['body'],'manuel incelemede')) && $afterPaymentAttempts===$beforePaymentAttempts,
        'customer cannot create a second gateway attempt while an invoice payment is pending or under review');

    $pdo->prepare("UPDATE payment_transactions SET status='pending',created_at=UTC_TIMESTAMP()+INTERVAL 1 HOUR WHERE invoice_id=:invoice AND status IN ('pending','review')")->execute([':invoice'=>$invoiceA]);
    $invoiceListA=$request('/client/invoices',$cookieA);
    $assert($invoiceListA['status']===200 && str_contains($invoiceListA['body'],'Ödeme bekliyor')
        && !str_contains($invoiceListA['body'],'/client/invoices/'.$invoiceA.'/pay'),
        'customer invoice list shows active payment state and suppresses duplicate pay action; status='.$invoiceListA['status'].'; body='.substr(strip_tags(preg_replace('/<style\\b[^>]*>.*?<\\/style>/s','',$invoiceListA['body'])),0,1800));

    $invoiceDetailA=$request('/client/invoices/'.$invoiceA,$cookieA);
    $assert($invoiceDetailA['status']===200 && str_contains($invoiceDetailA['body'],'HTTP-A-INV')
        && str_contains($invoiceDetailA['body'],'Customer A hosting plan') && str_contains($invoiceDetailA['body'],'PayTR')
        && str_contains($invoiceDetailA['body'],'Bekliyor') && str_contains($invoiceDetailA['body'],'Yazdır / PDF kaydet')
        && str_contains($invoiceDetailA['body'],'Ödeme işlemi: Bekliyor')
        && str_contains($invoiceDetailA['body'],'Durumu yenile')
        && !str_contains($invoiceDetailA['body'],'/client/invoices/'.$invoiceA.'/pay'),
        'customer invoice detail shows pending state, safe refresh and suppresses another payment action');

    $pdo->prepare("UPDATE payment_transactions SET status='review' WHERE invoice_id=:invoice AND status='pending'")->execute([':invoice'=>$invoiceA]);
    $invoiceReviewA=$request('/client/invoices/'.$invoiceA,$cookieA);
    $assert($invoiceReviewA['status']===200 && str_contains($invoiceReviewA['body'],'Ödeme işlemi: İncelemede')
        && str_contains($invoiceReviewA['body'],'yeni ödeme başlatılamaz')
        && !str_contains($invoiceReviewA['body'],'/client/invoices/'.$invoiceA.'/pay'),
        'customer invoice detail clearly shows manual review state without exposing internal provider diagnostics');
    $invoiceDetailB=$request('/client/invoices/'.$invoiceB,$cookieA);
    $assert($invoiceDetailB['status']===404 && !str_contains($invoiceDetailB['body'],'HTTP-B-INV')
        && !str_contains($invoiceDetailB['body'],'Customer B confidential line'),
        'customer A cannot read customer B invoice details or line items');
    $invoiceDetailC=$request('/client/invoices/'.$invoiceC,$cookieC);
    $assert($invoiceDetailC['status']===200 && str_contains($invoiceDetailC['body'],'HTTP-C-INV')
        && str_contains($invoiceDetailC['body'],'Customer C paid hosting plan') && str_contains($invoiceDetailC['body'],'Manuel ödeme')
        && str_contains($invoiceDetailC['body'],'Başarılı'),
        'paid customer invoice detail displays its payment history without exposing provider references');

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
    $assert($r['status']===200 && str_contains($r['body'],'HTTP-A-INV'),'finance staff can view payment transactions with payments.view');
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
        && str_contains($r['body'],'/admin/domains/orders') && str_contains($r['body'],'/admin/domains/renewals')
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
    foreach ([$cookieA,$cookieB,$cookieC,$cookieStaff,$cookieAdmin,$cookieFinance,$cookieCron,$serverLog] as $path) if (is_file($path)) @unlink($path);
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
