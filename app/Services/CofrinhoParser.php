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
        $pendingMovements = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Match movement line (comes BEFORE the date/balance line in smalot output)
            // "Guardado\t+R$ 4.000,00" or "Rendimentos\t+R$ 0,07"
            if (preg_match('/^(Guardado|Resgatado|Rendimentos)\s+([+\x{2212}\-])R\$\s*([\d.,]+)/u', $line, $m)) {
                $type = self::TYPE_MAP[$m[1]] ?? null;
                if (!$type) continue;

                $amount = (float) str_replace(['.', ','], ['', '.'], $m[3]);
                if ($amount <= 0) continue;

                $pendingMovements[] = [
                    'type' => $type,
                    'amount' => round($amount, 2),
                ];
                continue;
            }

            // smalot format: "Saldo ao final do dia: R$ 4.000,00Data: 07/out/2025" (joined)
            if (preg_match('/Saldo ao final do dia:\s*R\$\s*([\d.,]+)\s*Data:\s*(\d{2})\/([\w]{3})\/(\d{4})/u', $line, $m)) {
                $balance = (float) str_replace(['.', ','], ['', '.'], $m[1]);
                $month = self::MONTHS[mb_strtolower($m[3])] ?? null;
                if ($month && !empty($pendingMovements)) {
                    $date = "{$m[4]}-{$month}-{$m[2]}";
                    foreach ($pendingMovements as $pm) {
                        $movements[] = [
                            'date' => $date,
                            'type' => $pm['type'],
                            'amount' => $pm['amount'],
                            'balance_after' => round($balance, 2),
                        ];
                    }
                    $pendingMovements = [];
                }
                continue;
            }

            // Standard format: "Data: DD/mmm/YYYY Saldo ao final do dia: R$ X.XXX,XX"
            if (preg_match('/^Data:\s+(\d{2})\/([\w]{3})\/(\d{4})\s+Saldo ao final do dia:\s+R\$\s*([\d.,]+)/u', $line, $m)) {
                $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
                if ($month && !empty($pendingMovements)) {
                    $date = "{$m[3]}-{$month}-{$m[1]}";
                    $balance = (float) str_replace(['.', ','], ['', '.'], $m[4]);
                    foreach ($pendingMovements as $pm) {
                        $movements[] = [
                            'date' => $date,
                            'type' => $pm['type'],
                            'amount' => $pm['amount'],
                            'balance_after' => round($balance, 2),
                        ];
                    }
                    $pendingMovements = [];
                }
                continue;
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
