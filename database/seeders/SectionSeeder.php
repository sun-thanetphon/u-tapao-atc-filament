<?php

namespace Database\Seeders;

use App\Models\Section;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sections = [
            ['Aerodrome-Control-Tower', 'ADC'],
            ['Approach-Radar-Control', 'APP'],
            ['Flight-Advisory', 'FA'],
        ];

        foreach ($sections as $section) {
            Section::create([
                'name' => $section[0],
                'prefix' => $section[1],
            ]);
        }
    }
}