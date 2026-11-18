# HostPilot Admin Navigation Audit

Audit baseline: current `main` route map and dashboard navigation, 18 November 2026.

This inventory distinguishes a working route from a complete business module. A page that loads but only lists records is not considered full CRUD or operational completion.

| Menu item | Current route/state | Assessment |
|---|---|---|
| Genel Bakış | `/admin` | Partial: real counts and customer-growth chart; financial and infrastructure telemetry are not complete. |
| Müşteriler | `/admin/customers` | Partial: searchable/paginated customer list; no complete customer detail, edit, status, notes or service overview workflow. |
| Ürün & Paketler | `/admin/products` | Partial: product management exists; richer pricing/options and package configuration remain. |
| Siparişler | `/admin/orders` | Partial: list view only; no full order detail/status/fulfilment management screen. |
| Faturalar | `/admin/invoices` | Partial: invoice overview list; no full administrator invoice detail/adjustment/credit workflow. |
| Ödeme Yöntemleri | `/admin/payments` | Working configuration for PayTR and iyzico; merchant sandbox/live acceptance still requires merchant credentials and testing. |
| Ödeme İşlemleri | `/admin/payment-transactions` | Working transaction history and reconciliation actions; provider refund API calls remain unsupported. |
| Finans Raporları | `/admin/finance` | Partial: financial movement/reporting and CSV export exist; broader accounting, tax, currency and credit-ledger features remain. |
| Sunucular | `/admin/servers` | Working server registry, connection test and package mapping; live server-side operation depends on valid credentials. |
| Hizmetler | `/admin/services` | Partial: service listing and provisioning/lifecycle actions exist; complete customer/service detail and remote-state query adapters remain. |
| Domainler | `/admin/domains` | Working ResellerClub settings page; domain operations are split into sub-pages and provider coverage is limited. |
| Domain Siparişleri | `/admin/domains/orders` | Working queue/reconciliation view. |
| Domain Yenilemeleri | `/admin/domains/renewals` | Working renewal queue/reconciliation view; additional registrar coverage remains. |
| Destek Talepleri | `/admin/tickets` | Working staff/admin ticket workflow, including detail, replies, assignment and internal notes. |
| Destek Departmanları | `/admin/ticket-departments` | Working department management. |
| Bilgi Bankası | `/admin/knowledgebase` | Working article CRUD and publishing workflow. |
| Site Sayfaları | `/admin/pages` | Working CMS page CRUD and publishing; not a visual page builder. |
| Menü Yönetimi | `/admin/menus` | Working header/footer menu management. |
| Duyurular | `/admin/announcements` | Working scheduled announcement management. |
| Raporlar | `/admin/reports` | Demo-only: currently aggregates only `DEMO-` sample records, not real business reporting. |
| Entegrasyonlar | `/admin/integrations` | Previously had no route; this change adds a central integration status page with links to real configuration screens. |
| Tema & Sayfa Editörü | `/admin/themes` | Previously had no route; this change adds a truthful CMS workspace. Drag-and-drop theme/page editor is still not implemented. |
| Ayarlar | `/admin/settings` | SMTP/email configuration only; rename menu to avoid implying a complete global settings module. |
| Güncelleme Merkezi | `/admin/updates` | Working update status/application workflow, subject to release and deployment constraints. |
| Veritabanı Güncellemeleri | `/admin/database-upgrade` | Working authenticated migration and backup workflow. |
| Personel Yetkileri | `/admin/staff` | Working scoped staff permission management. |
| Oturum Güvenliği | `/admin/security/sessions` | Working session security controls. |
| İki Aşamalı Doğrulama | `/admin/security/mfa` | Working TOTP MFA and recovery-code management. |
| Ek Dosya Güvenliği | `/admin/security/attachments` | Working optional ClamAV configuration; requires a separately installed local daemon. |
| Veritabanı Yedekleri | `/admin/database-restore` | Working super-admin backup verification and restore workflow. |

## Follow-up implementation order

1. Finish the newly added integration/theme workspace pages and ensure every visible menu destination has an explicit route and permission.
2. Replace demo-only reporting with real customer, order, invoice, service and support aggregates.
3. Expand customer and service administration from list views into safe detail/action workflows.
4. Complete invoice/order administration and financial/tax configuration.
5. Build the actual theme registry and visual page editor; do not label the CMS link collection as a visual editor.
6. Consolidate general settings, locale and business profile rather than leaving unrelated settings under a generic label.

## Definition of a completed menu page

A menu page is complete only when its route exists, the correct role/permission is enforced, data is persisted and tenant/ownership boundaries are checked, all mutations have CSRF and validation, failures are recoverable, actions are audited, and tests cover the expected behavior. A static placeholder is not a completed module.
