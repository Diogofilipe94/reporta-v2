<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportRequest;
use App\Http\Requests\UpdateReportRequest;
use App\Http\Requests\UpdateReportStatusRequest;
use App\Models\Report;
use App\Models\Status;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index()
    {
        $reports = Report::with(['status', 'categories'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Add full photo URL to each report
        $reports->getCollection()->transform(function ($report) {
            $report->photo_url = $report->photo ? url('storage/reports/' . $report->photo) : null;
            return $report;
        });

        return response()->json($reports);
    }

    public function store(StoreReportRequest $request)
    {
        $user = auth()->user();
        $report = new Report();
        $photoPath = null;

        if ($request->hasFile('photo')) {
            // Generate a unique filename with timestamp
            $filename = Str::uuid() . '_' . time() . '.' . $request->file('photo')->getClientOriginalExtension();

            // Store the file in the public disk under 'reports' directory
            $photoPath = $request->file('photo')->storeAs('reports', $filename, 'public');

            // Save just the filename to the database
            $report->photo = basename($photoPath);
        }

        $report->location = $request->location;
        $report->date = now();
        $report->status_id = 1;
        $report->comment = $request->comment;
        $report->user_id = $user->id;
        $report->save();

        $report->categories()->attach($request->category_id);

        // Add photo URL to response
        $report->photo_url = $report->photo ? url('storage/reports/' . $report->photo) : null;

        return response()->json(
            $report->load(['categories', 'status']),
            201
        );
    }

    public function show($id)
    {
        $report = Report::where('id', $id)
            ->with(['user', 'status', 'categories'])
            ->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        // Add photo URL
        $report->photo_url = $report->photo ? url('storage/reports/' . $report->photo) : null;

        return response()->json($report);
    }

    public function update(UpdateReportRequest $request, $id)
    {
        $report = Report::where('id', $id)->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        $user = auth()->user();
        if($report->user_id !== $user->id &&
            $user->role->role !== 'admin' &&
            $user->role->role !== 'curator') {
            return response()->json([
                'error' => 'Unauthorized to update this report'
            ], 403);
        }

        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($report->photo) {
                Storage::disk('public')->delete('reports/' . $report->photo);
            }

            // Generate unique filename
            $filename = Str::uuid() . '_' . time() . '.' . $request->file('photo')->getClientOriginalExtension();

            // Store new photo
            $photoPath = $request->file('photo')->storeAs('reports', $filename, 'public');
            $report->photo = basename($photoPath);
        }

        if ($request->has('comment')) {
            $report->comment = $request->comment;
        }

        if ($request->has('location')) {
            $report->location = $request->location;
        }

        $report->save();

        if ($request->has('category_id')) {
            $report->categories()->sync($request->category_id);
        }

        // Add photo URL to response
        $report->photo_url = $report->photo ? url('storage/reports/' . $report->photo) : null;

        return response()->json([
            'message' => 'Report atualizado com sucesso',
            'report' => $report->load(['categories', 'status'])
        ]);
    }

    public function updateStatus(UpdateReportStatusRequest $request, $id)
    {
        $report = Report::where('id', $id)
            ->with(['status', 'user'])
            ->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report não encontrado'
            ], 404);
        }

        $statusOrder = [
            'pendente' => 1,
            'em resolução' => 2,
            'resolvido' => 3
        ];

        $currentStatus = $report->status;
        $newStatus = Status::where('id', $request->status_id)->first();

        if (!$newStatus) {
            return response()->json([
                'error' => 'Status inválido'
            ], 400);
        }

        $currentOrder = $statusOrder[$currentStatus->status];
        $newOrder = $statusOrder[$newStatus->status];

        if ($newOrder <= $currentOrder) {
            return response()->json([
                'error' => 'Progressão de status inválida, este pode apenas movimentar-se na direção: pendente -> em resolução -> resolvido',
                'current_status' => $currentStatus->status,
                'attempted_status' => $newStatus->status
            ], 400);
        }

        // Armazenar o status antigo para uso na notificação
        $oldStatus = $currentStatus->status;

        $report->status_id = $newStatus->id;
        $report->save();

        $user = $report->user;
        $user->calculatePoints();

        // Enviar notificação diretamente após a atualização
        $this->notificationService->sendToUser(
            $user,
            'Atualização do seu Report',
            "O seu report foi atualizado de '{$oldStatus}' para '{$newStatus->status}'",
            [
                'type' => 'status_update',
                'report_id' => $report->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus->status
            ]
        );

        return response()->json([
            'message' => 'Status atualizado com sucesso',
            'report' => $report->load('status')
        ]);
    }

    public function destroy($id)
    {
        $report = Report::where('id', $id)->first();

        if (!$report) {
            return response()->json([
                'error' => 'Report not found'
            ], 404);
        }

        $user = auth()->user();
        if($user->role->role !== 'admin' &&
            $user->role->role !== 'curator') {
            return response()->json([
                'error' => 'Não autorizado a eliminar este report'
            ], 403);
        }

        // Delete photo from storage
        if ($report->photo) {
            Storage::disk('public')->delete('reports/' . $report->photo);
        }

        $report->delete();

        return response()->json([
            "message" => "Report eliminado com sucesso",
        ]);
    }

    public function getUserOwnReports()
    {
        $user = auth()->user();
        $reports = Report::where('user_id', $user->id)
            ->with(['status', 'categories'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Add photo URLs
        $reports->transform(function ($report) {
            $report->photo_url = $report->photo ? url('storage/reports/' . $report->photo) : null;
            return $report;
        });

        return response()->json($reports);
    }

    public function getPoints()
    {
        // No changes needed here as it doesn't involve files
        $user = auth()->user();

        // Contadores por status
        $pendingCount = $user->reports()->whereHas('status', function($query) {
            $query->where('status', 'pendente');
        })->count();

        $inProgressCount = $user->reports()->whereHas('status', function($query) {
            $query->where('status', 'em resolução');
        })->count();

        $resolvedCount = $user->reports()->whereHas('status', function($query) {
            $query->where('status', 'resolvido');
        })->count();

        // Cálculo de pontos
        $totalPoints = ($pendingCount * 1) + ($inProgressCount * 5) + ($resolvedCount * 10);

        // Atualizar pontos do utilizador
        $user->points = $totalPoints;
        $user->save();

        return response()->json([
            'points' => $totalPoints,
            'reports_summary' => [
                'pending' => $pendingCount,
                'in_progress' => $inProgressCount,
                'resolved' => $resolvedCount
            ]
        ]);
    }
}
