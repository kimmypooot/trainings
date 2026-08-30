<?php

namespace App\Support\Exports;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Cookie;
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

        $response = response()->streamDownload(function () use ($headings, $rows, $format) {
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

        // Set on the header bag rather than with ->withCookie(): streamDownload
        // hands back Symfony's StreamedResponse, which has no cookie helper of
        // its own — that one belongs to Illuminate\Http\Response.
        $response->headers->setCookie(self::handshakeCookie());

        return $response;
    }

    /**
     * Tell the page that started this download that it has begun.
     *
     * A streamed download is invisible to the page that triggered it: no
     * Inertia visit, no XHR, no load event — nothing to hang a pending state
     * on. The one thing a page *can* observe is a cookie, so a caller sends a
     * throwaway token in `?_dl=` and gets it back here.
     *
     * What this marks is time-to-first-byte, not the whole transfer: headers go
     * out ahead of the generator, so the cookie lands when the request has
     * cleared routing, auth and scoping and the browser is committing to the
     * download — precisely the handover point after which the browser's own
     * download UI is the better indicator. It is deliberately not a claim that
     * the file has finished.
     *
     * Length-capped and stripped to token characters because it is reflected:
     * the value goes back out in a Set-Cookie header, and a header is a place
     * where an unfiltered echo of user input is a response-splitting bug rather
     * than a cosmetic one. Anything unexpected yields a null cookie and the
     * caller simply falls back to its timeout.
     */
    private static function handshakeCookie(): Cookie
    {
        $token = (string) request()->query('_dl', '');
        $valid = $token !== '' && preg_match('/^[A-Za-z0-9]{1,64}$/', $token) === 1;

        // Expires quickly and is cleared by the page the moment it is seen;
        // this is a signal in flight, not state worth keeping.
        return cookie(
            name: 'dl_token',
            value: $valid ? $token : '',
            minutes: 1,
            httpOnly: false,
        );
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
