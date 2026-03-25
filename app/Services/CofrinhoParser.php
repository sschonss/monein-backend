<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class CofrinhoParser
{
    private const MONTHS = [
        'jan' => '01', 'fev' => '02', 'mar' => '03',
        'abr' => '04', 'mai' => '05', 'jun' => '06',
        'jul' => '07', 'ago' => '08', 'set' => '09',
        'out' => '10', 'nov' => '11', 'dez' => '12',
    ];

    private const TYPE_MAP = [
        'Guardado' => 'deposit',
        'Resgatado' => 'withdrawal',
        'Rendimentos' => 'yield',
    ];

    public function parse(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        $text = '';
        foreach ($pdf->getPages() as $page) {
            $text .= $page->getText() . "\n";
        }

        return $this->parseText($text);
    }

    public function parseText(string $text): array
    {
        $lines = explode("\n", $text);

        $name = $this->extractName($lines);
        $movements = [];
        $currentDate = null;
        $currentBalance = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Match: "Data: DD/mmm/YYYY Saldo ao final do dia: R$ X.XXX,XX"
            if (preg_match('/^Data:\s+(\d{2})\/([\w]{3})\/(\d{4})\s+Saldo ao final do dia:\s+R\$\s*([\d.,]+)/u', $line, $m)) {
                $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
                if ($month) {
                    $currentDate = "{$m[3]}-{$month}-{$m[1]}";
                    $currentBalance = (float) str_replace(['.', ','], ['', '.'], $m[4]);
                }
                continue;
            }

            // Match movement: "Guardado +R$ X.XXX,XX" or "Resgatado -R$ X.XXX,XX" or "Rendimentos +R$ X,XX"
            if ($currentDate && preg_match('/^(Guardado|Resgatado|Rendimentos)\s+([+\x{2212}\-])R\$\s*([\d.,]+)/u', $line, $m)) {
                $type = self::TYPE_MAP[$m[1]] ?? null;
                if (!$type) continue;

                $amount = (float) str_replace(['.', ','], ['', '.'], $m[3]);
                if ($amount <= 0) continue;

                $movements[] = [
                    'date' => $currentDate,
                    'type' => $type,
                    'amount' => round($amount, 2),
                    'balance_after' => $currentBalance ? round($currentBalance, 2) : null,
                ];
            }
        }

        return [
            'name' => $name,
            'movements' => $movements,
        ];
    }

    private function extractName(array $lines): string
    {
        foreach ($lines as $line) {
            $line = trim($line);
            // "Movimentações no Cofrinho Só guardar dinheiro"
            if (preg_match('/Movimenta[çc][õo]es no Cofrinho\s+(.+)/iu', $line, $m)) {
                return trim($m[1]);
            }
        }
        return 'Cofrinho';
    }
}
