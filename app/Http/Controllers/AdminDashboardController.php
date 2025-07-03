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
use Illuminate\Support\Facades\Log;

class AdminDashboardController extends Controller
{
    /**
     * Verificar se o utilizador tem permissões de admin ou curator
     */
    private function checkPermissions()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['error' => 'Não autenticado'], 401);
            }

            // Debug: Log user info
            Log::info('Dashboard access attempt', [
                'user_id' => $user->id,
                'user_email' => $user->email ?? 'no email',
                'has_role' => isset($user->role),
                'role_data' => $user->role ?? 'no role'
            ]);

            // Verificação mais flexível do role
            if (!isset($user->role)) {
                return response()->json(['error' => 'Utilizador sem role definido'], 403);
            }

            $roleValue = null;

            // Tentar diferentes formas de acessar o role
            if (is_object($user->role)) {
                $roleValue = $user->role->role ?? $user->role->name ?? null;
            } elseif (is_string($user->role)) {
                $roleValue = $user->role;
            } elseif (is_numeric($user->role)) {
                // Se role é numérico, considerar 2=curator, 3=admin
                $roleValue = ($user->role == 2 || $user->role == 3) ? 'admin' : 'user';
            }

            Log::info('Role check', ['role_value' => $roleValue]);

            if (!in_array($roleValue, ['admin', 'curator'])) {
                return response()->json([
                    'error' => 'Acesso negado. Apenas administradores e curadores podem aceder ao dashboard.',
                    'debug_info' => [
                        'user_role' => $roleValue,
                        'expected' => ['admin', 'curator']
                    ]
                ], 403);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Permission check error', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Erro na verificação de permissões: ' . $e->getMessage()
            ], 500);
        }
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
        try {
            // Não verificar permissões no debug para diagnosticar problemas de auth
            Log::info('Debug endpoint called');

            $info = [
                'timestamp' => now()->toISOString(),
                'database_driver' => DB::connection()->getDriverName(),
                'auth_user' => auth()->user() ? [
                    'id' => auth()->user()->id,
                    'email' => auth()->user()->email ?? 'no email',
                    'role_raw' => auth()->user()->role ?? 'no role'
                ] : 'not authenticated',
                'tables' => [],
                'columns' => [],
                'sample_data' => []
            ];

            // Verificar tabelas existentes
            $tables = ['reports', 'categories', 'statuses', 'users', 'category_report', 'report_details', 'roles'];
            foreach ($tables as $table) {
                try {
                    $exists = DB::getSchemaBuilder()->hasTable($table);
                    $info['tables'][$table] = $exists;

                    if ($exists) {
                        $info['columns'][$table] = DB::getSchemaBuilder()->getColumnListing($table);
                        $info['sample_data'][$table . '_count'] = DB::table($table)->count();
                    }
                } catch (\Exception $e) {
                    $info['tables'][$table] = 'Error: ' . $e->getMessage();
                }
            }

            // Amostra de dados importantes
            try {
                if ($info['tables']['categories'] === true) {
                    $info['sample_data']['categories_sample'] = DB::table('categories')->limit(2)->get();
                }

                if ($info['tables']['statuses'] === true) {
                    $info['sample_data']['statuses'] = DB::table('statuses')->get();
                }

                if ($info['tables']['roles'] === true) {
                    $info['sample_data']['roles'] = DB::table('roles')->get();
                }
            } catch (\Exception $e) {
                $info['sample_data']['sample_error'] = $e->getMessage();
            }

            return response()->json($info);
        } catch (\Exception $e) {
            Log::error('Debug error', ['error' => $e->getMessage()]);
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
            Log::info('Starting overview metrics');

            // Verificar existência das tabelas primeiro
            $requiredTables = ['reports', 'users', 'categories', 'statuses'];
            foreach ($requiredTables as $table) {
                if (!DB::getSchemaBuilder()->hasTable($table)) {
                    throw new \Exception("Tabela necessária não encontrada: {$table}");
                }
            }

            // Counts básicos com tratamento de erro individual
            $totalReports = 0;
            $totalUsers = 0;
            $totalCategories = 0;

            try {
                $totalReports = DB::table('reports')->count();
                Log::info('Reports count', ['count' => $totalReports]);
            } catch (\Exception $e) {
                Log::error('Error counting reports', ['error' => $e->getMessage()]);
            }

            try {
                $totalUsers = DB::table('users')->count();
                Log::info('Users count', ['count' => $totalUsers]);
            } catch (\Exception $e) {
                Log::error('Error counting users', ['error' => $e->getMessage()]);
            }

            try {
                $totalCategories = DB::table('categories')->count();
                Log::info('Categories count', ['count' => $totalCategories]);
            } catch (\Exception $e) {
                Log::error('Error counting categories', ['error' => $e->getMessage()]);
            }

            // Reports por status com tratamento de erro
            $reportsByStatus = [];
            try {
                $reportsByStatus = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->select('statuses.status', DB::raw('count(*) as count'))
                    ->groupBy('statuses.status')
                    ->pluck('count', 'status')
                    ->toArray();
                Log::info('Reports by status', ['data' => $reportsByStatus]);
            } catch (\Exception $e) {
                Log::error('Error getting reports by status', ['error' => $e->getMessage()]);
                $reportsByStatus = [];
            }

            // Reports criados nos últimos 30 dias
            $reportsLast30Days = 0;
            try {
                $reportsLast30Days = DB::table('reports')
                    ->where('created_at', '>=', Carbon::now()->subDays(30))
                    ->count();
                Log::info('Reports last 30 days', ['count' => $reportsLast30Days]);
            } catch (\Exception $e) {
                Log::error('Error counting recent reports', ['error' => $e->getMessage()]);
            }

            // Reports resolvidos
            $resolvedReports = 0;
            try {
                $resolvedReports = DB::table('reports')
                    ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                    ->where('statuses.status', 'resolvido')
                    ->count();
                Log::info('Resolved reports', ['count' => $resolvedReports]);
            } catch (\Exception $e) {
                Log::error('Error counting resolved reports', ['error' => $e->getMessage()]);
            }

            $resolutionRate = $totalReports > 0 ? round(($resolvedReports / $totalReports) * 100, 2) : 0;

            $result = [
                'total_reports' => $totalReports,
                'total_users' => $totalUsers,
                'total_categories' => $totalCategories,
                'reports_by_status' => $reportsByStatus,
                'reports_last_30_days' => $reportsLast30Days,
                'resolution_rate' => $resolutionRate,
                'resolved_reports' => $resolvedReports,
                'pending_reports' => $totalReports - $resolvedReports
            ];

            Log::info('Overview metrics completed', $result);
            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Overview metrics error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'error' => 'Erro ao carregar métricas: ' . $e->getMessage(),
                'debug' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                    'trace' => $e->getTraceAsString()
                ]
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
     * Métricas por categoria corrigidas e melhoradas
     */
    public function getCategoryMetrics()
    {
        $permissionError = $this->checkPermissions();
        if ($permissionError) return $permissionError;

        try {
            $reportsByCategory = [];
            $resolutionRateByCategory = [];

            // Verificar estrutura das tabelas
            $hasCategoryReportTable = DB::getSchemaBuilder()->hasTable('category_report');
            $reportsColumns = DB::getSchemaBuilder()->getColumnListing('reports');
            $categoriesColumns = DB::getSchemaBuilder()->getColumnListing('categories');
            $hasCategoryIdColumn = in_array('category_id', $reportsColumns);

            // Detectar qual campo usar para nome da categoria
            $categoryNameField = null;
            if (in_array('name', $categoriesColumns)) {
                $categoryNameField = 'name';
            } elseif (in_array('category_name', $categoriesColumns)) {
                $categoryNameField = 'category_name';
            } elseif (in_array('title', $categoriesColumns)) {
                $categoryNameField = 'title';
            } elseif (in_array('description', $categoriesColumns)) {
                $categoryNameField = 'description';
            }

            // Verificar se há reports no sistema
            $totalReports = DB::table('reports')->count();

            if ($totalReports === 0) {
                return response()->json([
                    'reports_by_category' => [
                        ['name' => 'Nenhum report encontrado', 'count' => 0]
                    ],
                    'resolution_rate_by_category' => [],
                    'debug_info' => [
                        'total_reports' => 0,
                        'message' => 'Não existem reports no sistema'
                    ]
                ]);
            }

            // Método 1: Tentar usar tabela pivot category_report
            if ($hasCategoryReportTable && $categoryNameField) {
                try {
                    $categoryData = DB::table('category_report')
                        ->join('categories', 'category_report.category_id', '=', 'categories.id')
                        ->select(
                            "categories.{$categoryNameField} as category_name",
                            'categories.id',
                            DB::raw('COUNT(*) as count')
                        )
                        ->groupBy('categories.id', "categories.{$categoryNameField}")
                        ->orderBy('count', 'desc')
                        ->get();

                    foreach ($categoryData as $item) {
                        $reportsByCategory[] = [
                            'name' => $item->category_name ?? 'Categoria sem nome',
                            'count' => $item->count ?? 0
                        ];
                    }

                    // Taxa de resolução por categoria usando pivot
                    if (!empty($reportsByCategory)) {
                        $resolutionData = DB::table('category_report')
                            ->join('categories', 'category_report.category_id', '=', 'categories.id')
                            ->join('reports', 'category_report.report_id', '=', 'reports.id')
                            ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                            ->select(
                                "categories.{$categoryNameField} as category_name",
                                DB::raw('COUNT(*) as total'),
                                DB::raw("SUM(CASE WHEN statuses.status = 'resolvido' THEN 1 ELSE 0 END) as resolved")
                            )
                            ->groupBy('categories.id', "categories.{$categoryNameField}")
                            ->get();

                        foreach ($resolutionData as $item) {
                            $total = $item->total ?? 0;
                            $resolved = $item->resolved ?? 0;
                            $resolutionRate = $total > 0 ? round(($resolved / $total) * 100, 2) : 0;

                            $resolutionRateByCategory[] = [
                                'name' => $item->category_name ?? 'Categoria sem nome',
                                'total' => $total,
                                'resolved' => $resolved,
                                'resolution_rate' => $resolutionRate
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Log do erro mas continue tentando outros métodos
                    error_log("Erro no método pivot: " . $e->getMessage());
                }
            }

            // Método 2: Tentar usar coluna category_id diretamente
            if (empty($reportsByCategory) && $hasCategoryIdColumn && $categoryNameField) {
                try {
                    $categoryData = DB::table('reports')
                        ->join('categories', 'reports.category_id', '=', 'categories.id')
                        ->select(
                            "categories.{$categoryNameField} as category_name",
                            'categories.id',
                            DB::raw('COUNT(*) as count')
                        )
                        ->whereNotNull('reports.category_id')
                        ->groupBy('categories.id', "categories.{$categoryNameField}")
                        ->orderBy('count', 'desc')
                        ->get();

                    foreach ($categoryData as $item) {
                        $reportsByCategory[] = [
                            'name' => $item->category_name ?? 'Categoria sem nome',
                            'count' => $item->count ?? 0
                        ];
                    }

                    // Taxa de resolução usando relacionamento direto
                    if (!empty($reportsByCategory)) {
                        $resolutionData = DB::table('reports')
                            ->join('categories', 'reports.category_id', '=', 'categories.id')
                            ->join('statuses', 'reports.status_id', '=', 'statuses.id')
                            ->select(
                                "categories.{$categoryNameField} as category_name",
                            DB::raw('COUNT(*) as total'),
                            DB::raw("SUM(CASE WHEN statuses.status = 'resolvido' THEN 1 ELSE 0 END) as resolved")
                        )
                        ->whereNotNull('reports.category_id')
                        ->groupBy('categories.id', "categories.{$categoryNameField}")
                        ->get();

                    foreach ($resolutionData as $item) {
                        $total = $item->total ?? 0;
                        $resolved = $item->resolved ?? 0;
                        $resolutionRate = $total > 0 ? round(($resolved / $total) * 100, 2) : 0;

                        $resolutionRateByCategory[] = [
                            'name' => $item->category_name ?? 'Categoria sem nome',
                            'total' => $total,
                            'resolved' => $resolved,
                            'resolution_rate' => $resolutionRate
                        ];
                    }
                }
            } catch (\Exception $e) {
                error_log("Erro no método direto: " . $e->getMessage());
            }
        }

        // Método 3: Se ainda não temos dados, mostrar todas as categorias com 0 reports
        if (empty($reportsByCategory) && $categoryNameField) {
            try {
                $categories = DB::table('categories')->get();
                foreach ($categories as $category) {
                    $categoryName = property_exists($category, $categoryNameField)
                        ? $category->$categoryNameField
                        : 'Categoria sem nome';

                    $reportsByCategory[] = [
                        'name' => $categoryName,
                        'count' => 0
                    ];
                }
            } catch (\Exception $e) {
                error_log("Erro ao carregar categorias: " . $e->getMessage());
            }
        }

        // Método 4: Fallback final - contar reports sem categoria
        if (empty($reportsByCategory)) {
            $reportsWithoutCategory = DB::table('reports')
                ->whereNull('category_id')
                ->count();

            $reportsWithCategory = $totalReports - $reportsWithoutCategory;

            $reportsByCategory = [
                ['name' => 'Reports sem categoria', 'count' => $reportsWithoutCategory],
                ['name' => 'Reports com categoria (não identificados)', 'count' => $reportsWithCategory]
            ];
        }

        // Informações de debug melhoradas
        $debugInfo = [
            'category_report_table_exists' => $hasCategoryReportTable,
            'has_category_id_column' => $hasCategoryIdColumn,
            'category_name_field_detected' => $categoryNameField,
            'categories_columns' => $categoriesColumns,
            'reports_columns' => $reportsColumns,
            'categories_count' => DB::table('categories')->count(),
            'total_reports' => $totalReports,
            'method_used' => !empty($reportsByCategory) ? 'success' : 'fallback',
            'pivot_table_records' => $hasCategoryReportTable ? DB::table('category_report')->count() : 0,
            'reports_with_category_id' => $hasCategoryIdColumn ? DB::table('reports')->whereNotNull('category_id')->count() : 0,
        ];

        // Se encontrou dados, adicionar amostra da estrutura
        if ($categoryNameField) {
            try {
                $debugInfo['categories_sample'] = DB::table('categories')->limit(2)->get();
            } catch (\Exception $e) {
                $debugInfo['categories_sample_error'] = $e->getMessage();
            }
        }

        return response()->json([
            'reports_by_category' => $reportsByCategory,
            'resolution_rate_by_category' => $resolutionRateByCategory,
            'debug_info' => $debugInfo
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Erro ao carregar métricas de categoria: ' . $e->getMessage(),
            'debug' => $e->getTraceAsString(),
            'reports_by_category' => [['name' => 'Erro ao carregar categorias', 'count' => 0]],
            'resolution_rate_by_category' => []
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
