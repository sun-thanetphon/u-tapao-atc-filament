<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * อ่านชีตผู้ถือใบอนุญาต ATC (อ่านอย่างเดียว) แล้วแปลงเป็นรายการวันหมดอายุต่อคน
 * หัวคอลัมน์หาตามชื่อ ไม่ผูกกับตำแหน่ง เพื่อให้เพิ่มคอลัมน์ในชีตได้โดยไม่พัง
 */
class CredentialSheet
{
    public const KINDS = ['rating', 'licence', 'medical', 'language'];

    public const KIND_LABELS = [
        'rating' => 'Rating',
        'licence' => 'ใบอนุญาต (Licence)',
        'medical' => 'ใบรับรองแพทย์ Class 3',
        'language' => 'Language Proficiency',
    ];

    private const NAME_HEADER = 'Name of Holder';

    private const DATE_HEADERS = [
        'rating' => 'Rating Expiry',
        'licence' => 'Validity of licence',
        'medical' => 'Validity of Medical Class 3',
        'language' => 'Valid Until',
    ];

    /**
     * @return list<array{name: string, items: array<string, Carbon>, unreadable: list<string>}>
     *
     * @throws CredentialSheetException
     */
    public static function fetch(): array
    {
        $id = (string) config('services.credential_sheet.id');

        if ($id === '') {
            throw new CredentialSheetException('CREDENTIAL_SHEET_ID is not set');
        }

        $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id) . '/export';

        try {
            $response = Http::timeout(15)->get($url, [
                'format' => 'csv',
                'gid' => (string) config('services.credential_sheet.gid', '0'),
            ]);
        } catch (Throwable $e) {
            throw new CredentialSheetException('Sheet request failed: ' . $e::class);
        }

        if (! $response->successful()) {
            throw new CredentialSheetException('Sheet request returned HTTP ' . $response->status());
        }

        return self::parse($response->body());
    }

    /**
     * @return list<array{name: string, items: array<string, Carbon>, unreadable: list<string>}>
     *
     * @throws CredentialSheetException
     */
    public static function parse(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");

        // ชีตที่ถูกจำกัดสิทธิ์จะตอบหน้า HTML ของ Google แทน CSV
        if (str_starts_with(ltrim($csv), '<')) {
            throw new CredentialSheetException('Sheet response is not CSV (sheet may no longer be shared)');
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $headerIndex = null;
        foreach ($rows as $i => $row) {
            if (in_array(self::NAME_HEADER, array_map('trim', $row), true)) {
                $headerIndex = $i;

                break;
            }
        }

        if ($headerIndex === null) {
            throw new CredentialSheetException('Header row not found');
        }

        $header = array_map('trim', $rows[$headerIndex]);
        $nameCol = array_search(self::NAME_HEADER, $header, true);
        $dateCols = [];

        foreach (self::DATE_HEADERS as $kind => $title) {
            $col = array_search($title, $header, true);

            if ($col === false) {
                throw new CredentialSheetException("Column [{$title}] not found");
            }

            $dateCols[$kind] = $col;
        }

        $people = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $name = trim((string) ($row[$nameCol] ?? ''));

            if ($name === '') {
                continue;
            }

            $items = [];
            $unreadable = [];

            foreach ($dateCols as $kind => $col) {
                $cell = trim((string) ($row[$col] ?? ''));

                if ($cell === '') {
                    continue;
                }

                $date = self::earliestDate($cell);

                if ($date === null) {
                    $unreadable[] = $kind;
                } else {
                    $items[$kind] = $date;
                }
            }

            $people[] = ['name' => $name, 'items' => $items, 'unreadable' => $unreadable];
        }

        return $people;
    }

    /**
     * ช่องหนึ่งอาจมีหลายวันที่ (หลายบรรทัด) ใช้วันที่เร็วที่สุด คืน null ถ้าอ่านไม่ได้สักบรรทัด
     */
    private static function earliestDate(string $cell): ?Carbon
    {
        $dates = [];

        foreach (preg_split('/\R/u', $cell) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $date = Carbon::createFromFormat('!F j, Y', $line, config('app.timezone'));
            } catch (Throwable) {
                return null;
            }

            // 30 กุมภาพันธ์ ฯลฯ ถูกปัดเป็นวันอื่นเงียบๆ: ย้อนกลับมาเทียบเพื่อจับวันที่ที่ไม่มีจริง
            if ($date === false || $date->format('F j, Y') !== $line) {
                return null;
            }

            $dates[] = $date;
        }

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $dates[0];
    }
}
