<?php

namespace App\Support\Exports;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a spreadsheet straight to the browser.
 *
 * Rows are pulled from a generator and written one at a time, so exporting a
 * few thousand participants never builds the whole file in memory — the failure
 * mode v1's `export-*.php` pages had, and the reason this is streamed rather
 * than collected.
 */
class SpreadsheetExport
{
    /**
     * @param  array<int, string>  $headings
     * @param  callable(): iterable<int, array<int, mixed>>  $rows
     */
    public static function download(
        string $filename,
        array $headings,
        callable $rows,
        string $format = 'csv'
    ): StreamedResponse {
        $format = in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv';
        $name = Str::slug($filename).'-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($headings, $rows, $format) {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;

            // php://output rather than a temp file: nothing is ever written to
            // disk, so a cancelled download leaves no participant data behind.
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headings));

            foreach ($rows() as $row) {
                $writer->addRow(Row::fromValues(self::stringify($row)));
            }

            $writer->close();
        }, $name, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Normalise values openspout will not accept as-is.
     *
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    private static function stringify(array $row): array
    {
        return array_map(function ($value) {
            if ($value === null) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i');
            }

            return $value;
        }, $row);
    }
}
