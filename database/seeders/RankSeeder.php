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
            'พลเรือเอก',
            'พลเรือโท',
            'พลเรือตรี',
            'นาวาเอก',
            'นาวาโท',
            'นาวาตรี',
            'เรือเอก',
            'เรือโท',
            'เรือตรี',
            'พันจ่าเอก',
            'พันจ่าโท',
            'พันจ่าตรี',
            'จ่าเอก',
            'จ่าโท',
            'จ่าตรี'
        ];

        foreach ($ranks as $rank) {
            Rank::create([
                'name' => $rank
            ]);
        }
    }
}
