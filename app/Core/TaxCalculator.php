<?php
declare(strict_types=1);

namespace HostPilot\\Core;

use PDO;
use RuntimeException;

final class TaxCalculator
{
    public static function settings(PDO $db): array
    {
        $query = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_group='tax'");
        $query->execute();
        $values = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        $enabled = ($values['enabled'] ?? '0') === '1';
        $mode = in_array($values['mode'] ?? 'exclusive', ['exclusive', 'inclusive'], true) ? $values['mode'] : 'exclusive';
        $rate = self::rateBasisPoints($values['rate'] ?? '0');
        if (!$enabled) $rate = 0;
        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'rate_basis_points' => $rate,
            'rate' => number_format($rate / 100, 4, '.', ''),
            'label' => trim($values['label'] ?? 'KDV') ?: 'KDV',
        ];
    }

    public static function line(int $amountCents, array $settings): array
    {
        if ($amountCents < 0 || $amountCents > 999999999999) {
            throw new RuntimeException('Vergilendirilecek tutar geçersiz.');
        }
        $rate = !empty($settings['enabled']) ? max(0, min(10000, (int)($settings['rate_basis_points'] ?? 0))) : 0;
        $mode = ($settings['mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
        if ($rate === 0) {
            $net = $amountCents;
            $tax = 0;
            $total = $amountCents;
        } elseif ($mode === 'inclusive') {
            $denominator = 10000 + $rate;
            $net = intdiv(($amountCents * 10000) + intdiv($denominator, 2), $denominator);
            $tax = $amountCents - $net;
            $total = $amountCents;
        } else {
            $net = $amountCents;
            $tax = intdiv(($net * $rate) + 5000, 10000);
            $total = $net + $tax;
        }
        return [
            'subtotal_cents' => $net,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'tax_rate' => number_format($rate / 100, 4, '.', ''),
        ];
    }

    public static function totals(array $amountsInCents, array $settings): array
    {
        $lines = [];
        $subtotal = 0;
        $tax = 0;
        $total = 0;
        foreach ($amountsInCents as $amount) {
            $line = self::line((int)$amount, $settings);
            $lines[] = $line;
            $subtotal += $line['subtotal_cents'];
            $tax += $line['tax_cents'];
            $total += $line['total_cents'];
        }
        return [
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $total,
        ];
    }

    public static function toCents(mixed $amount): int
    {
        $value = trim((string)$amount);
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\\.([0-9]{1,2}))?$/', $value, $match)) {
            throw new RuntimeException('Para tutarı geçersiz.');
        }
        $fraction = str_pad($match[2] ?? '', 2, '0');
        return ((int)$match[1] * 100) + (int)$fraction;
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) throw new RuntimeException('Negatif para tutarı desteklenmiyor.');
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function rateBasisPoints(string $rate): int
    {
        $rate = trim(str_replace(',', '.', $rate));
        if (!preg_match('/^(?:0|[1-9][0-9]?|100)(?:\\.([0-9]{1,2}))?$/', $rate, $match)) {
            throw new RuntimeException('Vergi oranı 0 ile 100 arasında olmalıdır.');
        }
        $parts = explode('.', $rate, 2);
        $whole = (int)$parts[0];
        $fraction = isset($parts[1]) ? (int)str_pad($parts[1], 2, '0') : 0;
        return ($whole * 100) + $fraction;
    }
}
