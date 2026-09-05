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

            return is_string($value) ? self::defuseFormula($value) : $value;
        }, $row);
    }

    /**
     * Stop a spreadsheet treating participant text as a formula.
     *
     * Every export here carries free text that a participant typed:
     * `organization_name`, `position_title`, `food_restrictions_details`, the
     * name itself, and the `reason` and `remarks` on requests. A value opening
     * with `=`, `+`, `-` or `@` is a formula to Excel and to LibreOffice, so a
     * participant who sets their employer to `=HYPERLINK(...)` — or to one of
     * the DDE payloads that shell out — is running it on the machine of
     * whichever HRD officer opens the register. The application never renders
     * it, which is what makes this easy to miss: the payload is inert
     * everywhere except the one place the data is meant to end up.
     *
     * A leading apostrophe is the fix both applications understand: it marks
     * the cell as literal text and is not displayed.
     *
     * Deliberately narrow. Only strings are touched, so the numeric columns an
     * accountant sums stay numeric — a naive version of this prefixes every
     * negative amount and turns the revenue column into text. And a string that
     * is merely a number (`-1500.00`, which is what a refund looks like once it
     * has been through number_format) is left alone for the same reason: it
     * opens with `-`, but there is no formula a bare number can become.
     */
    private static function defuseFormula(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        // Tab, CR and LF are here because a leading one shifts the payload into
        // the next cell in some importers, which puts an unescaped `=` at the
        // start of a value again.
        return str_contains("=+-@\t\r\n", $value[0]) ? "'".$value : $value;
    }
}
