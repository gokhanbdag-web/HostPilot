<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Http/PrivacyController.php';
use HostPilot\Http\PrivacyController;
$cases=['password_hash'=>false,'api_key'=>false,'secret_enc'=>false,'refresh_token'=>false,'gateway_transaction_id'=>true,'email'=>true,'domain'=>true,'created_at'=>true,'remote_password_enc'=>false,'session_id'=>false,'raw_response'=>false];
foreach($cases as $column=>$expected)if(PrivacyController::isSafeColumn($column)!==$expected)throw new RuntimeException('Export policy mismatch: '.$column);
if(!PrivacyController::validReviewStatus('under_review')||!PrivacyController::validReviewStatus('approved')||!PrivacyController::validReviewStatus('declined')||PrivacyController::validReviewStatus('completed')||PrivacyController::validReviewStatus('pending'))throw new RuntimeException('Review status allowlist failed.');
if(PrivacyController::exportTables()!==['orders','services','invoices','payment_transactions','tickets','ticket_messages','domain_registrations','domain_renewals'])throw new RuntimeException('Export table allowlist mismatch.');
echo "Privacy policy tests passed.\n";
