<?php
declare(strict_types=1);

namespace HostPilot\\Core;

use PDO;
use Throwable;

/**
 * Adds a clearly-labelled, repeatable sample hosting business dataset.
 * It never creates real server credentials or calls payment/registrar APIs.
 */
final class DemoSeeder
{
    public static function seed(PDO $db): void
    {
        $check = $db->prepare("SELECT setting_value FROM settings WHERE setting_group='demo' AND setting_key='seed_version' LIMIT 1");
        $check->execute();
        if ((string)$check->fetchColumn() === '1') {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $groups = [
                'paylasimli-hosting' => 'Paylaşımlı Hosting',
                'bayi-hosting' => 'Bayi Hosting',
                'vps-sunucu' => 'VPS Sunucular',
                'domain' => 'Domain Hizmetleri',
            ];
            $groupIds = [];
            $groupInsert = $db->prepare("INSERT IGNORE INTO product_groups(name,slug,description,status,sort_order,created_at,updated_at) VALUES(:name,:slug,:description,'active',:sort,:created,:updated)");
            foreach ($groups as $slug => $name) {
                $groupInsert->execute([':name'=>$name,':slug'=>$slug,':description'=>'HostPilot demo katalog kategorisi',':sort'=>count($groupIds),':created'=>$now,':updated'=>$now]);
                $q=$db->prepare('SELECT id FROM product_groups WHERE slug=:slug');$q->execute([':slug'=>$slug]);$groupIds[$slug]=(int)$q->fetchColumn();
            }

            $products = [
                ['baslangic-hosting','Başlangıç Hosting','paylasimli-hosting','hosting',89.90,'1 web sitesi · 5 GB NVMe · Ücretsiz SSL · Günlük yedekleme'],
                ['ekonomik-hosting','Ekonomik Hosting','paylasimli-hosting','hosting',129.90,'3 web sitesi · 10 GB NVMe · E-posta hesapları · Ücretsiz SSL'],
                ['profesyonel-hosting','Profesyonel Hosting','paylasimli-hosting','hosting',219.90,'Sınırsız web sitesi · 25 GB NVMe · LiteSpeed · Günlük yedekleme'],
                ['wordpress-pro','WordPress Pro','paylasimli-hosting','hosting',279.90,'WordPress için optimize edilmiş kaynaklar · Staging · Otomatik yedekleme'],
                ['bayi-starter','Bayi Hosting Starter','bayi-hosting','hosting',449.90,'20 cPanel hesabı · 40 GB NVMe · WHM erişimi'],
                ['bayi-business','Bayi Hosting Business','bayi-hosting','hosting',799.90,'50 cPanel hesabı · 100 GB NVMe · Ücretsiz SSL'],
                ['bayi-enterprise','Bayi Hosting Enterprise','bayi-hosting','hosting',1399.90,'100 cPanel hesabı · 200 GB NVMe · Öncelikli destek'],
                ['vps-2gb','VPS 2 GB','vps-sunucu','vps',349.90,'2 vCPU · 2 GB RAM · 40 GB NVMe · Root erişimi'],
                ['vps-4gb','VPS 4 GB','vps-sunucu','vps',599.90,'4 vCPU · 4 GB RAM · 80 GB NVMe · Root erişimi'],
                ['vps-8gb','VPS 8 GB','vps-sunucu','vps',999.90,'6 vCPU · 8 GB RAM · 160 GB NVMe · Root erişimi'],
                ['com-domain','.com Domain','domain','domain',499.90,'Bir yıllık .com alan adı kayıt hizmeti. Demo katalog fiyatıdır.'],
                ['com-tr-domain','.com.tr Domain','domain','domain',349.90,'Bir yıllık .com.tr alan adı kayıt hizmeti. Demo katalog fiyatıdır.'],
            ];
            $productInsert=$db->prepare("INSERT IGNORE INTO products(group_id,name,slug,description,product_type,status,featured,sort_order,created_at,updated_at) VALUES(:group_id,:name,:slug,:description,:type,'active',:featured,:sort,:created,:updated)");
            $priceInsert=$db->prepare("INSERT IGNORE INTO product_pricing(product_id,currency,billing_cycle,price,setup_fee,is_active,created_at,updated_at) VALUES(:product,'TRY',:cycle,:price,0,1,:created,:updated)");
            $productRows=[];
            foreach($products as $i=>$p){
                [$slug,$name,$group,$type,$monthly,$description]=$p;
                $productInsert->execute([':group_id'=>$groupIds[$group],':name'=>$name,':slug'=>$slug,':description'=>$description,':type'=>$type,':featured'=>$i%4===0?1:0,':sort'=>$i,':created'=>$now,':updated'=>$now]);
                $q=$db->prepare('SELECT id FROM products WHERE slug=:slug');$q->execute([':slug'=>$slug]);$id=(int)$q->fetchColumn();
                $priceInsert->execute([':product'=>$id,':cycle'=>'monthly',':price'=>$monthly,':created'=>$now,':updated'=>$now]);
                $priceInsert->execute([':product'=>$id,':cycle'=>'annually',':price'=>round($monthly*10,2),':created'=>$now,':updated'=>$now]);
                $productRows[]=['id'=>$id,'slug'=>$slug,'name'=>$name,'type'=>$type,'price'=>$monthly];
            }

            $firstNames=['Ahmet','Mehmet','Ayşe','Fatma','Emre','Zeynep','Can','Elif','Burak','Deniz','Mert','Ece','Kerem','Derya','Onur','Selin','Yusuf','İrem','Berk','Ceren','Murat','Buse','Arda','Seda','Okan','Aslı','Tolga','Melis','Kaan','Gizem'];
            $lastNames=['Yılmaz','Kaya','Demir','Çelik','Şahin','Yıldız','Aydın','Öztürk','Arslan','Doğan','Kılıç','Aslan','Çetin','Koç','Kurt'];
            $userInsert=$db->prepare("INSERT IGNORE INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:name,:email,:username,:hash,'customer','active',:created,:updated)");
            $userIds=[];
            foreach($firstNames as $i=>$first){
                $n=$i+1;$email=sprintf('demo.musteri%02d@example.invalid',$n);$username=sprintf('demo_musteri%02d',$n);
                $created=gmdate('Y-m-d H:i:s',strtotime('-'.(45+($n*3)%300).' days'));
                $userInsert->execute([':name'=>$first.' '.$lastNames[$i%count($lastNames)],':email'=>$email,':username'=>$username,':hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),':created'=>$created,':updated'=>$now]);
                $q=$db->prepare('SELECT id FROM users WHERE email=:email');$q->execute([':email'=>$email]);$userIds[]=(int)$q->fetchColumn();
            }

            $orderInsert=$db->prepare("INSERT IGNORE INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,notes,created_at,updated_at) VALUES(:number,:user,:status,'TRY',:subtotal,0,:total,:notes,:created,:updated)");
            $itemInsert=$db->prepare("INSERT INTO order_items(order_id,product_id,product_name,billing_cycle,quantity,unit_price,line_total,created_at) VALUES(:order,:product,:name,:cycle,1,:price,:total,:created)");
            $invoiceInsert=$db->prepare("INSERT IGNORE INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,paid_at,created_at,updated_at) VALUES(:number,:order,:user,:status,'TRY',:subtotal,0,:total,:paid,:issued,:due,:paid_at,:created,:updated)");
            $invoiceItem=$db->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,0,0,:total,:created)");
            $serviceInsert=$db->prepare("INSERT INTO services(user_id,product_id,order_id,domain,status,billing_cycle,amount,next_due_date,created_at,updated_at,provisioning_status) VALUES(:user,:product,:order,:domain,:status,:cycle,:amount,:due,:created,:updated,'demo')");
            $orders=[];
            for($i=1;$i<=42;$i++){
                $p=$productRows[($i*5+2)%count($productRows)];$user=$userIds[($i*7-1)%count($userIds)];
                $days=($i*11)%180;$created=gmdate('Y-m-d H:i:s',strtotime('-'.$days.' days'));
                $cycle=$i%5===0?'annually':'monthly';$total=$cycle==='annually'?round($p['price']*10,2):$p['price'];
                $status=$i%11===0?'cancelled':($i%7===0?'pending':'completed');
                $number=sprintf('DEMO-ORD-2026-%04d',$i);
                $orderInsert->execute([':number'=>$number,':user'=>$user,':status'=>$status,':subtotal'=>$total,':total'=>$total,':notes'=>'DEMO KAYDI — gerçek sipariş veya tahsilat değildir.',':created'=>$created,':updated'=>$created]);
                $q=$db->prepare('SELECT id FROM orders WHERE order_number=:number');$q->execute([':number'=>$number]);$orderId=(int)$q->fetchColumn();
                $exists=$db->prepare('SELECT id FROM order_items WHERE order_id=:order LIMIT 1');$exists->execute([':order'=>$orderId]);
                if(!$exists->fetchColumn())$itemInsert->execute([':order'=>$orderId,':product'=>$p['id'],':name'=>$p['name'],':cycle'=>$cycle,':price'=>$total,':total'=>$total,':created'=>$created]);
                $invoiceStatus=$i%9===0?'overdue':($i%6===0?'unpaid':'paid');$paid=$invoiceStatus==='paid'?$total:0;
                $due=gmdate('Y-m-d H:i:s',strtotime($created.' +14 days'));$paidAt=$invoiceStatus==='paid'?$created:null;
                $invoiceInsert->execute([':number'=>sprintf('DEMO-FTR-2026-%04d',$i),':order'=>$orderId,':user'=>$user,':status'=>$invoiceStatus,':subtotal'=>$total,':total'=>$total,':paid'=>$paid,':issued'=>$created,':due'=>$due,':paid_at'=>$paidAt,':created'=>$created,':updated'=>$created]);
                $q=$db->prepare('SELECT id FROM invoices WHERE order_id=:order');$q->execute([':order'=>$orderId]);$invoiceId=(int)$q->fetchColumn();
                $exists=$db->prepare('SELECT id FROM invoice_items WHERE invoice_id=:invoice LIMIT 1');$exists->execute([':invoice'=>$invoiceId]);
                if(!$exists->fetchColumn())$invoiceItem->execute([':invoice'=>$invoiceId,':description'=>'DEMO · '.$p['name'],':price'=>$total,':total'=>$total,':created'=>$created]);
                if($invoiceStatus==='paid'){
                    $pay=$db->prepare("INSERT IGNORE INTO payment_transactions(invoice_id,user_id,gateway,gateway_transaction_id,idempotency_key,status,currency,amount,request_reference,response_code,safe_message,created_at,updated_at) VALUES(:invoice,:user,'demo',NULL,:key,'demo','TRY',:amount,:reference,'DEMO','DEMO KAYDI — gerçek ödeme yapılmamıştır.',:created,:updated)");
                    $pay->execute([':invoice'=>$invoiceId,':user'=>$user,':key'=>hash('sha256','hostpilot-demo-payment-'.$i),':amount'=>$total,':reference'=>$number,':created'=>$created,':updated'=>$created]);
                }
                $orders[$i]=['id'=>$orderId,'invoice'=>$invoiceId,'user'=>$user,'product'=>$p,'cycle'=>$cycle,'total'=>$total,'created'=>$created];
            }

            $serviceNo=0;
            foreach($orders as $i=>$o){
                if($o['product']['type']==='domain'||$i%4===0)continue;
                $serviceNo++;if($serviceNo>30)break;
                $status=$serviceNo%10===0?'suspended':($serviceNo%8===0?'pending':'active');
                $domain=sprintf('demo-site-%02d.example.invalid',$serviceNo);
                $created=$o['created'];$due=gmdate('Y-m-d',strtotime($created.' +'.(25+($serviceNo%5)*5).' days'));
                $exists=$db->prepare('SELECT id FROM services WHERE order_id=:order AND product_id=:product LIMIT 1');$exists->execute([':order'=>$o['id'],':product'=>$o['product']['id']]);
                if(!$exists->fetchColumn())$serviceInsert->execute([':user'=>$o['user'],':product'=>$o['product']['id'],':order'=>$o['id'],':domain'=>$domain,':status'=>$status,':cycle'=>$o['cycle'],':amount'=>$o['total'],':due'=>$due,':created'=>$created,':updated'=>$now]);
            }

            $domainInsert=$db->prepare("INSERT IGNORE INTO domain_registrations(user_id,order_id,invoice_id,domain_name,years,registrar_payload_enc,idempotency_key,status,action_status,action_message,attempts,available_at,registered_at,expiry_date,created_at,updated_at) VALUES(:user,:order,:invoice,:domain,1,'DEMO_ONLY_NO_REGISTRAR_CALL',:key,'demo','demo','DEMO KAYDI — registrar API çağrısı yapılmadı.',0,:available,:registered,:expiry,:created,:updated)");
            $domainNo=0;
            foreach($orders as $i=>$o){
                if($o['product']['type']!=='domain')continue;
                $domainNo++;$domain=sprintf('ornekfirma%02d.example.invalid',$domainNo);$created=$o['created'];
                $registered=gmdate('Y-m-d H:i:s',strtotime($created.' +1 day'));$expiry=gmdate('Y-m-d H:i:s',strtotime($registered.' +1 year'));
                $domainInsert->execute([':user'=>$o['user'],':order'=>$o['id'],':invoice'=>$o['invoice'],':domain'=>$domain,':key'=>hash('sha256','hostpilot-demo-domain-'.$domain),':available'=>$created,':registered'=>$registered,':expiry'=>$expiry,':created'=>$created,':updated'=>$now]);
            }

            $staff=(int)$db->query("SELECT id FROM users WHERE role IN ('super_admin','admin') ORDER BY id LIMIT 1")->fetchColumn();
            $ticketInsert=$db->prepare("INSERT IGNORE INTO tickets(ticket_number,user_id,department,subject,priority,status,last_reply_at,created_at,updated_at) VALUES(:number,:user,:department,:subject,:priority,:status,:reply,:created,:updated)");
            $messageInsert=$db->prepare("INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:ticket,:user,:message,:staff,:created)");
            $ticketSubjects=['WordPress sitemi nasıl taşıyabilirim?','SSL sertifikası kurulumu hakkında','VPS kaynak yükseltme talebi','Faturam hakkında bilgi','Alan adı DNS ayarları','E-posta hesabı oluşturma','Yedek geri yükleme talebi','Hosting kontrol paneli erişimi','Paket yenileme hakkında','Web sitemin performansı'];
            foreach($ticketSubjects as $i=>$subject){
                $n=$i+1;$user=$userIds[($i*3)%count($userIds)];$created=gmdate('Y-m-d H:i:s',strtotime('-'.($i*4+1).' days'));
                $ticketInsert->execute([':number'=>sprintf('DEMO-TKT-%04d',$n),':user'=>$user,':department'=>$i%3===0?'Billing':($i%3===1?'Technical Support':'General Support'),':subject'=>$subject,':priority'=>$i%4===0?'high':'normal',':status'=>$i%3===0?'open':($i%3===1?'staff_reply':'closed'),':reply'=>$created,':created'=>$created,':updated'=>$created]);
                $q=$db->prepare('SELECT id FROM tickets WHERE ticket_number=:number');$q->execute([':number'=>sprintf('DEMO-TKT-%04d',$n)]);$ticketId=(int)$q->fetchColumn();
                $exists=$db->prepare('SELECT id FROM ticket_messages WHERE ticket_id=:ticket LIMIT 1');$exists->execute([':ticket'=>$ticketId]);
                if(!$exists->fetchColumn())$messageInsert->execute([':ticket'=>$ticketId,':user'=>$user,':message'=>'[DEMO] Merhaba, bu örnek destek kaydıdır. Gerçek bir destek talebi değildir.',':staff'=>0,':created'=>$created]);
                if($staff>0&&$i%3===1)$messageInsert->execute([':ticket'=>$ticketId,':user'=>$staff,':message'=>'[DEMO] Talebiniz incelendi. Bu örnek yanıt gerçek müşteri iletişimi değildir.',':staff'=>1,':created'=>gmdate('Y-m-d H:i:s',strtotime($created.' +2 hours'))]);
            }

            $articles=[
                ['hosting-baslangic-rehberi','Hosting hesabınıza başlarken','Hosting','Kontrol paneline giriş, alan adı yönlendirme ve ilk web sitesi kurulum adımları.','Demo makalesi: Hosting hizmetiniz etkinleştirildikten sonra panel giriş bilgileri müşteri alanında görüntülenir. Alan adınızın DNS kayıtlarını hizmet sağlayıcınızın verdiği nameserver adreslerine yönlendirin.'],
                ['wordpress-kurulumu','WordPress nasıl kurulur?','Web Sitesi','WordPress kurulumunun temel adımları.','Demo makalesi: Hosting kontrol panelinizdeki uygulama kurucusunu açın, WordPress seçin, alan adınızı ve yönetici bilgilerinizi girerek kurulumu başlatın.'],
                ['ssl-sertifikasi','Ücretsiz SSL sertifikası','Güvenlik','SSL sertifikası ve HTTPS kullanımı hakkında.','Demo makalesi: Alan adınız sunucuya yönlendikten sonra SSL aracından sertifika talep edin. HTTPS yönlendirmesini etkinleştirmeden önce sitenizin çalıştığını kontrol edin.'],
                ['dns-nameserver','DNS ve nameserver ayarları','Domain','Alan adı DNS kayıtlarını güncelleme.','Demo makalesi: Nameserver değişiklikleri registrar tarafında kaydedilir ve yayılım süresi DNS önbelleklerine göre değişebilir.'],
                ['e-posta-hesabi','Kurumsal e-posta hesabı oluşturma','E-posta','Alan adınıza bağlı e-posta hesabı açma.','Demo makalesi: Kontrol panelindeki E-posta Hesapları bölümünden adres ve parola belirleyerek yeni posta kutusu oluşturabilirsiniz.'],
                ['vps-yedekleme','VPS sunucuda yedekleme','Sunucu','VPS yedekleme stratejisi.','Demo makalesi: Düzenli dosya ve veritabanı yedekleri alın, yedekleri mümkünse farklı bir depolama alanında saklayın ve geri yükleme testi yapın.'],
                ['fatura-odeme','Fatura ve ödeme işlemleri','Faturalandırma','Faturalarınızı görüntüleme ve ödeme.','Demo makalesi: Müşteri panelindeki Faturalarım alanında ödeme bekleyen ve geçmiş faturalarınızı görüntüleyebilirsiniz.'],
                ['destek-talebi','Destek talebi oluşturma','Destek','Teknik destek ekibine ulaşma.','Demo makalesi: Müşteri panelinden ilgili departmanı seçerek konu ve açıklama ile destek talebi açabilirsiniz.'],
                ['site-tasima','Web sitesi taşıma kontrol listesi','Taşıma','Bir web sitesini yeni hosting hesabına taşıma.','Demo makalesi: Dosyaları ve veritabanını yedekleyin, yeni ortama aktarın, yapılandırma bilgilerini güncelleyin ve DNS geçişinden önce test edin.'],
                ['paket-yukseltme','Hosting paketi yükseltme','Hesap Yönetimi','Kaynak ihtiyacınıza göre paketinizi yükseltin.','Demo makalesi: Müşteri panelindeki hizmet detaylarından uygun paket değişikliği talebi oluşturabilirsiniz.'],
            ];
            $articleInsert=$db->prepare("INSERT IGNORE INTO knowledgebase_articles(title,slug,excerpt,content,category,status,sort_order,created_at,updated_at) VALUES(:title,:slug,:excerpt,:content,:category,'published',:sort,:created,:updated)");
            foreach($articles as $i=>$a)$articleInsert->execute([':title'=>$a[1],':slug'=>$a[0],':excerpt'=>$a[3],':content'=>$a[4],':category'=>$a[2],':sort'=>$i,':created'=>$now,':updated'=>$now]);

            $mark=$db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('demo','seed_version','1',0,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)");
            $mark->execute([':updated'=>$now]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[HostPilot DemoSeeder] '.$e->getMessage());
            throw $e;
        }
    }
}
