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
     * Verificar se o utilizador tem permissões de admin ou moderador
     */
    private function checkPermissions()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Não autenticado'], 401);
        }

        if ($user->role->role !== 'admin' && $user->role->role !== 'curator') {
            return response()->json(['error' => 'Acesso negado. Apenas administradores e moderadores podem aceder ao dashboard.'], 403);
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
            return "EXTRACT(DAY FROM ({$endColumn}::timestamp - {$startColumn}::timestamp))";
        } else {
            return "DATEDIFF({$endColumn}, {$startColumn})";
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
                'database_driver' => DB::connection()->getDriverName(),
                'tables' => [],
                'columns' => [],
                'sample_data' => []
            ];

            // Verificar tabelas existentes
            $tables = ['reports', 'categories', 'statuses', 'users', 'category_report', 'report_details'];
            foreach ($tables as $table) {
                $exists = DB::getSchemaBuilder()->hasTable($table);
                $info['tables'][$table] = $exists;

                if ($exists) {
                    try {
                        $info['columns'][$table] = DB::getSchemaBuilder()->getColumnListing($table);
                    } catch (\Exception $e) {
                        $info['columns'][$table] = 'Erro: ' . $e->getMessage();
                    }
                }
            }

            // Sample data
            $info['sample_data']['reports_count'] = DB::table('reports')->count();
            $info['sample_data']['categories_count'] = DB::table('categories')->count();

            // Amostra da estrutura de categories
            if ($info['tables']['categories']) {
                $info['sample_data']['categories_sample'] = DB::table('categories')->limit(2)->get();
            }

            if ($info['tables']['statuses']) {
                $info['sample_data']['statuses'] = DB::table('statuses')->get();
            }

            // Check category_report relationship
            if ($info['tables']['category_report']) {
                $info['sample_data']['category_report_count'] = DB::table('category_report')->count();
                $info['sample_data']['sample_category_report'] = DB::table('category_report')->limit(3)->get();
            }

            return response()->json($info);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro: ' . $e->getMessage()], 500);
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
            $daysDifferenceSQL = $this->calculateDaysDifference('"reports"."created_at"', '"reports"."updated_at"');

            // Corrigir comparação de timestamps para PostgreSQL
            $driver = DB::connection()->getDriverName();
            $timestampComparison = $driver === 'pgsql'
                ? '"reports"."updated_at"::timestamp != "reports"."created_at"::timestamp'
                : '"reports"."updated_at" != "reports"."created_at"';

            $resolvedReports = DB::table('reports')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->whereNotNull('reports.updated_at')
                ->whereNotNull('reports.created_at')
                ->whereRaw($timestampComparison)
                ->select(
                    'reports.id',
                    'reports.created_at',
                    'reports.updated_at',
                    DB::raw("({$daysDifferenceSQL}) as days_to_resolve")
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
            if ($driver === 'pgsql') {
                $resolvedByMonth = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->where('reports.updated_at', '>=', Carbon::now()->subMonths(6))
                    ->selectRaw('EXTRACT(YEAR FROM "reports"."updated_at") as year, EXTRACT(MONTH FROM "reports"."updated_at") as month, COUNT(*) as count')
                    ->groupByRaw('EXTRACT(YEAR FROM "reports"."updated_at"), EXTRACT(MONTH FROM "reports"."updated_at")')
                    ->orderByRaw('EXTRACT(YEAR FROM "reports"."updated_at") ASC, EXTRACT(MONTH FROM "reports"."updated_at") ASC')
                    ->get();
            } else {
                $resolvedByMonth = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->where('reports.updated_at', '>=', Carbon::now()->subMonths(6))
                    ->selectRaw('YEAR("reports"."updated_at") as year, MONTH("reports"."updated_at") as month, COUNT(*) as count')
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
                    'sample_resolution_times' => $resolvedReports->take(5)->toArray(),
                    'sql_used' => $daysDifferenceSQL,
                    'timestamp_comparison' => $timestampComparison
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
            // Obter todas as categorias usando a coluna correta "category"
            $allCategories = DB::table('categories')
                ->select('id', 'category as name')  // ← MUDANÇA AQUI: category as name
                ->get()
                ->keyBy('id');

            $reportsByCategory = [];
            $resolutionRateByCategory = [];

            // Verificar estrutura da base de dados
            $hasCategoryReportTable = DB::getSchemaBuilder()->hasTable('category_report');
            $reportsColumns = DB::getSchemaBuilder()->getColumnListing('reports');
            $hasCategoryIdColumn = in_array('category_id', $reportsColumns);

            \Log::info('Category Metrics Debug', [
                'has_category_report_table' => $hasCategoryReportTable,
                'has_category_id_column' => $hasCategoryIdColumn,
                'categories_count' => $allCategories->count()
            ]);

            if ($hasCategoryReportTable) {
                // Método 1: Usar tabela pivot category_report
                $categoryReports = DB::table('category_report')
                    ->join('categories', 'category_report.category_id', '=', 'categories.id')
                    ->select(
                        'categories.id',
                        'categories.category as name',  // ← MUDANÇA AQUI
                        DB::raw('COUNT(category_report.report_id) as report_count')
                    )
                    ->groupBy('categories.id', 'categories.category')  // ← MUDANÇA AQUI
                    ->get()
                    ->keyBy('id');

                // Taxa de resolução usando tabela pivot
                $resolutionData = DB::table('category_report')
                    ->join('categories', 'category_report.category_id', '=', 'categories.id')
                    ->join('reports', 'category_report.report_id', '=', 'reports.id')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->select(
                        'categories.id',
                        'categories.category as name',  // ← MUDANÇA AQUI
                        DB::raw('COUNT(reports.id) as total_reports'),
                        DB::raw('SUM(CASE WHEN statuses.status = \'resolvido\' THEN 1 ELSE 0 END) as resolved_reports')
                    )
                    ->groupBy('categories.id', 'categories.category')  // ← MUDANÇA AQUI
                    ->get()
                    ->keyBy('id');

            } else if ($hasCategoryIdColumn) {
                // Método 2: Usar coluna category_id diretamente
                $categoryReports = DB::table('reports')
                    ->join('categories', 'reports.category_id', '=', 'categories.id')
                    ->select(
                        'categories.id',
                        'categories.category as name',  // ← MUDANÇA AQUI
                        DB::raw('COUNT(reports.id) as report_count')
                    )
                    ->groupBy('categories.id', 'categories.category')  // ← MUDANÇA AQUI
                    ->get()
                    ->keyBy('id');

                // Taxa de resolução usando coluna category_id
                $resolutionData = DB::table('reports')
                    ->join('categories', 'reports.category_id', '=', 'categories.id')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->select(
                        'categories.id',
                        'categories.category as name',  // ← MUDANÇA AQUI
                        DB::raw('COUNT(reports.id) as total_reports'),
                        DB::raw('SUM(CASE WHEN statuses.status = \'resolvido\' THEN 1 ELSE 0 END) as resolved_reports')
                    )
                    ->groupBy('categories.id', 'categories.category')  // ← MUDANÇA AQUI
                    ->get()
                    ->keyBy('id');

            } else {
                // Fallback: Nenhum relacionamento encontrado
                $categoryReports = collect();
                $resolutionData = collect();
            }

            // Construir array de reports por categoria
            foreach ($allCategories as $categoryId => $category) {
                $categoryData = $categoryReports->get($categoryId);
                $reportCount = $categoryData ? ($categoryData->report_count ?? 0) : 0;

                $reportsByCategory[] = [
                    'name' => $category->name ?? 'Categoria sem nome',
                    'count' => (int) $reportCount
                ];
            }

            // Construir array de taxa de resolução por categoria
            foreach ($allCategories as $categoryId => $category) {
                $resolutionInfo = $resolutionData->get($categoryId);

                $total = $resolutionInfo ? ($resolutionInfo->total_reports ?? 0) : 0;
                $resolved = $resolutionInfo ? ($resolutionInfo->resolved_reports ?? 0) : 0;
                $resolutionRate = $total > 0 ? round(($resolved / $total) * 100, 2) : 0;

                $resolutionRateByCategory[] = [
                    'name' => $category->name ?? 'Categoria sem nome',
                    'total' => (int) $total,
                    'resolved' => (int) $resolved,
                    'resolution_rate' => $resolutionRate
                ];
            }

            // Ordenar por count decrescente
            usort($reportsByCategory, function($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            \Log::info('Category Metrics Success', [
                'categories_found' => count($reportsByCategory),
                'sample_data' => array_slice($reportsByCategory, 0, 3)
            ]);

            return response()->json([
                'reports_by_category' => $reportsByCategory,
                'resolution_rate_by_category' => $resolutionRateByCategory,
                'debug_info' => [
                    'category_report_table_exists' => $hasCategoryReportTable,
                    'has_category_id_column' => $hasCategoryIdColumn,
                    'total_categories' => $allCategories->count(),
                    'categories_with_reports' => $categoryReports->count(),
                    'column_used' => 'category',
                    'sample_categories' => $allCategories->take(3)->toArray()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Category Metrics Error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'error' => 'Erro ao carregar métricas de categoria: ' . $e->getMessage(),
                'reports_by_category' => [],
                'resolution_rate_by_category' => [],
                'debug' => [
                    'error_line' => $e->getLine(),
                    'error_file' => $e->getFile(),
                    'sql_error' => $e->getMessage()
                ]
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
            // Top 5 utilizadores por reports (dados completos para prémios)
            $topUsersByReports = DB::table('users')
                ->leftJoin('reports', 'users.id', '=', 'reports.user_id')
                ->leftJoin('statuses', 'reports.status_id', '=', 'statuses.id')
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.points',
                    'users.created_at as user_since',
                    DB::raw('COUNT(reports.id) as total_reports'),
                    DB::raw('SUM(CASE WHEN statuses.status = \'resolvido\' THEN 1 ELSE 0 END) as resolved_reports'),
                    DB::raw('MAX(reports.created_at) as last_report_date'),
                    DB::raw('MIN(reports.created_at) as first_report_date')
                )
                ->groupBy(
                    'users.id', 'users.first_name', 'users.last_name',
                    'users.email', 'users.points', 'users.created_at'
                )
                ->having('total_reports', '>', 0)  // Apenas users com reports
                ->orderBy('total_reports', 'desc')
                ->limit(5)  // Top 5 para prémios
                ->get()
                ->map(function($user) {
                    $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                    $resolutionRate = $user->total_reports > 0
                        ? round(($user->resolved_reports / $user->total_reports) * 100, 1)
                        : 0;

                    return [
                        'id' => $user->id,
                        'full_name' => $fullName ?: 'Nome não definido',
                        'first_name' => $user->first_name ?? '',
                        'last_name' => $user->last_name ?? '',
                        'email' => $user->email ?? 'Email não definido',
                        'total_reports' => $user->total_reports ?? 0,
                        'resolved_reports' => $user->resolved_reports ?? 0,
                        'resolution_rate' => $resolutionRate,
                        'current_points' => $user->points ?? 0,
                        'user_since' => $user->user_since ? Carbon::parse($user->user_since)->format('d/m/Y') : 'N/A',
                        'last_report_date' => $user->last_report_date ? Carbon::parse($user->last_report_date)->format('d/m/Y H:i') : 'N/A',
                        'first_report_date' => $user->first_report_date ? Carbon::parse($user->first_report_date)->format('d/m/Y') : 'N/A',
                        'days_active' => $user->first_report_date && $user->last_report_date
                            ? Carbon::parse($user->first_report_date)->diffInDays(Carbon::parse($user->last_report_date)) + 1
                            : 1,
                        'avg_reports_per_month' => $user->first_report_date
                            ? round($user->total_reports / max(1, Carbon::parse($user->first_report_date)->diffInMonths(Carbon::now()) + 1), 1)
                            : 0
                    ];
                });

            // Top 10 utilizadores para o gráfico (mantém funcionalidade existente)
            $topUsersForChart = DB::table('users')
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
                ->having('reports_count', '>', 0)
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
                    DB::raw('COUNT(reports.id) as recent_reports'),
                    DB::raw('MAX(reports.created_at) as most_recent_report')
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
                        'recent_reports' => $user->recent_reports ?? 0,
                        'most_recent_report' => $user->most_recent_report
                            ? Carbon::parse($user->most_recent_report)->format('d/m/Y H:i')
                            : 'N/A'
                    ];
                });

            // Distribuição de pontos (mantém funcionalidade existente)
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

            // Estatísticas dos top users para relatório
            $topUsersStats = [
                'total_reports_from_top_5' => $topUsersByReports->sum('total_reports'),
                'avg_reports_top_5' => $topUsersByReports->avg('total_reports'),
                'highest_resolution_rate' => $topUsersByReports->max('resolution_rate'),
                'most_active_user' => $topUsersByReports->first(),
                'total_points_top_5' => $topUsersByReports->sum('current_points'),
            ];

            return response()->json([
                'top_users_by_reports' => $topUsersByReports,  // Dados completos para prémios
                'top_users_for_chart' => $topUsersForChart,    // Para o gráfico existente
                'active_users_last_30_days' => $activeUsers,
                'points_distribution' => $pointsDistribution,
                'top_users_statistics' => $topUsersStats,
                'awards_info' => [
                    'top_5_users_for_awards' => $topUsersByReports->take(5)->toArray(),
                    'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
                    'total_eligible_users' => $topUsersByReports->count()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('User Metrics Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erro ao carregar métricas de utilizadores: ' . $e->getMessage(),
                'top_users_by_reports' => [],
                'top_users_for_chart' => [],
                'active_users_last_30_days' => [],
                'points_distribution' => [],
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
                ->whereNotNull('priority')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            $daysDifferenceSQL = $this->calculateDaysDifference('"reports"."created_at"', '"reports"."updated_at"');

            // Corrigir comparação de timestamps para PostgreSQL
            $driver = DB::connection()->getDriverName();

            $resolutionTimeByPriority = DB::table('report_details')
                ->join('reports', 'report_details.report_id', '=', 'reports.id')
                ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                ->where('statuses.status', 'resolvido')
                ->whereNotNull('report_details.priority')
                ->whereNotNull('reports.updated_at')
                ->whereNotNull('reports.created_at')
                ->select('report_details.priority')
                ->selectRaw("AVG({$daysDifferenceSQL}) as avg_days")
                ->groupBy('report_details.priority')
                ->pluck('avg_days', 'priority')
                ->map(function($days) {
                    return round($days ?? 0, 1);
                })
                ->toArray();

            return response()->json([
                'reports_by_priority' => $reportsByPriority,
                'resolution_time_by_priority' => $resolutionTimeByPriority
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas de prioridade: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
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
