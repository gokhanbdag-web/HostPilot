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
