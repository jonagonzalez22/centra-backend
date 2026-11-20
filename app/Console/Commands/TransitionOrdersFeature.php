<?php

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Console\Command;

class TransitionOrdersFeature extends Command
{
    protected $signature = 'centra:orders-feature-transition
        {--dry-run : Report plans with POS but without Orders; makes no changes}
        {--apply : Attach Orders only to explicitly selected plans}
        {--plan=* : UUID of a plan to transition (repeatable; required with --apply)}';

    protected $description = 'Explicit, idempotent compatibility transition from POS-coupled orders to the Orders feature.';

    public function handle(): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Specify exactly one of --dry-run or --apply. No changes were made.');

            return self::INVALID;
        }

        $orders = Feature::query()->where('code', 'orders')->first();
        if (! $orders) {
            $this->error("The canonical 'orders' feature does not exist. Seed or create it through the approved feature workflow first.");

            return self::FAILURE;
        }

        $plans = Plan::query()
            ->withCount('stores')
            ->whereHas('features', fn ($query) => $query->where('code', 'pos'))
            ->whereDoesntHave('features', fn ($query) => $query->where('code', 'orders'))
            ->orderBy('name')
            ->get();

        if ($this->option('dry-run')) {
            foreach ($plans as $plan) {
                $this->line(json_encode([
                    'plan_id' => $plan->id,
                    'plan' => $plan->name,
                    'stores_that_would_lose_orders_access' => $plan->stores_count,
                ], JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        }

        $requestedPlanIds = array_values(array_filter($this->option('plan')));
        if ($requestedPlanIds === []) {
            $this->error('--apply requires one or more explicit --plan UUID values.');

            return self::INVALID;
        }

        $eligible = $plans->keyBy('id');
        foreach ($requestedPlanIds as $planId) {
            $plan = $eligible->get($planId);
            if (! $plan) {
                $this->error("Plan '{$planId}' is not a POS-without-Orders transition candidate. No changes were made for it.");

                continue;
            }

            $plan->features()->syncWithoutDetaching([$orders->id]);
            $this->info("Attached orders to plan '{$plan->name}' ({$plan->id}); affected stores: {$plan->stores_count}.");
        }

        return self::SUCCESS;
    }
}
