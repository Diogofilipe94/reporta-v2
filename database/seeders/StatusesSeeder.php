<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StatusesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('statuses')->insert([
            [
                'status' => 'pendente',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'status' => 'em resolução',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'status' => 'resolvido',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
