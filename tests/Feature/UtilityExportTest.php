<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Jobs\ExportUtilityUsagesJob;
use App\Models\Export;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtilityExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected Property $property;

    protected PropertyUtility $utility;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->landlord = User::create([
            'name' => 'Landlord Jack',
            'email' => 'jack@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->landlord->assignRole('landlord');

        $this->property = Property::create([
            'landlord_id' => $this->landlord->id,
            'name' => 'Sunset Condos',
        ]);

        $this->utility = PropertyUtility::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'name' => 'Water',
            'billing_type' => BillingType::Metered,
            'rate' => 1.50,
            'unit_of_measure' => 'm3',
        ]);

        $this->unit = Unit::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => 'A1',
            'room_type' => 'Standard',
            'rent_amount' => 600,
        ]);

        // Create some utility usages
        UtilityUsage::create([
            'unit_id' => $this->unit->id,
            'property_utility_id' => $this->utility->id,
            'reading_date' => '2026-07-01',
            'old_reading' => 10,
            'new_reading' => 20,
            'amount_used' => 10,
            'recorded_by_id' => $this->landlord->id,
        ]);
    }

    /**
     * The endpoint used to call $job->handle() in-request ("Run synchronously"),
     * which for the pdf format spawned Node + headless Chrome inside a PHP-FPM
     * worker. It now queues and answers 202; the finished file reaches the user
     * through the job's database notification and exports.download.
     */
    public function test_landlord_export_request_is_queued_not_rendered_in_request(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->landlord)
            ->postJson(route('exports.utility-usages', ['property_id' => $this->property->id]), [
                'time_period' => 'all',
                'utility_types' => ['all'],
                'format' => 'csv',
            ]);

        $response->assertStatus(202)->assertJson(['queued' => true]);

        $this->assertDatabaseHas('exports', [
            'user_id' => $this->landlord->id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ExportUtilityUsagesJob::class);
    }

    public function test_landlord_cannot_trigger_export_for_other_properties(): void
    {
        $otherLandlord = User::create([
            'name' => 'Other Landlord',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);
        $otherLandlord->assignRole('landlord');

        $response = $this->actingAs($otherLandlord)
            ->postJson(route('exports.utility-usages', ['property_id' => $this->property->id]), [
                'time_period' => 'all',
                'utility_types' => ['all'],
                'format' => 'csv',
            ]);

        $response->assertStatus(404);
    }

    public function test_export_job_generates_csv_file_correctly(): void
    {
        $export = Export::create([
            'user_id' => $this->landlord->id,
            'file_name' => 'test.csv',
            'status' => 'pending',
        ]);

        $job = new ExportUtilityUsagesJob($export, $this->property->id, [
            'time_period' => 'all',
            'utility_types' => ['all'],
            'format' => 'csv',
        ]);

        $job->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        $this->assertNotNull($export->file_path);

        $fullPath = storage_path('app/'.$export->file_path);
        $this->assertFileExists($fullPath);

        $content = file_get_contents($fullPath);
        $this->assertStringContainsString('Water', $content);
        $this->assertStringContainsString('A1', $content);

        // Cleanup
        unlink($fullPath);
    }

    public function test_export_job_generates_xlsx_file_correctly(): void
    {
        $export = Export::create([
            'user_id' => $this->landlord->id,
            'file_name' => 'test.xlsx',
            'status' => 'pending',
        ]);

        $job = new ExportUtilityUsagesJob($export, $this->property->id, [
            'time_period' => 'all',
            'utility_types' => ['all'],
            'format' => 'xlsx',
        ]);

        $job->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        $this->assertNotNull($export->file_path);

        $fullPath = storage_path('app/'.$export->file_path);
        $this->assertFileExists($fullPath);

        // Cleanup
        unlink($fullPath);
    }

    public function test_export_job_generates_pdf_file_correctly(): void
    {
        $export = Export::create([
            'user_id' => $this->landlord->id,
            'file_name' => 'test.pdf',
            'status' => 'pending',
        ]);

        $job = new ExportUtilityUsagesJob($export, $this->property->id, [
            'time_period' => 'all',
            'utility_types' => ['all'],
            'format' => 'pdf',
        ]);

        $job->handle();

        $export->refresh();
        $this->assertEquals('completed', $export->status);
        $this->assertNotNull($export->file_path);

        $fullPath = storage_path('app/'.$export->file_path);
        $this->assertFileExists($fullPath);

        // Cleanup
        unlink($fullPath);
    }

    public function test_user_can_download_completed_export_file(): void
    {
        // Setup file
        $fileName = 'test_download.csv';
        $filePath = 'exports/'.$fileName;
        $absolutePath = storage_path('app/'.$filePath);

        if (! file_exists(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, 'dummy,csv,data');

        $export = Export::create([
            'user_id' => $this->landlord->id,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->landlord)
            ->get(route('exports.download', ['file_id' => $export->id]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename='.$fileName);

        // Cleanup
        unlink($absolutePath);
    }

    public function test_unauthorized_user_cannot_download_export_file(): void
    {
        $otherLandlord = User::create([
            'name' => 'Other Landlord',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);
        $otherLandlord->assignRole('landlord');

        $export = Export::create([
            'user_id' => $this->landlord->id,
            'file_name' => 'test.csv',
            'file_path' => 'exports/test.csv',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($otherLandlord)
            ->get(route('exports.download', ['file_id' => $export->id]));

        $response->assertStatus(403);
    }
}
