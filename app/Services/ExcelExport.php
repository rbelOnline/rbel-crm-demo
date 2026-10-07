<?php

namespace App\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows to the browser as an .xlsx file without holding the whole
 * report in memory. Each column declares a type so Excel gets real numbers
 * and dates (sortable, summable) instead of text.
 */
class ExcelExport
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const FORMATS = [
        'text' => null,
        'int' => '0',
        'money' => '#,##0.00',
        'date' => 'yyyy-mm-dd',
    ];

    /**
     * @param  list<array{header: string, type?: 'text'|'int'|'money'|'date', width?: float}>  $columns
     * @param  iterable<list<mixed>>  $rows  one list of values per row, in column order
     */
    public function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $options = new Options;
            foreach ($columns as $i => $column) {
                $options->setColumnWidth($column['width'] ?? 16, $i + 1);
            }

            $styles = array_map(function (array $column) {
                $format = self::FORMATS[$column['type'] ?? 'text'];

                return $format === null ? new Style : (new Style)->withFormat($format);
            }, $columns);

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $bold = (new Style)->withFontBold(true);
            $writer->addRow(new Row(array_map(fn ($header) => self::cell($header, $bold), array_column($columns, 'header'))));

            foreach ($rows as $values) {
                $writer->addRow(new Row(array_map(fn ($value, $i) => self::cell($value, $styles[$i] ?? null), $values, array_keys($values))));
            }

            $writer->close();
        }, $filename, ['Content-Type' => self::MIME]);
    }

    /**
     * Text is always written as a plain string cell. OpenSpout would otherwise turn
     * any value starting with "=" into a live formula, so user-entered data such as
     * a name of =HYPERLINK(...) could run in Excel (spreadsheet formula injection).
     */
    private static function cell(mixed $value, ?Style $style): Cell
    {
        return is_string($value) && $value !== '' ? new StringCell($value, $style) : Cell::fromValue($value, $style);
    }
}
