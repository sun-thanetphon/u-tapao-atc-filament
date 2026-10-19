<?php

namespace Tests\Feature;

use App\Support\CredentialSheet;
use App\Support\CredentialSheetException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredentialSheetTest extends TestCase
{
    private const HEADER = "title row,,,,,,,,,,,,,\n"
        . ",Name of Holder,ATC Licence/SATC No.,Ratings,Rating Issue,Years,Rating Expiry,day left,Validity of licence,day left,Validity of Medical Class 3,day left,Level Language,Valid Until,day left\n";

    private function row(string $name, string $rating = '', string $licence = '', string $medical = '', string $language = ''): string
    {
        $cells = ['1', $name, 'TH.ATCL.1', 'ADI', 'January 1, 2000', '20', $rating, '99', $licence, '99', $medical, '99', 'Level 4', $language, '99'];

        return implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cells)) . "\n";
    }

    public function test_parses_four_items_per_row(): void
    {
        $csv = self::HEADER . $this->row('น.ต.ทดสอบ หนึ่ง', 'August 31, 2029', 'December 7, 2029', 'September 16, 2027', 'June 27, 2028');

        $rows = CredentialSheet::parse($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('น.ต.ทดสอบ หนึ่ง', $rows[0]['name']);
        $this->assertSame(
            ['rating' => '2029-08-31', 'licence' => '2029-12-07', 'medical' => '2027-09-16', 'language' => '2028-06-27'],
            array_map(fn ($d) => $d->toDateString(), $rows[0]['items'])
        );
        $this->assertSame([], $rows[0]['unreadable']);
    }

    public function test_empty_date_cell_means_no_item(): void
    {
        $rows = CredentialSheet::parse(self::HEADER . $this->row('A B', '', 'December 7, 2029'));

        $this->assertSame(['licence'], array_keys($rows[0]['items']));
        $this->assertSame([], $rows[0]['unreadable']);
    }

    public function test_multi_line_cell_uses_earliest_date(): void
    {
        $rows = CredentialSheet::parse(self::HEADER . $this->row('A B', "August 31, 2029\nMarch 1, 2028"));

        $this->assertSame('2028-03-01', $rows[0]['items']['rating']->toDateString());
    }

    public function test_bad_date_is_listed_unreadable(): void
    {
        $rows = CredentialSheet::parse(self::HEADER . $this->row('A B', 'soon', 'February 30, 2029'));

        $this->assertSame([], $rows[0]['items']);
        $this->assertSame(['rating', 'licence'], $rows[0]['unreadable']);
    }

    public function test_blank_trailing_rows_are_skipped(): void
    {
        $rows = CredentialSheet::parse(self::HEADER . $this->row('A B', 'August 31, 2029') . ",,,,,,,,,,,,,,\n,,,,,,,,,,,,,,\n");

        $this->assertCount(1, $rows);
    }

    public function test_missing_header_throws(): void
    {
        $this->expectException(CredentialSheetException::class);

        CredentialSheet::parse("a,b,c\n1,2,3\n");
    }

    public function test_missing_date_column_throws(): void
    {
        $this->expectException(CredentialSheetException::class);

        CredentialSheet::parse("Name of Holder,Rating Expiry\nA B,\"August 31, 2029\"\n");
    }

    public function test_html_response_throws(): void
    {
        $this->expectException(CredentialSheetException::class);

        CredentialSheet::parse('<!DOCTYPE html><html><body>Sign in</body></html>');
    }

    public function test_fetch_parses_the_exported_csv(): void
    {
        config(['services.credential_sheet.id' => 'abc123', 'services.credential_sheet.gid' => '7']);
        Http::fake(['docs.google.com/*' => Http::response(self::HEADER . $this->row('A B', 'August 31, 2029'))]);

        $rows = CredentialSheet::fetch();

        $this->assertCount(1, $rows);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/spreadsheets/d/abc123/export')
            && str_contains($request->url(), 'format=csv')
            && str_contains($request->url(), 'gid=7'));
    }

    public function test_fetch_failure_throws(): void
    {
        config(['services.credential_sheet.id' => 'abc123']);
        Http::fake(['docs.google.com/*' => Http::response('nope', 500)]);

        $this->expectException(CredentialSheetException::class);

        CredentialSheet::fetch();
    }

    public function test_fetch_without_sheet_id_throws(): void
    {
        config(['services.credential_sheet.id' => null]);

        $this->expectException(CredentialSheetException::class);

        CredentialSheet::fetch();
    }
}
