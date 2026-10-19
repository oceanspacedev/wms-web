<?php

namespace Tests\Unit;

use App\Support\Rupiah;
use PHPUnit\Framework\TestCase;

class RupiahTest extends TestCase
{
    public function test_raw_spreadsheet_numbers_keep_their_decimal_point(): void
    {
        // Export Excel/Google Sheets menyimpan harga 1.500 sebagai "1500.0"; dulu terbaca 15000
        $this->assertSame(1500.0, Rupiah::parse('1500.0'));
        $this->assertSame(19665.3, Rupiah::parse('19665.3'));
        $this->assertSame(2900.0, Rupiah::parse('2900'));
    }

    public function test_formatted_rupiah_text_is_read_with_thousand_separators(): void
    {
        $this->assertSame(2900.0, Rupiah::parse('Rp 2.900'));
        $this->assertSame(15000.0, Rupiah::parse('Rp15.000,-'));
        $this->assertSame(2900.5, Rupiah::parse('2.900,50'));
        $this->assertSame(1500.0, Rupiah::parse('1,500'));
        $this->assertSame(0.0, Rupiah::parse('FLAT'));
        $this->assertSame(0.0, Rupiah::parse(null));
    }
}
