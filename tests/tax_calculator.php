<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Core/TaxCalculator.php';

use HostPilot\Core\TaxCalculator;

function expectTax(array $actual, int $subtotal, int $tax, int $total, string $rate): void
{
    if ($actual['subtotal_cents'] !== $subtotal || $actual['tax_cents'] !== $tax || $actual['total_cents'] !== $total || $actual['tax_rate'] !== $rate) {
        throw new RuntimeException('Unexpected tax line: '.json_encode($actual));
    }
}

$exclusive=['enabled'=>true,'mode'=>'exclusive','rate_basis_points'=>2000];
expectTax(TaxCalculator::line(10000,$exclusive),10000,2000,12000,'20.0000');
$inclusive=['enabled'=>true,'mode'=>'inclusive','rate_basis_points'=>2000];
expectTax(TaxCalculator::line(12000,$inclusive),10000,2000,12000,'20.0000');
$tenPercent=['enabled'=>true,'mode'=>'exclusive','rate_basis_points'=>1000];
expectTax(TaxCalculator::line(1000,$tenPercent),1000,100,1100,'10.0000');
expectTax(TaxCalculator::line(3,$tenPercent),3,0,3,'10.0000');
$disabled=['enabled'=>false,'mode'=>'exclusive','rate_basis_points'=>2000];
expectTax(TaxCalculator::line(10000,$disabled),10000,0,10000,'0.0000');
$totals=TaxCalculator::totals([10000,5000],$exclusive);
if($totals['subtotal_cents']!==15000||$totals['tax_cents']!==3000||$totals['total_cents']!==18000||count($totals['lines'])!==2)throw new RuntimeException('Multi-line tax totals are incorrect.');
if(TaxCalculator::toCents('123.4')!==12340||TaxCalculator::decimal(12340)!=='123.40')throw new RuntimeException('Money conversion is not cent-accurate.');
if(TaxCalculator::rateBasisPoints('20.5')!==2050||TaxCalculator::rateBasisPoints('0')!==0||TaxCalculator::rateBasisPoints('100')!==10000)throw new RuntimeException('Tax rate parsing is incorrect.');
foreach(['100.01','-1','abc','101'] as $invalid){$failed=false;try{TaxCalculator::rateBasisPoints($invalid);}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Invalid tax rate was accepted: '.$invalid);}
echo "HostPilot tax calculation tests passed.".PHP_EOL;
