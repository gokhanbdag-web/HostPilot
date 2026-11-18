# Payment statement import formats

HostPilot converts provider-native reports to the same internal statement movement model. Imports are file-based and work on ordinary PHP/MySQL shared hosting; they do not require Composer, SSH, SFTP credentials or background workers.

## PayTR

Upload a JSON response saved from PayTR's documented **Satış ve İade İşlem Dökümü Servisi** at /rapor/islem-dokumu. The official service returns JSON, not CSV. It supports a maximum three-day query period. The importer accepts a successful response with a transaction list in data, transactions or islemler (or a top-level JSON list).

Mapped fields:
- siparis_no → payment reference (matches the HostPilot PayTR merchant order reference)
- islem_tipi=S → capture; islem_tipi=I → refund
- islem_tutari → gross transaction/refund amount; provider fees and net payout are not imported as customer payment amounts
- para_birimi → ISO currency (TL becomes TRY)
- islem_tarihi → provider-local date; a date-only value is represented as 00:00 Europe/Istanbul and converted to UTC

The report includes masked card information. HostPilot intentionally ignores card fields and only stores the minimum reference, date, description, amount, currency and movement type.

## iyzico

Upload an iyzico SFTP CSV transaction, cut-off or settlement report. iyzico documents these report types as CSV and documents fields including transactionDate, transactionType, paymentTxId, paymentId, paidPrice and transactionCurrency.

Mapped fields:
- paymentId → capture reference
- paymentTxId (fallback refundConversationId) → refund reference
- transactionType=auth|postauth → capture; refund → refund
- preauth and cancel → unknown/non-cash movement; these are retained but never auto-matched as captures or refunds
- paidPrice (fallback price) → amount
- transactionCurrency → currency

The parser supports comma- and semicolon-delimited UTF-8 CSV and ignores non-transaction summary rows in cut-off/settlement exports. Provider timestamps without an explicit timezone are interpreted as Europe/Istanbul and converted to UTC. Buyer name/email, card data and other personal fields are not persisted.

## Bank CSV

Bank uploads continue to use the canonical format with the exact ordered header:

    date,reference,description,amount,currency,type
    2026-11-17 10:30:00,TX-100,Incoming transfer,125.50,TRY,capture
    2026-11-17 11:00:00,RF-100,Refund,25.00,TRY,refund

Dates must be UTC YYYY-MM-DD HH:MM:SS; amount uses a dot decimal separator; type is capture, refund or unknown.

## On-demand API retrieval

Administrators can optionally fetch reports from the same encrypted credentials already stored in Payment Settings. PayTR uses its official transaction-detail report endpoint and is limited to a maximum three-day range. iyzico uses its official daily transaction reporting API, including page-by-page retrieval, and is limited to seven days per action. The requests run only after an administrator submits the CSRF-protected form; no scheduled or background provider calls are made. The API response is imported through the same parser, duplicate-file check and suggestion-only matching flow. Test/live iyzico endpoint selection follows the saved payment setting.

## Safety and limitations

- Maximum file size is 2 MB and maximum imported movements are 5,000 per file.
- Duplicate files for the same source are rejected by SHA-256 idempotency.
- Matching uses provider references, currency and exact decimal amounts. Unmatched, ambiguous or mismatched rows remain in the review queue.
- Imports and review decisions do not change payment, refund, invoice or bank account balances and do not initiate refunds.
- PayTR API and iyzico daily reporting API retrieval are optional, on-demand actions initiated by an administrator. iyzico SFTP is not connected automatically; its CSV files remain browser-uploaded.
- Provider report layouts can evolve. Unsupported or missing required columns fail closed instead of guessing mappings.
