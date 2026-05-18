<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;

class DailySalesInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'daily:sales-inventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process today\'s sales in chunks for inventory and reporting';

    public function handle(): int
    {
        Order::whereDate('created_at', today())
            ->chunk(100, function ($orders) {
                foreach ($orders as $order) {
                    $this->info("Processing order #{$order->id}");
                }
            });

        $this->info('Daily sales inventory completed successfully.');

        return 0;
    }
}
