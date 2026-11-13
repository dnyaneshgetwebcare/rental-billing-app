<?php

declare(strict_types=1);

namespace App\Report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns report tables into an .xlsx workbook (one sheet per table).
 */
final class XlsxExporter
{
    /**
     * @param list<array{title: string, subtitle?: string, columns: array<string, string>, money?: list<string>, rows: list<array<string, mixed>>, totals?: array<string, mixed>}> $sheets
     */
    public function export(string $companyName, array $sheets): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($sheets as $spec) {
            $sheet = $book->createSheet();
            $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $spec['title']) ?? 'Sheet', 0, 31));
            $sheet->setCellValue('A1', $companyName);
            $sheet->setCellValue('A2', $spec['title'] . (isset($spec['subtitle']) ? ' — ' . $spec['subtitle'] : ''));
            $sheet->getStyle('A1:A2')->getFont()->setBold(true);

            $keys = array_keys($spec['columns']);
            $money = array_flip($spec['money'] ?? []);
            $row = 4;
            foreach (array_values($spec['columns']) as $i => $label) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $label);
            }
            $last = Coordinate::stringFromColumnIndex(count($keys));
            $sheet->getStyle("A$row:$last$row")->getFont()->setBold(true);
            $sheet->freezePane('A5');

            foreach ($spec['rows'] as $data) {
                $row++;
                $this->writeRow($sheet, $row, $keys, $money, $data);
            }
            if (!empty($spec['totals'])) {
                $row++;
                $this->writeRow($sheet, $row, $keys, $money, $spec['totals']);
                $sheet->getStyle("A$row:$last$row")->getFont()->setBold(true);
            }
            foreach ($keys as $i => $key) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->getColumnDimension($col)->setAutoSize(true);
                if (isset($money[$key])) {
                    $sheet->getStyle("{$col}5:$col$row")->getNumberFormat()->setFormatCode('#,##,##0.00;-#,##,##0.00');
                }
            }
        }
        $book->setActiveSheetIndex(0);

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();
        return $content;
    }

    /**
     * @param list<string> $keys
     * @param array<string, int> $money
     * @param array<string, mixed> $data
     */
    private function writeRow(Worksheet $sheet, int $row, array $keys, array $money, array $data): void
    {
        foreach ($keys as $i => $key) {
            $value = $data[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
            if (isset($money[$key]) && is_numeric($value)) {
                $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
            }
        }
    }
}
