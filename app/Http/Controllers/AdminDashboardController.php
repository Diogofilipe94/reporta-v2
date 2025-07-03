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
            return response()->json(['error' => 'Não autenticado'], 401);
        }

        if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
            return response()->json(['error' => 'Acesso negado. Apenas administradores e curadores podem aceder ao dashboard.'], 403);
        }

        return null;
    }

    /**
     * Função auxiliar para calcular diferença em dias (PostgreSQL compatible)
     */
    private function calculateDaysDifference($startColumn, $endColumn)
    {
        // Detectar tipo de base de dados
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return "EXTRACT(DAY FROM ($endColumn - $startColumn))";
        } else {
            return "DATEDIFF($endColumn, $startColumn)";
        }
    }

    /**
     * Debug: Verificar estrutura das tabelas
     */
    public function getDebugInfo()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $info = [
                'tables' => [],
                'sample_data' => []
            ];

            // Verificar tabelas existentes
            $tables = ['reports', 'categories', 'statuses', 'users', 'category_report', 'report_details'];
            foreach ($tables as $table) {
                $info['tables'][$table] = DB::getSchemaBuilder()->hasTable($table);
            }

            // Sample data
            $info['sample_data']['reports_count'] = DB::table('reports')->count();
            $info['sample_data']['categories_count'] = DB::table('categories')->count();
            $info['sample_data']['statuses'] = DB::table('statuses')->get();

            // Check category_report relationship
            if ($info['tables']['category_report']) {
                $info['sample_data']['category_report_count'] = DB::table('category_report')->count();
                $info['sample_data']['sample_category_report'] = DB::table('category_report')->limit(3)->get();
            }

            return response()->json($info);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Debug error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Métricas gerais do dashboard
     */
    public function getOverviewMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $totalReports = DB::table('reports')->count();
            $totalUsers = DB::table('users')->count();
            $totalCategories = DB::table('categories')->count();

            // Reports por status com join explícito
            $reportsByStatus = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->select('statuses.status', DB::raw('count(*) as count'))
                ->groupBy('statuses.status')
                ->pluck('count', 'status')
                ->toArray();

            // Reports criados nos últimos 30 dias
            $reportsLast30Days = DB::table('reports')
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->count();

            // Reports resolvidos
            $resolvedReports = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->count();

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
     * Métricas de resolução corrigidas
     */
    public function getResolutionMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            // Usar cálculo compatível com PostgreSQL
            $daysDifferenceSQL = $this->calculateDaysDifference('reports.created_at', 'reports.updated_at');

            $resolvedReports = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->whereNotNull('reports.updated_at')
                ->whereNotNull('reports.created_at')
                ->where('reports.updated_at', '!=', 'reports.created_at')
                ->select(
                    'reports.id',
                    'reports.created_at',
                    'reports.updated_at',
                    DB::raw("($daysDifferenceSQL) as days_to_resolve")
                )
                ->get();

            $averageResolutionTime = 0;
            if ($resolvedReports->count() > 0) {
                $totalDays = $resolvedReports->sum('days_to_resolve');
                $averageResolutionTime = round($totalDays / $resolvedReports->count(), 1);
            }

            // Distribuição por tempo de resolução
            $resolutionTimeDistribution = [];
            foreach ($resolvedReports as $report) {
                $days = $report->days_to_resolve ?? 0;
                if ($days <= 1) {
                    $range = "1 dia";
                } elseif ($days <= 7) {
                    $range = "1-7 dias";
                } elseif ($days <= 30) {
                    $range = "1-4 semanas";
                } else {
                    $range = "Mais de 1 mês";
                }

                $resolutionTimeDistribution[$range] = ($resolutionTimeDistribution[$range] ?? 0) + 1;
            }

            // Reports resolvidos por mês (últimos 6 meses) - PostgreSQL compatible
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                $resolvedByMonth = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->where('reports.updated_at', '>=', Carbon::now()->subMonths(6))
                    ->selectRaw('EXTRACT(YEAR FROM reports.updated_at) as year, EXTRACT(MONTH FROM reports.updated_at) as month, COUNT(*) as count')
                    ->groupByRaw('EXTRACT(YEAR FROM reports.updated_at), EXTRACT(MONTH FROM reports.updated_at)')
                    ->orderByRaw('EXTRACT(YEAR FROM reports.updated_at) ASC, EXTRACT(MONTH FROM reports.updated_at) ASC')
                    ->get();
            } else {
                $resolvedByMonth = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->where('reports.updated_at', '>=', Carbon::now()->subMonths(6))
                    ->selectRaw('YEAR(reports.updated_at) as year, MONTH(reports.updated_at) as month, COUNT(*) as count')
                    ->groupBy('year', 'month')
                    ->orderBy('year', 'asc')
                    ->orderBy('month', 'asc')
                    ->get();
            }

            $resolvedByMonth = $resolvedByMonth->map(function($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'count' => $item->count
                ];
            });

            return response()->json([
                'average_resolution_time_days' => $averageResolutionTime,
                'resolved_by_month' => $resolvedByMonth,
                'resolution_time_distribution' => $resolutionTimeDistribution,
                'debug_info' => [
                    'resolved_reports_count' => $resolvedReports->count(),
                    'sample_resolution_times' => $resolvedReports->take(5)->toArray()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de resolução: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Métricas por categoria corrigidas
     */
    public function getCategoryMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $reportsByCategory = [];
            $resolutionRateByCategory = [];

            // Verificar se existe tabela category_report
            $hasCategoryReportTable = DB::getSchemaBuilder()->hasTable('category_report');

            // Verificar se reports tem coluna category_id
            $reportsColumns = DB::getSchemaBuilder()->getColumnListing('reports');
            $hasCategoryIdColumn = in_array('category_id', $reportsColumns);

            if ($hasCategoryReportTable) {
                // Método 1: Usar tabela pivot
                try {
                    $reportsByCategory = DB::table('category_report')
                        ->join('categories', 'category_report.category_id', '=', 'categories.id')
                        ->select('categories.name', DB::raw('COUNT(*) as count'))
                        ->groupBy('categories.id', 'categories.name')
                        ->orderBy('count', 'desc')
                        ->get()
                        ->map(function($item) {
                            return [
                                'name' => $item->name,
                                'count' => $item->count
                            ];
                        })
                        ->toArray();

                    // Taxa de resolução por categoria
                    $resolutionRateByCategory = DB::table('category_report')
                        ->join('categories', 'category_report.category_id', '=', 'categories.id')
                        ->join('reports', 'category_report.report_id', '=', 'reports.id')
                        ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                        ->select('categories.name')
                        ->selectRaw('
                            COUNT(*) as total,
                            SUM(CASE WHEN statuses.status = ? THEN 1 ELSE 0 END) as resolved
                        ', ['resolvido'])
                        ->selectRaw('
                            CASE
                                WHEN COUNT(*) > 0 THEN ROUND((SUM(CASE WHEN statuses.status = ? THEN 1 ELSE 0 END)::numeric / COUNT(*)) * 100, 2)
                                ELSE 0
                            END as resolution_rate
                        ', ['resolvido'])
                        ->groupBy('categories.id', 'categories.name')
                        ->get()
                        ->map(function($item) {
                            return [
                                'name' => $item->name,
                                'total' => $item->total,
                                'resolved' => $item->resolved,
                                'resolution_rate' => $item->resolution_rate
                            ];
                        })
                        ->toArray();
                } catch (\Exception $e) {
                    // Se falhar, tentar método alternativo
                    $reportsByCategory = [];
                    $resolutionRateByCategory = [];
                }
            }

            if (empty($reportsByCategory) && $hasCategoryIdColumn) {
                // Método 2: Usar coluna category_id
                try {
                    $reportsByCategory = DB::table('reports')
                        ->join('categories', 'reports.category_id', '=', 'categories.id')
                        ->select('categories.name', DB::raw('COUNT(*) as count'))
                        ->groupBy('categories.id', 'categories.name')
                        ->orderBy('count', 'desc')
                        ->get()
                        ->map(function($item) {
                            return [
                                'name' => $item->name,
                                'count' => $item->count
                            ];
                        })
                        ->toArray();
                } catch (\Exception $e) {
                    $reportsByCategory = [];
                }
            }

            // Se ainda estiver vazio, criar dados com todas as categorias (zero reports)
            if (empty($reportsByCategory)) {
                $categories = DB::table('categories')->get();
                foreach ($categories as $category) {
                    $reportsByCategory[] = [
                        'name' => $category->name,
                        'count' => 0
                    ];
                }
            }

            return response()->json([
                'reports_by_category' => $reportsByCategory,
                'resolution_rate_by_category' => $resolutionRateByCategory,
                'debug_info' => [
                    'category_report_table_exists' => $hasCategoryReportTable,
                    'has_category_id_column' => $hasCategoryIdColumn,
                    'reports_columns' => $reportsColumns,
                    'categories_count' => DB::table('categories')->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de categoria: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
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
            // Top utilizadores por reports
            $topUsersByReports = DB::table('users')
                ->leftJoin('reports', 'users.id', '=', 'reports.user_id')
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.points',
                    DB::raw('COUNT(reports.id) as reports_count')
                )
                ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.points')
                ->orderBy('reports_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Nome não definido',
                        'email' => $user->email ?? 'Email não definido',
                        'reports_count' => $user->reports_count ?? 0,
                        'points' => $user->points ?? 0
                    ];
                });

            // Utilizadores ativos nos últimos 30 dias
            $activeUsers = DB::table('users')
                ->join('reports', 'users.id', '=', 'reports.user_id')
                ->where('reports.created_at', '>=', Carbon::now()->subDays(30))
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    DB::raw('COUNT(reports.id) as recent_reports')
                )
                ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email')
                ->orderBy('recent_reports', 'desc')
                ->limit(10)
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Nome não definido',
                        'email' => $user->email ?? 'Email não definido',
                        'recent_reports' => $user->recent_reports ?? 0
                    ];
                });

            // Distribuição de pontos com tratamento de NULL
            $pointsDistribution = DB::table('users')
                ->selectRaw('
                    CASE
                        WHEN points IS NULL OR points = 0 THEN ?
                        WHEN points <= 10 THEN ?
                        WHEN points <= 50 THEN ?
                        WHEN points <= 100 THEN ?
                        ELSE ?
                    END as points_range,
                    COUNT(*) as count
                ', ['0 pontos', '1-10 pontos', '11-50 pontos', '51-100 pontos', 'Mais de 100 pontos'])
                ->groupBy('points_range')
                ->pluck('count', 'points_range')
                ->toArray();

            return response()->json([
                'top_users_by_reports' => $topUsersByReports,
                'active_users_last_30_days' => $activeUsers,
                'points_distribution' => $pointsDistribution
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de utilizadores: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Métricas financeiras
     */
    public function getFinancialMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            if (!DB::getSchemaBuilder()->hasTable('report_details')) {
                return response()->json([
                    'total_estimated_cost' => 0,
                    'average_cost_per_report' => 0,
                    'costs_by_category' => [],
                    'costs_by_status' => []
                ]);
            }

            $totalEstimatedCost = DB::table('report_details')->sum('estimated_cost') ?? 0;
            $averageCostPerReport = DB::table('report_details')->avg('estimated_cost') ?? 0;

            return response()->json([
                'total_estimated_cost' => round($totalEstimatedCost, 2),
                'average_cost_per_report' => round($averageCostPerReport, 2),
                'costs_by_category' => [],
                'costs_by_status' => []
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
            if (!DB::getSchemaBuilder()->hasTable('report_details')) {
                return response()->json([
                    'reports_by_priority' => [],
                    'resolution_time_by_priority' => []
                ]);
            }

            $reportsByPriority = DB::table('report_details')
                ->select('priority', DB::raw('COUNT(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            $daysDifferenceSQL = $this->calculateDaysDifference('reports.created_at', 'reports.updated_at');

            $resolutionTimeByPriority = DB::table('report_details')
                ->join('reports', 'report_details.report_id', '=', 'reports.id')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->select('report_details.priority')
                ->selectRaw("AVG($daysDifferenceSQL) as avg_days")
                ->groupBy('report_details.priority')
                ->pluck('avg_days', 'priority')
                ->map(function($days) {
                    return round($days, 1);
                })
                ->toArray();

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
     * Dados exportáveis
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
