<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetWebhookService;
use Illuminate\Console\Command;

class TestCsaSheetSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'csa:test-sheet-sync
                            {--sheet=CIREBON : Nama tab sheet tujuan yang SUDAH ADA (contoh: CIREBON, BANDUNG)}
                            {--send-row : Kirim baris data pengujian ke sheet target (opsional)}
                            {--url= : URL webhook Google Apps Script jika ingin override .env}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uji konektivitas ke Google Spreadsheet Webhook tanpa membuat sheet baru';

    /**
     * Execute the console command.
     */
    public function handle(GoogleSheetWebhookService $service): int
    {
        $sheet = (string) $this->option('sheet');
        $sendRow = (bool) $this->option('send-row');
        $overrideUrl = $this->option('url');

        if ($overrideUrl) {
            $service->setWebhookUrl((string) $overrideUrl);
        }

        $url = $service->getWebhookUrl();
        $spreadsheetId = $service->getSpreadsheetId();

        $this->info('====================================================');
        $this->info(' WMS -> Google Spreadsheet Webhook Connection Test');
        $this->info('====================================================');
        $this->line("Target Spreadsheet ID : {$spreadsheetId}");
        $this->line('Mode Uji              : '.($sendRow ? "Kirim 1 Baris ke Sheet '{$sheet}'" : 'Uji Konektivitas Ping (Aman, Tanpa Menambah Data)'));
        $this->line("Webhook URL           : {$url}");
        $this->newLine();

        if (empty($url)) {
            $this->error('Webhook URL belum diatur! Pastikan GOOGLE_SHEET_WEBHOOK_URL terisi di .env.');

            return self::FAILURE;
        }

        $this->info('Menghubungi Webhook Google Apps Script...');
        $result = $service->testConnection($sheet, $sendRow);

        if ($result['success']) {
            $this->info('✓ '.$result['message']);

            if ($sendRow) {
                $this->table(
                    ['Parameter', 'Nilai'],
                    [
                        ['Status', 'BERHASIL'],
                        ['Tab Sheet', $sheet],
                        ['Total Dikirim', $result['details']['total_received'] ?? 1],
                        ['Baris Ditambahkan', $result['details']['inserted'] ?? 1],
                        ['Duplikasi Dilewati', $result['details']['skipped_duplicate'] ?? 0],
                    ]
                );
            }

            return self::SUCCESS;
        }

        $this->error('✗ '.$result['message']);
        if (! empty($result['details'])) {
            $this->line(json_encode($result['details'], JSON_PRETTY_PRINT));
        }

        return self::FAILURE;
    }
}
