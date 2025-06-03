<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDashboardIndexes extends Migration
{
    public function up()
    {
        Schema::table('reports', function (Blueprint $table) {
            try {
                $table->index('created_at', 'reports_created_at_index');
            } catch (\Exception $e) {
            }

            try {
                $table->index('updated_at', 'reports_updated_at_index');
            } catch (\Exception $e) {
            }

            try {
                $table->index('status_id', 'reports_status_id_index');
            } catch (\Exception $e) {
            }

            try {
                $table->index('user_id', 'reports_user_id_index');
            } catch (\Exception $e) {
            }
        });

        if (Schema::hasTable('report_details')) {
            Schema::table('report_details', function (Blueprint $table) {
                try {
                    $table->index('priority', 'report_details_priority_index');
                } catch (\Exception $e) {
                }

                try {
                    $table->index('estimated_cost', 'report_details_estimated_cost_index');
                } catch (\Exception $e) {
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->index('points', 'users_points_index');
            } catch (\Exception $e) {
            }
        });
    }

    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            try {
                $table->dropIndex('reports_created_at_index');
            } catch (\Exception $e) {
            }

            try {
                $table->dropIndex('reports_updated_at_index');
            } catch (\Exception $e) {
            }

            try {
                $table->dropIndex('reports_status_id_index');
            } catch (\Exception $e) {
            }

            try {
                $table->dropIndex('reports_user_id_index');
            } catch (\Exception $e) {
            }
        });

        if (Schema::hasTable('report_details')) {
            Schema::table('report_details', function (Blueprint $table) {
                try {
                    $table->dropIndex('report_details_priority_index');
                } catch (\Exception $e) {
                }

                try {
                    $table->dropIndex('report_details_estimated_cost_index');
                } catch (\Exception $e) {
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropIndex('users_points_index');
            } catch (\Exception $e) {
            }
        });
    }
}
