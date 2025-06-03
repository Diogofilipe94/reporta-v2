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
    public function __construct()
    {
        // Garantir que apenas admins e curadores podem aceder
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
                return response()->json([
                    'error' => 'Acesso negado. Apenas administradores e curadores podem aceder ao dashboard.'
                ], 403);
            }
            return $next($request);
        });
    }

    /**
     * Métricas gerais do dashboard
     */
    public function getOverviewMetrics()
    {
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
    }

    /**
     * Métricas de resolução de reports
     */
    public function getResolutionMetrics()
    {
        // Tempo médio de resolução (em dias)
        $averageResolutionTime = DB::table('reports')
            ->join('statuses', 'reports.status_id', '=', 'statuses.id')
            ->where('statuses.status', 'resolvido')
            ->selectRaw('AVG(DATEDIFF(reports.updated_at, reports.created_at)) as avg_days')
            ->value('avg_days');

        $averageResolutionTime = $averageResolutionTime ? round($averageResolutionTime, 1) : 0;

        // Reports resolvidos por mês (últimos 12 meses)
        $resolvedByMonth = Report::selectRaw('YEAR(updated_at) as year, MONTH(updated_at) as month, COUNT(*) as count')
            ->whereHas('status', function($query) {
                $query->where('status', 'resolvido');
            })
            ->where('updated_at', '>=', Carbon::now()->subYear())
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
    }

    /**
     * Métricas por categoria
     */
    public function getCategoryMetrics()
    {
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
    }

    /**
     * Métricas de utilizadores
     */
    public function getUserMetrics()
    {
        // Top utilizadores por número de reports
        $topUsersByReports = User::withCount('reports')
            ->orderBy('reports_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
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
                    'name' => $user->name,
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
    }

    /**
     * Métricas financeiras (baseadas nos detalhes dos reports)
     */
    public function getFinancialMetrics()
    {
        // Custo total estimado
        $totalEstimatedCost = ReportDetail::sum('estimated_cost') ?? 0;

        // Custo médio por report
        $averageCostPerReport = ReportDetail::avg('estimated_cost') ?? 0;

        // Custos por categoria
        $costsByCategory = DB::table('report_details')
            ->join('reports', 'report_details.report_id', '=', 'reports.id')
            ->join('category_report', 'reports.id', '=', 'category_report.report_id')
            ->join('categories', 'category_report.category_id', '=', 'categories.id')
            ->select('categories.name')
            ->selectRaw('SUM(report_details.estimated_cost) as total_cost, COUNT(*) as report_count')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('total_cost', 'desc')
            ->get();

        // Custos por status
        $costsByStatus = DB::table('report_details')
            ->join('reports', 'report_details.report_id', '=', 'reports.id')
            ->join('statuses', 'reports.status_id', '=', 'statuses.id')
            ->select('statuses.status')
            ->selectRaw('SUM(report_details.estimated_cost) as total_cost, COUNT(*) as report_count')
            ->groupBy('statuses.id', 'statuses.status')
            ->get();

        return response()->json([
            'total_estimated_cost' => round($totalEstimatedCost, 2),
            'average_cost_per_report' => round($averageCostPerReport, 2),
            'costs_by_category' => $costsByCategory,
            'costs_by_status' => $costsByStatus
        ]);
    }

    /**
     * Métricas de prioridade
     */
    public function getPriorityMetrics()
    {
        $reportsByPriority = ReportDetail::select('priority')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('priority')
            ->orderByRaw('CASE
                WHEN priority = "alta" THEN 1
                WHEN priority = "media" THEN 2
                WHEN priority = "baixa" THEN 3
                ELSE 4 END')
            ->get()
            ->pluck('count', 'priority');

        // Tempo médio de resolução por prioridade
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

        return response()->json([
            'reports_by_priority' => $reportsByPriority,
            'resolution_time_by_priority' => $resolutionTimeByPriority
        ]);
    }

    /**
     * Tendências temporais
     */
    public function getTimelineMetrics(Request $request)
    {
        $period = $request->get('period', 'last_30_days'); // last_7_days, last_30_days, last_3_months, last_year

        $startDate = match($period) {
            'last_7_days' => Carbon::now()->subDays(7),
            'last_30_days' => Carbon::now()->subDays(30),
            'last_3_months' => Carbon::now()->subMonths(3),
            'last_year' => Carbon::now()->subYear(),
            default => Carbon::now()->subDays(30)
        };

        // Reports criados por dia/semana/mês
        $groupBy = match($period) {
            'last_7_days' => 'DATE(created_at)',
            'last_30_days' => 'DATE(created_at)',
            'last_3_months' => 'WEEK(created_at)',
            'last_year' => 'MONTH(created_at)',
            default => 'DATE(created_at)'
        };

        $reportsTimeline = Report::selectRaw("$groupBy as period, COUNT(*) as count")
            ->where('created_at', '>=', $startDate)
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // Status evolution over time
        $statusEvolution = Report::selectRaw("$groupBy as period, statuses.status, COUNT(*) as count")
            ->join('statuses', 'reports.status_id', '=', 'statuses.id')
            ->where('reports.created_at', '>=', $startDate)
            ->groupBy('period', 'statuses.status')
            ->orderBy('period')
            ->get()
            ->groupBy('period');

        return response()->json([
            'period' => $period,
            'reports_timeline' => $reportsTimeline,
            'status_evolution' => $statusEvolution
        ]);
    }

    /**
     * Dados exportáveis para relatório completo
     */
    public function getExportData()
    {
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
    }
}
