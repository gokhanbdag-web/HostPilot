# HostPilot güncelleme notları

## 2026-11-17 · 2026.11.17.03

- Yönetici, ödeme sağlayıcısı panelinde tamamlandığını doğruladığı kısmi iadeleri tutar, para birimi, sağlayıcı iade referansı ve açıklamayla ayrı kayıtlar halinde ekleyebilir.
- Her ödeme işlemi için birden fazla iade kaydı tutulur; yinelenen sağlayıcı referansı, sıfır/geçersiz tutar, para birimi uyuşmazlığı ve tahsilat tutarını aşan toplam iade reddedilir.
- Kısmi/tam iade kaydı, ödeme durumu, fatura tahsilat bakiyesi ve müşteri e-posta outbox bildirimi tek veritabanı işlemi içinde güncellenir.
- Fatura bakiyesi iade tutarı kadar geri açılır; ödeme ancak kümülatif iade tahsilatın tamamına ulaştığında tamamen iade edildi durumuna geçer.
- Yönetici ekranında iade geçmişi; müşteri fatura detayında yalnızca kendi iade tutarları ve tarihleri gösterilir.
- Bu sürüm sağlayıcıya otomatik iade isteği göndermez. Sağlayıcı panelinde tamamlanmış iadenin kaydını tutar; gerçek PayTR/iyzico iade API çağrıları ve banka mutabakatı ayrı geliştirme/test gerektirir.


## 2026-11-17 · 2026.11.17.02

- Yönetici, sağlayıcı panelinde tam iadenin tamamlandığını doğruladığında müşteriye iade bildirimi e-posta kuyruğuna eklenir.
- Sağlayıcıda tahsilat olmadığı doğrulanıp ödeme oturumu kapatıldığında müşteriye iptal bildirimi gönderilmek üzere kuyruğa eklenir.
- İade/iptal bildirimleri ödeme durumu ve outbox kaydı aynı veritabanı işlemi içinde tamamlanır; benzersiz işlem/durum anahtarı tekrar bildirimleri engeller.
- Müşteri mesajları yalnızca doğrulanmış tam iade veya tahsilat yok kararı için kullanılır; otomatik sağlayıcı iadesi başlatıldığı iddia edilmez.
- MySQL 8 ve MariaDB 10.11 üzerinde iade/iptal bildirim içeriği, tekilleştirme ve mutabakat akışı test edilir. Gerçek banka/sağlayıcı iade işlemi ve SMTP teslimatı CI kapsamı dışındadır.


## 2026-11-17 · 2026.11.17.01

- Ödeme oturumu başlatıldığında, sağlayıcı sonucu doğrulandığında, işlem manuel incelemeye alındığında veya başarısız olduğunda müşteriye Türkçe e-posta bildirimi kuyruğa eklenir.
- Bildirimler ödeme işlem kimliği ve durum geçişi bazında tekilleştirilir; yinelenen callback ve tekrar mutabakat aynı bildirimi çoğaltmaz.
- Ödeme durumu değişikliği ile e-posta outbox kaydı aynı veritabanı işlemi içinde yazılır; SMTP gönderimi mevcut kuyruk worker'ına bırakılır ve ödeme callback yanıtını bekletmez.
- Belirsiz SMTP worker zaman aşımı otomatik tekrar yerine manuel incelemede kalır; e-posta içeriğinde sağlayıcı hata ayrıntıları veya gizli bilgiler paylaşılmaz.
- CI, MySQL 8 ve MariaDB 10.11 üzerinde outbox tekilleştirmesini ve durum bildirimlerini test eder. Gerçek SMTP teslimatı bu testlerin kapsamı dışındadır.


## 2026-11-13

- Ana sayfa, geçici duyuru metinleri kaldırılarak önceki satış odaklı başlık ve açıklamasına döndürüldü.
- Yönetim panelindeki Güncelleme Merkezi'ne canlı güncelleme notları bölümü eklendi.
- Güncelleme notları, yönetici oturumu doğrulanan durum servisi üzerinden 5 saniyede bir yenilenir; sayfanın yeniden yüklenmesi gerekmez.
- Durum servisi önbelleğe alınmaz ve notlar `UPDATE_NOTES.md` dosyasından okunur.

Yeni sürümlerde bu dosyaya tarihli başlık altında kullanıcıya görünen değişiklikler eklenir.

## 2026-11-16 · 2026.11.16.11

- 30 dakikadan uzun süredir sonuçlanmayan ödeme oturumları otomatik olarak yeni tahsilata izin vermek yerine manuel incelemeye alınır.
- Yönetici, sağlayıcı panelinde tahsilat olmadığını doğrulayıp oturumu kapatabilir; bu karar referans, açıklama ve denetim kaydıyla saklanır.
- Manuel olarak kapatılmış ödeme oturumlarına geç gelen doğrulanmış sağlayıcı bildirimi faturaya tahsilat işleyemez.

## 2026-11-16 · 2026.11.16.12

- Müşteri faturaları artık ayrı detay sayfasında kalem, vergi, toplam, ödenen ve kalan tutarları gösterir.
- Fatura detayında yalnızca ilgili müşteriye ait ödeme geçmişi listelenir; sağlayıcı işlem referansları ve dahili hata mesajları gösterilmez.
- Fatura sayfasına tarayıcı yazdırma / PDF olarak kaydetme görünümü eklendi; bu özellik yasal e-fatura/e-arşiv belgesi üretimi değildir.
- Müşteri A'nın müşteri B faturasını görüntüleyemediğini doğrulayan HTTP IDOR testi eklendi.

## 2026-11-16 · 2026.11.16.13

- Müşteriler, hesap güvenliği sayfasından isteğe bağlı TOTP iki aşamalı doğrulamayı etkinleştirebilir veya kapatabilir.
- Müşteri girişinde MFA etkinse parola doğrulamasından sonra 5 dakika geçerli ikinci faktör adımı istenir.
- TOTP sırları uygulama anahtarıyla şifrelenir; 10 tek kullanımlık kurtarma kodu yalnızca SHA-256 özetiyle saklanır.
- Kurtarma kodları bir kez kullanılabilir; TOTP zaman adımı tekrar kullanımına karşı korunur. Kod yenileme ve MFA kapatma işlemleri parola ve ikinci faktör doğrulaması ister.
- MFA işlemleri güvenlik denetim kaydına alınır; MFA etkinleştirilmemiş müşteri hesaplarında parola ile giriş değişmeden devam eder.

## 2026-11-16 · 2026.11.16.14

- PayTR imzalı başarılı callback tutarı, beklenen tahsilat tutarından farklıysa artık başarısız sayılmaz; manuel incelemeye alınır.
- Tutar uyuşmazlığı olan doğrulanmış callback faturaya otomatik bakiye yazmaz; yönetici sağlayıcı panelinden gerçek tahsilatı ve olası iadeyi mutabakat ekranında değerlendirir.
- PayTR callback HMAC ve iyzico IYZWSv2 istek imzası için sabit protokol vektörleri CI testlerine eklendi.
- HTTP entegrasyon testine farklı tahsilat tutarlı imzalı PayTR başarı bildirimi senaryosu eklendi.
- Gerçek PayTR/iyzico sandbox kabulü merchant hesabı ve test kimlik bilgileri gerektirdiğinden bu sürümde doğrulanmış sayılmaz.

## 2026-11-16 · 2026.11.16.15

- iyzico Checkout Form retrieve yanıtı imza doğrulaması ve işlem/fatura eşleşmesi ayrı bir test edilebilir politika sınıfına taşındı.
- İmzalı SUCCESS yanıtında tutar, para birimi, sepet veya konuşma kimliği uyuşmazlığı artık otomatik başarısız sayılmıyor; manuel incelemeye alınıyor.
- Eksik/geçersiz imza ve tanınmayan risk durumu manuel incelemeye yönlendiriliyor; fraudStatus=0 risk incelemesinde bekliyor.
- Sabit HMAC vektörü ve başarı/başarısızlık, fraud ve uyuşmazlık senaryoları CI'ya eklendi.
- Gerçek iyzico sandbox çağrısı bu sürümde yapılmadı; testler sahte/sabit protokol yanıtları kullanır.

## 2026-11-16 · 2026.11.16.16

- PayTR iFrame token üretimi ve başlatma formu, ağ isteğinden bağımsız test edilebilir PaytrRequest sınıfına ayrıldı.
- Tutarın kuruş cinsinden gönderimi, TL para birimi, sepet JSON/Base64 biçimi, merchant token HMAC, sandbox bayrakları ve dönüş URL'leri için sabit sözleşme testleri eklendi.
- Sıfır tutar ve boş sepetle ödeme başlatılması engellendi.
- Testler sentetik merchant bilgileri kullanır; gerçek PayTR hesabına veya ödeme API'sine istek göndermez.

## 2026-11-16 · 2026.11.16.17

- Ödeme HTTP taşıma katmanı yalnızca PayTR token ve iyzico Checkout Form API HTTPS uç noktalarını kabul edecek şekilde sınırlandı.
- URL şeması, host, port, kimlik bilgileri, query/fragment ve iyzico imza yolu doğrulanıyor.
- cURL ve cURL bulunmayan PHP stream bağlantılarında HTTP yönlendirmeleri kapatıldı; TLS sertifika/hostname doğrulaması zorunlu tutuldu.
- Sağlayıcı yanıtları 1 MiB ile sınırlandı; her iki taşıma yolu da sınırı aşan yanıtları reddediyor.
- Yeni güvenlik politika testleri CI'ya eklendi; gerçek merchant/sandbox isteği gönderilmedi.

## 2026-11-16 · 2026.11.16.18

- PayTR/iyzico bağlantı zaman aşımı, bozuk HTTP yanıtı veya doğrulanamayan sağlayıcı yanıtı artık otomatik başarısız sayılmıyor; işlem manuel incelemeye alınıyor.
- Sağlayıcıdan açık başarısızlık yanıtı gelen başlatmalar başarısız olarak kalıyor; belirsiz sonuçlarda otomatik yeniden deneme veya yeni tahsilat açılmıyor.
- Sağlayıcı oturumu oluşturulmuş olabileceği halde form/token bilgisi kaydedilemezse işlem incelemeye alınıyor.
- Müşteri ödeme sayfası bekleyen ve incelemedeki işlemleri açıkça gösteriyor ve ikinci ödeme formunu göstermiyor.
- Yeni ödeme başlatma belirsizliği ve müşteri arayüzü politika testleri CI'ya eklendi; gerçek sağlayıcı isteği gönderilmedi.
