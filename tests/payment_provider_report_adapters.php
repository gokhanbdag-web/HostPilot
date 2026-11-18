<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/StatementImport.php';
use HostPilot\Payment\StatementImport;

$iyzico = "transactionDate,transactionType,paymentTxId,paymentId,conversationId,paymentPhase,price,paidPrice,transactionCurrency,settlementReferenceCode\n2026-11-17 10:30:00,postauth,9001,iyzico-pay-1,conv-1,AUTH,100.00,105.00,TRY,settle-1\n2026-11-17 11:00:00,refund,9002,iyzico-pay-1,conv-1,AUTH,25.00,105.00,TRY,settle-1\n2026-11-17 11:05:00,cancel,9003,iyzico-pay-2,conv-2,AUTH,10.00,10.00,TRY,settle-2\n";
$iyzicoRows = StatementImport::parseProviderFile('iyzico', $iyzico);
if (count($iyzicoRows)!==3 || $iyzicoRows[0]['reference']!=='iyzico-pay-1' || $iyzicoRows[0]['amount']!=='105.00' || $iyzicoRows[0]['type']!=='capture' || $iyzicoRows[1]['amount']!=='25.00' || $iyzicoRows[1]['type']!=='refund' || $iyzicoRows[2]['type']!=='unknown') throw new RuntimeException('iyzico transaction CSV adapter failed.');
$iyzicoSemicolon = "transactionDate;transactionType;paymentId;conversationId;price;paidPrice;transactionCurrency\n17.11.2026 12:30:00;postauth;iyzico-2;conv-2;10.00;10.00;TRY\n";
$iyzicoRows = StatementImport::parseProviderFile('iyzico', $iyzicoSemicolon);
if (count($iyzicoRows)!==1 || $iyzicoRows[0]['date']!=='2026-11-17 12:30:00') throw new RuntimeException('iyzico semicolon CSV adapter failed.');
$paytr = json_encode(['status'=>'success','transactions'=>[
 ['islem_tipi'=>'S','islem_tutari'=>'125.50','net_tutar'=>'122.50','islem_tarihi'=>'17.11.2026','para_birimi'=>'TL','siparis_no'=>'HPP-100'],
 ['islem_tipi'=>'I','islem_tutari'=>'25.00','net_tutar'=>'24.00','islem_tarihi'=>'17.11.2026','para_birimi'=>'TL','siparis_no'=>'HPP-100'],
]], JSON_THROW_ON_ERROR);
$paytrRows = StatementImport::parseProviderFile('paytr', $paytr);
if (count($paytrRows)!==2 || $paytrRows[0]['reference']!=='HPP-100' || $paytrRows[0]['amount']!=='125.50' || $paytrRows[0]['currency']!=='TRY' || $paytrRows[0]['type']!=='capture' || $paytrRows[1]['type']!=='refund') throw new RuntimeException('PayTR JSON adapter failed.');
$paytrCsv = "islem_tarihi,siparis_no,islem_tipi,islem_tutari,para_birimi\n17.11.2026,HPP-101,S,75.00,TL\n";
$paytrRows = StatementImport::parseProviderFile('paytr', $paytrCsv);
if (count($paytrRows)!==1 || $paytrRows[0]['date']!=='2026-11-17 00:00:00') throw new RuntimeException('PayTR CSV adapter failed.');
$badPaytr = false; try { StatementImport::parseProviderFile('paytr', '{"status":"error","err_msg":"bad"}'); } catch (RuntimeException) { $badPaytr = true; }
if (!$badPaytr) throw new RuntimeException('Unsuccessful PayTR report response was accepted.');
$badIyzico = false; try { StatementImport::parseProviderFile('iyzico', "transactionDate,transactionType,paymentId,price,paidPrice,transactionCurrency\n2026-11-17 12:00:00,refund,abc,,20.00,TRY\n"); } catch (RuntimeException) { $badIyzico = true; }
if (!$badIyzico) throw new RuntimeException('iyzico refund fell back to the original payment amount.');
echo "HostPilot provider report adapter tests passed.\n";
