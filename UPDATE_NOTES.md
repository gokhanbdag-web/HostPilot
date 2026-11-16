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
