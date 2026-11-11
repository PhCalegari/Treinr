<?php

namespace App\Http\Controllers\Progress;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\MeasurementType;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Services\Progress\ProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProgressController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly ProgressService $progress) {}

    public function index(Request $request): Response
    {
        return Inertia::render('progress/Index', [
            'today' => now()->toDateString(),
            'heightCm' => $request->user()->height_cm !== null ? (float) $request->user()->height_cm : null,
            ...$this->progress->overview($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->progress->save($request->user(), $this->validated($request));

        return back();
    }

    public function update(Request $request, ProgressEntry $entry): RedirectResponse
    {
        $this->own($entry);
        $this->progress->save($request->user(), $this->validated($request), $entry);

        return back();
    }

    public function destroy(ProgressEntry $entry): RedirectResponse
    {
        $this->own($entry);
        $this->progress->delete($entry->load('measurements', 'photos'));

        return back();
    }

    public function storePhoto(Request $request, ProgressEntry $entry): RedirectResponse
    {
        $this->own($entry);
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'angle' => ['required', Rule::in(ProgressPhoto::ANGLES)],
        ], [], ['photo' => 'foto']);

        $this->progress->addPhoto($entry, $data['photo'], $data['angle']);

        return back();
    }

    /** Fotos são privadas: só o dono abre. */
    public function showPhoto(ProgressPhoto $photo): StreamedResponse
    {
        $this->own($photo);
        abort_unless($photo->file_path && Storage::disk(ProgressService::PHOTO_DISK)->exists($photo->file_path), 404);

        return Storage::disk(ProgressService::PHOTO_DISK)->response($photo->file_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroyPhoto(ProgressPhoto $photo): RedirectResponse
    {
        $this->own($photo);
        $this->progress->deletePhoto($photo);

        return back();
    }

    private function validated(Request $request): array
    {
        $codes = MeasurementType::pluck('code')->all();

        return $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.now()->toDateString()],
            'weight_kg' => ['nullable', 'numeric', 'between:20,400'],
            'body_fat_pct' => ['nullable', 'numeric', 'between:3,70'],
            'notes' => ['nullable', 'string', 'max:500'],
            'measurements' => ['nullable', 'array:'.implode(',', $codes)],
            'measurements.*' => ['nullable', 'numeric', 'between:10,300'],
        ], [], [
            'entry_date' => 'data', 'weight_kg' => 'peso', 'body_fat_pct' => '% de gordura',
            'notes' => 'observação', 'measurements.*' => 'medida',
        ]);
    }
}
