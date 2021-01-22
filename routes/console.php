<?php

use App\Ledger\PayoutRunner;
use App\Models\Vendor;
use App\Orders\OrderWorkflow;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('marketplace:expire-reservations', function (OrderWorkflow $workflow) {
    $count = $workflow->expireOverdue();
    $this->info("Cancelled {$count} unpaid orders and released their stock.");
})->purpose('Cancel unpaid orders whose payment window passed and put their stock back');

Artisan::command('marketplace:payouts', function (PayoutRunner $runner) {
    $payouts = $runner->run();
    foreach ($payouts as $payout) {
        $this->line(sprintf('%-24s %12s  %s', $payout->vendor->name, money($payout->amount_cents), $payout->reference));
    }
    $this->info($payouts->isEmpty() ? 'No vendor had a payable balance.' : "Paid {$payouts->count()} vendors.");
})->purpose('Pay every approved vendor its available (cleared) balance');

Artisan::command('marketplace:seed-demo', function () {
    if (Vendor::exists()) {
        $this->info('Demo data already present; nothing to do.');

        return;
    }
    $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
})->purpose('Seed the demo marketplace, only if the database has no shops yet');

Schedule::command('marketplace:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('marketplace:payouts')->dailyAt('06:00')->withoutOverlapping();
