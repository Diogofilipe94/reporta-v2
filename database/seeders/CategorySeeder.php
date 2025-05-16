<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run()
    {
        DB::table('categories')->insert([
            [
                'category' => 'Danos na via',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Iluminação pública',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Problemas de acessibilidade',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Árvores caídas',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Lixo na via',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Parquímetro avariado',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'category' => 'Sinalização em falta/ incorreta',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
