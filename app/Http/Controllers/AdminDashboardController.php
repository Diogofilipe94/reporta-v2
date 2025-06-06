<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportDetail;
use App\Models\Status;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Verificar se o utilizador tem permissões de admin ou curator
     */
    private function checkPermissions()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'Não autenticado'
            ], 401);
        }

        if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
            return response()->json([
                'error' => 'Acesso negado. Apenas administradores e curadores podem aceder ao dashboard.'
            ], 403);
        }

        return null; // Sem erro
    }

    /**
     * Métricas gerais do dashboard
     */
    public function getOverviewMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $totalReports = Report::count();
            $totalUsers = User::count();
            $totalCategories = Category::count();

            // Reports por status
            $reportsByStatus = Report::select('status_id')
                ->with('status')
                ->get()
                ->groupBy('status.status')
                ->map(function ($reports) {
                    return $reports->count();
                });

            // Reports criados nos últimos 30 dias
            $reportsLast30Days = Report::where('created_at', '>=', Carbon::now()->subDays(30))->count();

            // Percentagem de reports resolvidos
            $resolvedReports = Report::whereHas('status', function($query) {
                $query->where('status', 'resolvido');
            })->count();

            $resolutionRate = $totalReports > 0 ? round(($resolvedReports / $totalReports) * 100, 2) : 0;

            return response()->json([
                'total_reports' => $totalReports,
                'total_users' => $totalUsers,
                'total_categories' => $totalCategories,
                'reports_by_status' => $reportsByStatus,
                'reports_last_30_days' => $reportsLast30Days,
                'resolution_rate' => $resolutionRate,
                'resolved_reports' => $resolvedReports,
                'pending_reports' => $totalReports - $resolvedReports
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas de resolução de reports
     */
    public function getResolutionMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            // Tempo médio de resolução (em dias)
            $averageResolutionTime = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->selectRaw('AVG(DATEDIFF(reports.updated_at, reports.created_at)) as avg_days')
                ->value('avg_days');

            $averageResolutionTime = $averageResolutionTime ? round($averageResolutionTime, 1) : 0;

            // Reports resolvidos por mês (últimos 6 meses para simplificar)
            $resolvedByMonth = Report::selectRaw('YEAR(updated_at) as year, MONTH(updated_at) as month, COUNT(*) as count')
                ->whereHas('status', function($query) {
                    $query->where('status', 'resolvido');
                })
                ->where('updated_at', '>=', Carbon::now()->subMonths(6))
                ->groupBy('year', 'month')
                ->orderBy('year', 'asc')
                ->orderBy('month', 'asc')
                ->get()
                ->map(function($item) {
                    return [
                        'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                        'count' => $item->count
                    ];
                });

            // Distribuição por tempo de resolução
            $resolutionTimeDistribution = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->selectRaw('
                    CASE
                        WHEN DATEDIFF(reports.updated_at, reports.created_at) <= 1 THEN "1 dia"
                        WHEN DATEDIFF(reports.updated_at, reports.created_at) <= 7 THEN "1-7 dias"
                        WHEN DATEDIFF(reports.updated_at, reports.created_at) <= 30 THEN "1-4 semanas"
                        ELSE "Mais de 1 mês"
                    END as time_range,
                    COUNT(*) as count
                ')
                ->groupBy('time_range')
                ->get()
                ->pluck('count', 'time_range');

            return response()->json([
                'average_resolution_time_days' => $averageResolutionTime,
                'resolved_by_month' => $resolvedByMonth,
                'resolution_time_distribution' => $resolutionTimeDistribution
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de resolução: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas por categoria
     */
    public function getCategoryMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $reportsByCategory = DB::table('reports')
                ->join('category_report', 'reports.id', '=', 'category_report.report_id')
                ->join('categories', 'category_report.category_id', '=', 'categories.id')
                ->select('categories.name', DB::raw('COUNT(*) as count'))
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('count', 'desc')
                ->get();

            // Taxa de resolução por categoria
            $resolutionRateByCategory = DB::table('reports')
                ->join('category_report', 'reports.id', '=', 'category_report.report_id')
                ->join('categories', 'category_report.category_id', '=', 'categories.id')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->select('categories.name')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN statuses.status = "resolvido" THEN 1 ELSE 0 END) as resolved,
                    ROUND((SUM(CASE WHEN statuses.status = "resolvido" THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as resolution_rate
                ')
                ->groupBy('categories.id', 'categories.name')
                ->get();

            return response()->json([
                'reports_by_category' => $reportsByCategory,
                'resolution_rate_by_category' => $resolutionRateByCategory
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de categoria: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas de utilizadores
     */
    public function getUserMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            // Top utilizadores por número de reports
            $topUsersByReports = User::withCount('reports')
                ->orderBy('reports_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->email,
                        'reports_count' => $user->reports_count,
                        'points' => $user->points ?? 0
                    ];
                });

            // Utilizadores mais ativos (últimos 30 dias)
            $activeUsers = User::whereHas('reports', function($query) {
                    $query->where('created_at', '>=', Carbon::now()->subDays(30));
                })
                ->withCount(['reports' => function($query) {
                    $query->where('created_at', '>=', Carbon::now()->subDays(30));
                }])
                ->orderBy('reports_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->email,
                        'recent_reports' => $user->reports_count
                    ];
                });

            // Distribuição de utilizadores por pontos
            $pointsDistribution = User::selectRaw('
                    CASE
                        WHEN points = 0 OR points IS NULL THEN "0 pontos"
                        WHEN points <= 10 THEN "1-10 pontos"
                        WHEN points <= 50 THEN "11-50 pontos"
                        WHEN points <= 100 THEN "51-100 pontos"
                        ELSE "Mais de 100 pontos"
                    END as points_range,
                    COUNT(*) as count
                ')
                ->groupBy('points_range')
                ->get()
                ->pluck('count', 'points_range');

            return response()->json([
                'top_users_by_reports' => $topUsersByReports,
                'active_users_last_30_days' => $activeUsers,
                'points_distribution' => $pointsDistribution
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de utilizadores: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas financeiras (baseadas nos detalhes dos reports)
     */
    public function getFinancialMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            // Verificar se a tabela report_details existe
            if (!DB::getSchemaBuilder()->hasTable('report_details')) {
                return response()->json([
                    'total_estimated_cost' => 0,
                    'average_cost_per_report' => 0,
                    'costs_by_category' => [],
                    'costs_by_status' => []
                ]);
            }

            // Custo total estimado
            $totalEstimatedCost = ReportDetail::sum('estimated_cost') ?? 0;

            // Custo médio por report
            $averageCostPerReport = ReportDetail::avg('estimated_cost') ?? 0;

            // Custos por categoria (simplificado)
            $costsByCategory = collect([]);

            // Custos por status (simplificado)
            $costsByStatus = collect([]);

            return response()->json([
                'total_estimated_cost' => round($totalEstimatedCost, 2),
                'average_cost_per_report' => round($averageCostPerReport, 2),
                'costs_by_category' => $costsByCategory,
                'costs_by_status' => $costsByStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas financeiras: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas de prioridade
     */
    public function getPriorityMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $reportsByPriority = [];
            $resolutionTimeByPriority = [];

            // Verificar se a tabela report_details existe e tem dados
            if (DB::getSchemaBuilder()->hasTable('report_details')) {
                $reportsByPriority = ReportDetail::select('priority')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('priority')
                    ->get()
                    ->pluck('count', 'priority');

                $resolutionTimeByPriority = DB::table('report_details')
                    ->join('reports', 'report_details.report_id', '=', 'reports.id')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->select('report_details.priority')
                    ->selectRaw('AVG(DATEDIFF(reports.updated_at, reports.created_at)) as avg_days')
                    ->groupBy('report_details.priority')
                    ->get()
                    ->mapWithKeys(function($item) {
                        return [$item->priority => round($item->avg_days, 1)];
                    });
            }

            return response()->json([
                'reports_by_priority' => $reportsByPriority,
                'resolution_time_by_priority' => $resolutionTimeByPriority
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de prioridade: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados exportáveis para relatório completo
     */
    public function getExportData()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $data = [
                'overview' => $this->getOverviewMetrics()->getData(),
                'resolution' => $this->getResolutionMetrics()->getData(),
                'categories' => $this->getCategoryMetrics()->getData(),
                'users' => $this->getUserMetrics()->getData(),
                'financial' => $this->getFinancialMetrics()->getData(),
                'priority' => $this->getPriorityMetrics()->getData(),
                'generated_at' => Carbon::now()->toISOString()
            ];

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao exportar dados: ' . $e->getMessage()
            ], 500);
        }
    }
}
