<?php

namespace Database\Seeders;

use App\Models\DocumentCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DocumentCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'ICAO-Documents',
            'ICAO-Annexes',
            'ข้อบังคับ กบร.',
            'พ.ร.บ.การเดินอากาศ'
        ];

        foreach ($categories as $categorie) {
            DocumentCategory::create([
                'name' => $categorie
            ]);
        }
    }
}