<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StressTestSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 200) as $i) {
            User::updateOrCreate(
                ['email' => "stressuser{$i}@example.com"],
                [
                    'name' => "Stress User {$i}",
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        foreach (range(1, 10) as $i) {
            Product::updateOrCreate(
                ['name' => "Stress Product {$i}"],
                [
                    'price' => 9.99 + $i,
                    'stock' => 10000,
                    'version' => 1,
                ]
            );
        }
    }
}
