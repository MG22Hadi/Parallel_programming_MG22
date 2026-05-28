<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GenerateStressTokens extends Command
{
    protected $signature = 'stress:generate-tokens {--output=tests/performance/stress_tokens.json}';
    protected $description = 'Generate Sanctum tokens for stress test users and save them to a JSON file.';

    public function handle(): int
    {
        $output = $this->option('output');
        $users = User::where('email', 'like', 'stressuser%@example.com')
            ->orderBy('email')
            ->get();

        if ($users->isEmpty()) {
            $this->error('No stress users found. Run php artisan db:seed --class=StressTestSeeder first.');
            return 1;
        }

        $payload = [];

        foreach ($users as $user) {
            $user->tokens()->delete();
            $token = $user->createToken('stress-token')->plainTextToken;
            $payload[] = [
                'email' => $user->email,
                'token' => $token,
            ];
        }

        $directory = dirname($output);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Generated {$users->count()} stress tokens to {$output}");

        return 0;
    }
}
