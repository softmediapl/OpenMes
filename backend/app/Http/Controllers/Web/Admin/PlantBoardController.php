<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Line;
use App\Models\Workstation;
use App\Services\Production\PlantBoardService;
use App\Support\ShiftWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlantBoardController extends Controller
{
    public function __construct(private readonly PlantBoardService $board) {}

    public function index(Request $request): Response
    {
        [$lines, $selectedLineIds, $workstations] = $this->selection($request);

        return Inertia::render('admin/plant-board/Index', [
            'lines' => $lines,
            'selected' => ['lineIds' => $selectedLineIds],
            'snapshot' => $this->board->snapshot($workstations, $this->window($selectedLineIds)),
            'basePath' => route('admin.plant-board.index', absolute: false),
            'kiosk' => $request->boolean('kiosk'),
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        [, $selectedLineIds, $workstations] = $this->selection($request);

        return response()->json([
            'data' => $this->board->snapshot($workstations, $this->window($selectedLineIds)),
        ]);
    }

    /**
     * @return array{0: array<int, array{id: int, name: string}>, 1: array<int, int>, 2: \Illuminate\Database\Eloquent\Collection<int, Workstation>}
     */
    private function selection(Request $request): array
    {
        $lines = Line::query()
            ->active()
            ->whereHas('workstations', fn ($query) => $query->active())
            ->orderBy('name')
            ->get(['id', 'name']);

        $hasFilter = $request->query->has('lines');
        $requested = collect(explode(',', (string) $request->query('lines', '')))
            ->filter(fn (string $id) => ctype_digit($id))
            ->map(fn (string $id) => (int) $id)
            ->unique();

        $selectedLineIds = $hasFilter
            ? $requested->intersect($lines->pluck('id'))->values()->all()
            : [];

        $workstations = Workstation::query()
            ->with('line:id,name')
            ->active()
            ->whereHas('line', fn ($query) => $query->active())
            ->when($hasFilter, fn ($query) => $query->whereIn('line_id', $selectedLineIds))
            ->get()
            ->sortBy(fn (Workstation $station) => ($station->line?->name ?? '')."\0".$station->name)
            ->values();

        return [
            $lines->map(fn (Line $line) => ['id' => $line->id, 'name' => $line->name])->all(),
            $selectedLineIds,
            $workstations,
        ];
    }

    /** @param array<int, int> $selectedLineIds */
    private function window(array $selectedLineIds): ShiftWindow
    {
        return ShiftWindow::current(count($selectedLineIds) === 1 ? $selectedLineIds[0] : null);
    }
}
