<?php

namespace Tests\Feature\Progress;

use App\Models\ProgressEntry;
use App\Models\ProgressMeasurement;
use App\Models\ProgressPhoto;
use App\Models\User;
use App\Support\JpegMetadata;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class); // tipos de medida
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    public function test_records_weight_and_measurements_and_merges_same_day()
    {
        $user = User::factory()->create(['height_cm' => 180]);

        $this->actingAs($user)->post(route('progress.store'), [
            'entry_date' => '2026-11-03', 'weight_kg' => 81.4, 'measurements' => ['WAIST' => 84, 'ARM_RIGHT' => 36.5],
        ])->assertSessionHasNoErrors();

        // Mesmo dia: atualiza o registro existente; medida vazia é removida.
        $this->post(route('progress.store'), [
            'entry_date' => '2026-11-03', 'weight_kg' => 81.0, 'measurements' => ['WAIST' => 83.5, 'ARM_RIGHT' => null],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ProgressEntry::count());
        $entry = ProgressEntry::firstOrFail();
        $this->assertSame(81.0, $entry->weight_kg);
        $this->assertSame([83.5], $entry->measurements()->pluck('value_cm')->all());
        $this->assertSame(1, ProgressMeasurement::count());
    }

    public function test_validation()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('progress.store'), ['entry_date' => '2026-11-03'])->assertSessionHasErrors('weight_kg'); // registro vazio
        $this->post(route('progress.store'), ['entry_date' => '2026-11-04', 'weight_kg' => 80])->assertSessionHasErrors('entry_date'); // futuro
        $this->post(route('progress.store'), ['entry_date' => '2026-11-03', 'weight_kg' => 5])->assertSessionHasErrors('weight_kg');
        $this->post(route('progress.store'), ['entry_date' => '2026-11-03', 'measurements' => ['NOSE' => 5]])->assertSessionHasErrors('measurements');
        $this->assertSame(0, ProgressEntry::count());
    }

    public function test_overview_series_and_summary()
    {
        $user = User::factory()->create(['height_cm' => 180]);
        $this->actingAs($user);
        foreach (['2026-09-20' => 85, '2026-10-01' => 84, '2026-10-20' => 82.5, '2026-11-02' => 81] as $date => $kg) {
            $this->post(route('progress.store'), ['entry_date' => $date, 'weight_kg' => $kg, 'measurements' => ['WAIST' => $kg + 5]]);
        }

        $this->get(route('progress.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('progress/Index')
            ->has('entries', 4)
            ->where('entries.0.entry_date', '2026-11-02')
            ->has('series.weight', 4)
            ->where('series.weight.0.value', 85)
            ->has('series.measurements.WAIST', 4)
            ->where('summary.weight_kg', 81)
            ->where('summary.change_30d', -3) // comparado com 01/10 (último registro até 30 dias antes)
            ->where('summary.change_total', -4)
            ->where('summary.bmi', 25));
    }

    public function test_other_users_cannot_touch_entries()
    {
        $owner = User::factory()->create();
        $entry = new ProgressEntry(['entry_date' => '2026-11-01', 'weight_kg' => 70]);
        $entry->user_id = $owner->id;
        $entry->save();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->patch(route('progress.update', $entry), ['entry_date' => '2026-11-01', 'weight_kg' => 50])->assertNotFound();
        $this->delete(route('progress.destroy', $entry))->assertNotFound();
        $this->assertSame(70.0, $entry->fresh()->weight_kg);
    }

    public function test_photos_are_resized_private_and_deleted_with_the_entry()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('progress.store'), ['entry_date' => '2026-11-03', 'weight_kg' => 80]);
        $entry = ProgressEntry::firstOrFail();

        $this->post(route('progress-photos.store', $entry), [
            'photo' => UploadedFile::fake()->image('frente.png', 3000, 2000), 'angle' => 'front',
        ])->assertSessionHasNoErrors();

        $photo = ProgressPhoto::firstOrFail();
        Storage::disk('local')->assertExists($photo->file_path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($photo->file_path));
        $this->assertSame([1600, 1067], [$w, $h]);

        $response = $this->get(route('progress-photos.show', $photo))->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('progress-photos.show', $photo))->assertNotFound();
        $this->post(route('progress-photos.store', $entry), ['photo' => UploadedFile::fake()->image('x.png'), 'angle' => 'front'])->assertNotFound();

        // Arquivo que não é imagem é recusado.
        $this->actingAs($user)->post(route('progress-photos.store', $entry), [
            'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), 'angle' => 'front',
        ])->assertSessionHasErrors('photo');

        $this->delete(route('progress.destroy', $entry))->assertRedirect();
        Storage::disk('local')->assertMissing($photo->file_path);
        $this->assertSame(0, ProgressPhoto::count());
        $this->assertSame(0, ProgressEntry::count());
    }

    /** JPEG mínimo (32×16) com um bloco EXIF contendo um texto de "localização". */
    private function jpegWithExif(): string
    {
        $exif = "Exif\x00\x00GPS-SECRET-LOCATION";

        return "\xFF\xD8"
            ."\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xE1".pack('n', 2 + strlen($exif)).$exif
            ."\xFF\xC0\x00\x11\x08\x00\x10\x00\x20\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            ."\xFF\xDA\x00\x0C\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00abc\xFF\xD9";
    }

    public function test_jpeg_metadata_is_removed()
    {
        $clean = JpegMetadata::strip($this->jpegWithExif());
        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('GPS-SECRET', $clean);
        $this->assertStringContainsString('JFIF', $clean);
        $this->assertSame([32, 16], array_slice(getimagesizefromstring($clean), 0, 2));
        $this->assertNull(JpegMetadata::strip('not a jpeg'));

        // Envio pelo app: o arquivo guardado não tem mais a localização.
        if ((imagetypes() & IMG_JPG) !== 0) {
            return; // com JPEG no GD, a imagem é recodificada (e este JPEG sintético não é decodificável)
        }
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('progress.store'), ['entry_date' => '2026-11-03', 'weight_kg' => 80]);
        $entry = ProgressEntry::firstOrFail();
        $this->post(route('progress-photos.store', $entry), [
            'photo' => UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegWithExif()), 'angle' => 'side',
        ])->assertSessionHasNoErrors();
        $stored = Storage::disk('local')->get(ProgressPhoto::firstOrFail()->file_path);
        $this->assertStringNotContainsString('GPS-SECRET', $stored);
    }

    public function test_weight_feeds_the_goal_estimate()
    {
        $user = User::factory()->create(['sex' => 'female', 'birth_date' => '1990-05-01', 'height_cm' => 165]);
        $this->actingAs($user)->post(route('progress.store'), ['entry_date' => '2026-11-03', 'weight_kg' => 60]);

        $this->get(route('goals.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('inputs.missing', [])
            ->where('estimates.maintenance.sedentary.bmr', 1290)); // 600 + 1031,25 − 180 − 161 (36 anos)
    }
}
