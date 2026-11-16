# HostPilot güncelleme notları

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
