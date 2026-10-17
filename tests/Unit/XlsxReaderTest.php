<?php

namespace Tests\Unit;

use App\Services\XlsxReader;
use PHPUnit\Framework\TestCase;

class XlsxReaderTest extends TestCase
{
    public function test_self_closing_empty_cells_do_not_take_the_next_cells_value(): void
    {
        // Export Google Sheets menulis sel kosong yang punya format sebagai <c .../>
        $row = '<row r="2"><c r="A2" s="17"><v>46024.0</v></c><c r="J2" s="22"/><c r="K2" s="19" t="s"><v>0</v></c>'
            .'<c r="U2" s="27"/><c r="Y2" t="inlineStr"><is><t>3x REALME</t></is></c></row>';

        $this->assertSame(
            ['_row' => '2', 'A' => '46024.0', 'K' => 'REALME - DP CIREBON', 'Y' => '3x REALME'],
            (new XlsxReader)->parseRow($row, ['REALME - DP CIREBON']),
        );
    }
}
