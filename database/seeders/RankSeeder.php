<?php

namespace Database\Seeders;

use App\Models\Rank;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ranks = [
            'จ่าตรี',
            'จ่าโท',
            'จ่าเอก',
            'พันจ่าตรี',
            'พันจ่าโท',
            'พันจ่าเอก',
            'เรือตรี',
            'เรือโท',
            'เรือเอก',
            'นาวาตรี',
            'นาวาโท',
            'นาวาเอก',
            'พลเรือตรี',
            'พลเรือโท',
            'พลเรือเอก'
        ];


        foreach ($ranks as $rank) {
            Rank::create([
                'name' => $rank
            ]);
        }
    }
}