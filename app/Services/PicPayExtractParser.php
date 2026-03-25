<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PicPayExtractParser
{
    private const MONTHS = [
        'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'marco' => '03',
        'abril' => '04', 'maio' => '05', 'junho' => '06',
        'julho' => '07', 'agosto' => '08', 'setembro' => '09',
        'outubro' => '10', 'novembro' => '11', 'dezembro' => '12',
    ];

    private const SKIP_TYPES = [
        'Pix devolvido',
        'Pix cancelado',
    ];

    private const TYPE_MAP = [
        'Pix enviado' => 'expense',
        'Pix recebido' => 'income',
        'Compra realizada' => 'expense',
        'Pagamento realizado' => 'expense',
        'Transferência recebida' => 'income',
        'Dinheiro guardado' => 'investment',
        'Dinheiro resgatado' => 'income',
        'Venda de criptomoedas' => 'income',
        'Rendimento recebido' => 'income',
    ];

    private array $categoryRules = [
        ['pattern' => '/Conta Sal[aá]rio|Transferência recebida.*Conta Sal/i', 'name' => 'Salário', 'type' => 'income', 'color' => '#16a34a'],
        ['pattern' => '/Rendimento recebido|Rendimento de conta/i', 'name' => 'Rendimentos', 'type' => 'income', 'color' => '#22c55e'],
        ['pattern' => '/Venda de criptomoedas/i', 'name' => 'Criptomoedas', 'type' => 'income', 'color' => '#f59e0b'],
        ['pattern' => '/Supermercado|Atacarejo|Rede Salvaro|Compra Certa/i', 'name' => 'Mercado', 'type' => 'expense', 'color' => '#ef4444'],
        ['pattern' => '/Acai|Gelato|Essencia do Sabor|Acaiconcept|Jelson Caetano/i', 'name' => 'Alimentação', 'type' => 'expense', 'color' => '#f97316'],
        ['pattern' => '/Gpv.*Drive|Drive/i', 'name' => 'Delivery', 'type' => 'expense', 'color' => '#fb923c'],
        ['pattern' => '/Vegas.*Comb|Posto|Combusti/i', 'name' => 'Combustível', 'type' => 'expense', 'color' => '#a855f7'],
        ['pattern' => '/Farmacia|Trajano/i', 'name' => 'Saúde', 'type' => 'expense', 'color' => '#ec4899'],
        ['pattern' => '/ENERGISA|MHNET|TELECOMUNICACOES/i', 'name' => 'Contas Fixas', 'type' => 'expense', 'color' => '#6366f1'],
        ['pattern' => '/TOKIO MARINE|SEGURADORA/i', 'name' => 'Seguro', 'type' => 'expense', 'color' => '#8b5cf6'],
        ['pattern' => '/GOVERNO DO PARANA|SECRETARIA.*FAZENDA/i', 'name' => 'Impostos', 'type' => 'expense', 'color' => '#64748b'],
        ['pattern' => '/Fatura PicPay Card|NU PAGAMENTOS|SANTANDER/i', 'name' => 'Cartão de Crédito', 'type' => 'expense', 'color' => '#0ea5e9'],
        ['pattern' => '/MITRA DIOCESANA|VAKINHA/i', 'name' => 'Doações', 'type' => 'expense', 'color' => '#14b8a6'],
        ['pattern' => '/Fio da Meada|MAGALUPAY|SBF COMERCIO|60818313/i', 'name' => 'Compras', 'type' => 'expense', 'color' => '#d946ef'],
        ['pattern' => '/Dinheiro guardado|cofrinho.*guardado/i', 'name' => 'Poupança', 'type' => 'investment', 'color' => '#2563eb'],
        ['pattern' => '/Dinheiro resgatado|cofrinho.*resgatado/i', 'name' => 'Resgate Poupança', 'type' => 'income', 'color' => '#0284c7'],
        ['pattern' => '/Para Conta Global/i', 'name' => 'Conta Global', 'type' => 'expense', 'color' => '#475569'],
        ['pattern' => '/Idealguapoltda|Cardoso e Schier/i', 'name' => 'Serviços', 'type' => 'expense', 'color' => '#78716c'],
    ];

    private const SKIP_LINE_PATTERNS = [
        '/^Hora\s+Tipo\s+Origem/i',
        '/^Documento emitido/i',
        '/^Saldo ao final/i',
        '/^Extrato\s/i',
        '/^PicPay/i',
        '/^Período/i',
        '/^\s*$/',
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
        $transactions = [];
        $currentDate = null;

        $i = 0;
        $totalLines = count($lines);

        while ($i < $totalLines) {
            $line = trim($lines[$i]);

            // Skip empty lines
            if ($line === '') {
                $i++;
                continue;
            }

            // Check for date header: "DD de MMMM YYYY"
            if (preg_match('/(\d{1,2})\s+de\s+([\p{L}]+)\s+(\d{4})/u', $line, $dateMatch)) {
                $day = str_pad($dateMatch[1], 2, '0', STR_PAD_LEFT);
                $monthName = mb_strtolower($dateMatch[2]);
                $year = $dateMatch[3];

                if (isset(self::MONTHS[$monthName])) {
                    $currentDate = "{$year}-" . self::MONTHS[$monthName] . "-{$day}";
                }

                $i++;
                continue;
            }

            // Skip known header/noise lines
            if ($this->isSkipLine($line)) {
                $i++;
                continue;
            }

            // Check for transaction line starting with time HH:MM
            if ($currentDate && preg_match('/^(\d{2}:\d{2})\s+(.+)/', $line, $timeMatch)) {
                $time = $timeMatch[1];
                $rest = $timeMatch[2];

                // Collect the full transaction text (may span multiple lines)
                $fullText = $rest;

                // Look ahead for continuation lines (no time prefix, no date header, not a skip line)
                while ($i + 1 < $totalLines) {
                    $nextLine = trim($lines[$i + 1]);

                    // Stop if next line is empty, a date header, a time-prefixed line, or a skip line
                    if (
                        $nextLine === '' ||
                        preg_match('/^\d{2}:\d{2}\s+/', $nextLine) ||
                        preg_match('/\d{1,2}\s+de\s+[\p{L}]+\s+\d{4}/u', $nextLine) ||
                        $this->isSkipLine($nextLine)
                    ) {
                        break;
                    }

                    // If the next line contains an amount, it's part of this transaction
                    $fullText .= ' ' . $nextLine;
                    $i++;

                    // If this continuation line has an amount, stop collecting
                    if (preg_match('/[+\x{2212}\-]R\$/u', $nextLine)) {
                        break;
                    }
                }

                $transaction = $this->parseTransaction($currentDate, $time, $fullText);
                if ($transaction !== null) {
                    $transactions[] = $transaction;
                }
            }

            $i++;
        }

        // Sort by date and time
        usort($transactions, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            return $cmp !== 0 ? $cmp : strcmp($a['time'], $b['time']);
        });

        return $transactions;
    }

    private function parseTransaction(string $date, string $time, string $fullText): ?array
    {
        // Extract amount: +R$ or −R$ (Unicode minus U+2212) or -R$
        if (!preg_match('/([+\x{2212}\-])R\$\s?([\d.,]+)/u', $fullText, $amountMatch)) {
            return null;
        }

        $sign = $amountMatch[1];
        $amountStr = $amountMatch[2];

        // Parse amount: "1.234,56" → 1234.56
        $amount = (float) str_replace(['.', ','], ['', '.'], $amountStr);

        if ($amount <= 0) {
            return null;
        }

        // Determine if income or expense from sign
        $isIncome = ($sign === '+');

        // Extract description (everything before the amount pattern)
        $descPart = preg_replace('/\s*[+\x{2212}\-]R\$\s?[\d.,]+/u', '', $fullText);
        $descPart = trim($descPart);

        // Identify the transaction type from known types
        $txType = null;
        foreach (self::TYPE_MAP as $knownType => $typeValue) {
            if (mb_stripos($descPart, $knownType) !== false) {
                $txType = $knownType;
                break;
            }
        }

        // Also check skip types
        foreach (self::SKIP_TYPES as $skipType) {
            if (mb_stripos($descPart, $skipType) !== false) {
                return null;
            }
        }

        // Determine transaction type (income/expense/investment)
        $type = 'expense';
        if ($txType && isset(self::TYPE_MAP[$txType])) {
            $type = self::TYPE_MAP[$txType];
        } elseif ($isIncome) {
            $type = 'income';
        }

        // Clean description
        $description = $this->cleanDescription($descPart, $txType);

        // Auto-categorize
        $category = $this->categorize($descPart, $txType, $type);

        return [
            'date' => $date,
            'time' => $time,
            'type' => $category['type'],
            'description' => $description,
            'amount' => round($amount, 2),
            'category_name' => $category['name'],
            'category_type' => $category['type'],
            'category_color' => $category['color'],
        ];
    }

    private function cleanDescription(string $description, ?string $txType): string
    {
        // Remove the transaction type prefix
        if ($txType) {
            $description = preg_replace('/' . preg_quote($txType, '/') . '/i', '', $description, 1);
        }

        // Remove payment method indicators
        $description = preg_replace('/\bCom saldo\b/i', '', $description);
        $description = preg_replace('/\bCom cart[aã]o\b/i', '', $description);

        // Remove location suffixes
        $description = preg_replace('/\bGuarapuava\s+Bra\b/i', '', $description);
        $description = preg_replace('/\bBra\s*$/i', '', $description);

        // Remove "Documento emitido" fragments
        $description = preg_replace('/Documento emitido.*/i', '', $description);

        // Clean up excess whitespace
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // Remove trailing/leading dashes or dots
        $description = trim($description, ' .-');

        return $description ?: 'Transação PicPay';
    }

    private function categorize(string $fullDescription, ?string $txType, string $defaultType): array
    {
        // Check against category rules
        foreach ($this->categoryRules as $rule) {
            if (preg_match($rule['pattern'], $fullDescription)) {
                return [
                    'name' => $rule['name'],
                    'type' => $rule['type'],
                    'color' => $rule['color'],
                ];
            }
        }

        // Fallback based on transaction type
        if ($txType) {
            if (preg_match('/recebid[oa]/i', $txType)) {
                return ['name' => 'Pix Recebido', 'type' => 'income', 'color' => '#22c55e'];
            }
            if (stripos($txType, 'enviado') !== false) {
                return ['name' => 'Pix Enviado', 'type' => 'expense', 'color' => '#94a3b8'];
            }
            if (stripos($txType, 'Compra') !== false) {
                return ['name' => 'Outros', 'type' => 'expense', 'color' => '#9ca3af'];
            }
            if (stripos($txType, 'Pagamento') !== false) {
                return ['name' => 'Pagamentos', 'type' => 'expense', 'color' => '#6b7280'];
            }
        }

        return ['name' => 'Outros', 'type' => $defaultType, 'color' => '#9ca3af'];
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        return false;
    }
}
