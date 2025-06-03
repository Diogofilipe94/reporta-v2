<?php

// 1. Migration para adicionar coluna 'points' na tabela users (se não existir)
// Ficheiro: database/migrations/xxxx_xx_xx_add_points_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPointsToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'points')) {
                $table->integer('points')->default(0)->after('email_verified_at');
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'points')) {
                $table->dropColumn('points');
            }
        });
    }
}
