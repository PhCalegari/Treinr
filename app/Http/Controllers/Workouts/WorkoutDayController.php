<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Concerns\ChecksOwnership;
use App\Http\Controllers\Controller;
use App\Models\WorkoutDay;
use App\Models\WorkoutPlan;
use App\Services\WorkoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkoutDayController extends Controller
{
    use ChecksOwnership;

    public function __construct(private readonly WorkoutService $workouts) {}

    public function store(Request $request, WorkoutPlan $plan): RedirectResponse
    {
        $this->own($plan);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);
        if ($plan->days()->count() >= 7) {
            return back()->withErrors(['day' => __('Um plano pode ter no máximo 7 dias.')]);
        }
        $this->workouts->createDay($plan, name: $data['name'] ?? null);

        return back();
    }

    public function update(Request $request, WorkoutDay $day): RedirectResponse
    {
        $this->own($day);
        $day->fill($request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'label' => ['sometimes', 'required', 'string', 'max:10'],
        ]))->save();

        return back();
    }

    public function destroy(WorkoutDay $day): RedirectResponse
    {
        $this->own($day);
        if (WorkoutDay::where('plan_id', $day->plan_id)->count() <= 1) {
            return back()->withErrors(['day' => __('O plano precisa ter pelo menos um dia.')]);
        }
        $this->workouts->deleteDay($day);

        return back();
    }
}
