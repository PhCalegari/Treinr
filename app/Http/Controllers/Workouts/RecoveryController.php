<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Controller;
use App\Services\WorkoutOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Tela "Recuperação muscular": quanto cada grupo ainda está cansado dos últimos treinos. */
class RecoveryController extends Controller
{
    public function __invoke(Request $request, WorkoutOverview $overview): Response
    {
        return Inertia::render('workouts/Recovery', ['muscles' => $overview->recovery($request->user()->id)]);
    }
}
