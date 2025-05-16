<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insert([
            [
                'role' => 'user',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'role' => 'curator',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
