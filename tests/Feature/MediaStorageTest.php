<?php

namespace Tests\Feature;

use App\Support\MediaFileName;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    public function test_filament_reads_photos_from_the_disk_the_api_writes_to(): void
    {
        $this->assertSame(
            config('filesystems.default'),
            config('filament.default_filesystem_disk'),
        );
    }

    public function test_livewire_temporary_uploads_stay_off_the_public_disk(): void
    {
        $this->assertSame('local', config('livewire.temporary_file_upload.disk'));
    }

    public function test_public_disk_photos_get_a_plain_storage_url(): void
    {
        config(['filesystems.default' => 'public']);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/tracking-orders/nota/a.jpg',
            MediaUrl::for('tracking-orders/nota/a.jpg'),
        );
    }

    public function test_private_disk_photos_get_a_signed_temporary_url(): void
    {
        config(['filesystems.default' => 'local']);

        $url = MediaUrl::for('tracking-orders/nota/a.jpg');

        $this->assertStringContainsString('/storage/tracking-orders/nota/a.jpg', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_file_name_carries_identifier_kind_time_and_short_hash(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 14, 30, 5));

        $name = MediaFileName::make(UploadedFile::fake()->image('x.jpg'), 'SJ/2026_001', 'nota');

        $this->assertMatchesRegularExpression('/^sj-2026-001_nota_20261019-143005_[a-z0-9]{5}\.jpg$/', $name);
    }

    public function test_file_name_falls_back_when_identifier_is_empty(): void
    {
        $name = MediaFileName::make(UploadedFile::fake()->image('x.png'), null, 'avatar');

        $this->assertMatchesRegularExpression('/^tanpa-id_avatar_\d{8}-\d{6}_[a-z0-9]{5}\.png$/', $name);
    }

    public function test_external_and_empty_paths_are_passed_through(): void
    {
        $this->assertSame('https://example.com/a.jpg', MediaUrl::for('https://example.com/a.jpg'));
        $this->assertNull(MediaUrl::for(null));
        $this->assertNull(MediaUrl::for(''));
    }
}
