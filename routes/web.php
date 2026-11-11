<?php

use App\Http\Controllers\Admin\ErrorLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\Nutrition\DiaryController;
use App\Http\Controllers\Nutrition\FoodController;
use App\Http\Controllers\Nutrition\GoalController;
use App\Http\Controllers\Nutrition\MealPhotoController;
use App\Http\Controllers\Progress\ProgressController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\Workouts\RecoveryController;
use App\Http\Controllers\Workouts\SessionSetController;
use App\Http\Controllers\Workouts\SharedPlanController;
use App\Http\Controllers\Workouts\TrainingSessionController;
use App\Http\Controllers\Workouts\WorkoutAiController;
use App\Http\Controllers\Workouts\WorkoutDayController;
use App\Http\Controllers\Workouts\WorkoutExerciseController;
use App\Http\Controllers\Workouts\WorkoutPlanController;
use Illuminate\Support\Facades\Route;

// Página inicial: quem está logado vai para o painel; quem não está, para o login.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

// Plano compartilhado por link: qualquer pessoa pode ver (copiar exige login).
Route::get('t/{token}', [SharedPlanController::class, 'show'])->middleware('throttle:60,1')->name('shared-plans.show');

Route::middleware(['auth'])->group(function () {
    Route::post('t/{token}/copy', [SharedPlanController::class, 'copy'])->middleware('throttle:10,1')->name('shared-plans.copy');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    // ---------------- Biblioteca de exercícios ----------------
    // Rotas fixas antes de {exercise} para não serem confundidas com um id.
    Route::get('exercises/library', [ExerciseController::class, 'library'])->name('exercises.library');
    Route::get('exercises/search', [ExerciseController::class, 'search'])->name('exercises.search');
    Route::get('exercises', [ExerciseController::class, 'index'])->name('exercises.index');
    Route::get('exercises/{exercise}', [ExerciseController::class, 'show'])->whereNumber('exercise')->name('exercises.show');

    // ---------------- Planos de treino ----------------
    // Treino montado pela IA (antes de workouts/{plan}, para "ai" não ser lido como id).
    Route::get('workouts/ai', [WorkoutAiController::class, 'create'])->name('workouts.ai');
    Route::get('workouts/recovery', RecoveryController::class)->name('workouts.recovery');
    Route::post('workouts/ai', [WorkoutAiController::class, 'generate'])->middleware('throttle:5,1')->name('workouts.ai.generate');
    Route::post('workouts/ai/{generation}/save', [WorkoutAiController::class, 'save'])->name('workouts.ai.save');

    Route::get('workouts', [WorkoutPlanController::class, 'index'])->name('workouts.index');
    Route::post('workouts', [WorkoutPlanController::class, 'store'])->name('workouts.store');
    Route::get('workouts/{plan}', [WorkoutPlanController::class, 'show'])->name('workouts.show');
    Route::patch('workouts/{plan}', [WorkoutPlanController::class, 'update'])->name('workouts.update');
    Route::delete('workouts/{plan}', [WorkoutPlanController::class, 'destroy'])->name('workouts.destroy');
    Route::post('workouts/{plan}/share', [SharedPlanController::class, 'store'])->name('workouts.share');
    Route::delete('workouts/{plan}/share', [SharedPlanController::class, 'destroy'])->name('workouts.unshare');

    Route::post('workouts/{plan}/days', [WorkoutDayController::class, 'store'])->name('workout-days.store');
    Route::patch('workout-days/{day}', [WorkoutDayController::class, 'update'])->name('workout-days.update');
    Route::delete('workout-days/{day}', [WorkoutDayController::class, 'destroy'])->name('workout-days.destroy');

    Route::post('workout-days/{day}/exercises', [WorkoutExerciseController::class, 'store'])->name('workout-exercises.store');
    Route::patch('workout-exercises/{item}', [WorkoutExerciseController::class, 'update'])->name('workout-exercises.update');
    Route::post('workout-exercises/{item}/move', [WorkoutExerciseController::class, 'move'])->name('workout-exercises.move');
    Route::delete('workout-exercises/{item}', [WorkoutExerciseController::class, 'destroy'])->name('workout-exercises.destroy');

    // ---------------- Execução e histórico ----------------
    Route::get('sessions', [TrainingSessionController::class, 'index'])->name('sessions.index');
    Route::post('sessions', [TrainingSessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{session}', [TrainingSessionController::class, 'show'])->name('sessions.show');
    Route::patch('sessions/{session}', [TrainingSessionController::class, 'update'])->name('sessions.update');
    Route::delete('sessions/{session}', [TrainingSessionController::class, 'destroy'])->name('sessions.destroy');
    Route::post('sessions/{session}/exercises', [TrainingSessionController::class, 'addExercise'])->name('sessions.exercises.store');

    Route::patch('session-exercises/{sessionExercise}', [SessionSetController::class, 'toggleExercise'])->name('session-exercises.update');
    Route::delete('session-exercises/{sessionExercise}', [SessionSetController::class, 'removeExercise'])->name('session-exercises.destroy');
    Route::post('session-exercises/{sessionExercise}/sets', [SessionSetController::class, 'store'])->name('session-sets.store');
    Route::patch('session-sets/{set}', [SessionSetController::class, 'update'])->name('session-sets.update');
    Route::delete('session-sets/{set}', [SessionSetController::class, 'destroy'])->name('session-sets.destroy');

    // ---------------- Diário alimentar ----------------
    Route::get('diary', [DiaryController::class, 'index'])->name('diary.index');
    Route::post('diary/items', [DiaryController::class, 'store'])->name('diary-items.store');
    Route::patch('diary/items/{item}', [DiaryController::class, 'update'])->name('diary-items.update');
    Route::delete('diary/items/{item}', [DiaryController::class, 'destroy'])->name('diary-items.destroy');

    // Busca de alimentos (Open Food Facts via servidor). Limite por usuário para respeitar os limites da OFF.
    Route::get('foods/search', [FoodController::class, 'search'])->middleware('throttle:20,1')->name('foods.search');
    Route::get('foods/barcode/{code}', [FoodController::class, 'barcode'])->middleware('throttle:30,1')->name('foods.barcode');
    Route::post('foods', [FoodController::class, 'store'])->name('foods.store');

    // Foto do prato com IA: limite curto por minuto (além do limite diário por usuário).
    Route::post('meal-photos', [MealPhotoController::class, 'analyze'])->middleware('throttle:6,1')->name('meal-photos.analyze');
    Route::post('meal-photos/{analysis}/confirm', [MealPhotoController::class, 'confirm'])->name('meal-photos.confirm');
    // Refeição falada/digitada (a transcrição da voz é feita no celular).
    Route::post('meal-texts', [MealPhotoController::class, 'analyzeText'])->middleware('throttle:10,1')->name('meal-texts.analyze');

    // Apoio ao desenvolvedor (PIX).
    Route::get('support/pix', [SupportController::class, 'pix'])->name('support.pix');

    // ---------------- Metas nutricionais ----------------
    Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('goals', [GoalController::class, 'store'])->name('goals.store');

    // ---------------- Evolução física ----------------
    Route::get('progress', [ProgressController::class, 'index'])->name('progress.index');
    Route::post('progress', [ProgressController::class, 'store'])->name('progress.store');
    Route::patch('progress/{entry}', [ProgressController::class, 'update'])->name('progress.update');
    Route::delete('progress/{entry}', [ProgressController::class, 'destroy'])->name('progress.destroy');
    Route::post('progress/{entry}/photos', [ProgressController::class, 'storePhoto'])->middleware('throttle:30,1')->name('progress-photos.store');
    Route::get('progress-photos/{photo}', [ProgressController::class, 'showPhoto'])->name('progress-photos.show');
    Route::delete('progress-photos/{photo}', [ProgressController::class, 'destroyPhoto'])->name('progress-photos.destroy');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('errors', ErrorLogController::class)->name('errors');
    Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
    Route::patch('users/{user}/password', [AdminUserController::class, 'updatePassword'])->name('users.password');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/settings.php';
