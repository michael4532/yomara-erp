<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DownloadsTest extends TestCase
{
    protected string $name = 'yomara-erp_9.9.9_amd64.deb';

    protected function tearDown(): void
    {
        @unlink(public_path('downloads/'.$this->name));
        parent::tearDown();
    }

    public function test_upload_by_chunks_detected_on_download_page(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $id = 'abc123';
        foreach (['PART-ONE-', 'PART-TWO'] as $i => $chunk) {
            $this->api($admin)->post('/api/admin/downloads', ['upload_id' => $id, 'index' => $i, 'total' => 2, 'name' => $this->name,
                'chunk' => UploadedFile::fake()->createWithContent('c', $chunk)])->assertSuccessful();
            $this->flushHeaders();
        }
        $this->assertSame('PART-ONE-PART-TWO', file_get_contents(public_path('downloads/'.$this->name)));
        $this->api($admin)->post('/api/admin/downloads', ['upload_id' => 'x1', 'index' => 0, 'total' => 1, 'name' => 'shell.php',
            'chunk' => UploadedFile::fake()->createWithContent('c', '<?php echo 1;')])->assertStatus(422);
        $this->flushHeaders();
        $list = $this->api($admin)->getJson('/api/admin/downloads')->assertOk()->json();
        $this->assertSame($this->name, $list['data'][0]['name']);
        $this->assertStringEndsWith('/downloads/'.$this->name, $list['detected']['linux']);
        $this->flushHeaders();
        $this->get('/telecharger/linux')->assertRedirect(url('/downloads/'.$this->name));
        $this->get('/telecharger/source')->assertOk()->assertSee('AGPL', false); // page du code source
        $this->api($admin)->deleteJson('/api/admin/downloads/'.$this->name)->assertNoContent();
        $this->assertFileDoesNotExist(public_path('downloads/'.$this->name));
    }
}
