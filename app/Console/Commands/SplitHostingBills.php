<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Posting\ExpensePoster;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// One-off (safe to rerun) split of a year's Linode and AWS bills across
// the clients they host, from the per-website monthly costs in "Hosting
// Revenue vs. Linode Cost - By Website" (Oct 2026), for Bookkeeping >
// Hosting profitability. Each month brings two bills of each under the
// same name: the larger is the main account, split by the weights below
// (each scaled to the bill's total); the smaller is The Andersons Inc.'s
// own account, all theirs. Servers no client pays for go to the Not billed
// share (a null client). Bills already split are left alone unless
// --force; after this, new bills start from the last split in the form.
class SplitHostingBills extends Command
{
    protected $signature = 'hosting:split {year} {--force : Re-split bills that already have a split} {--dry-run : Show what would be split, change nothing}';

    protected $description = "Split a year's Linode and AWS bills across clients by each one's monthly server cost.";

    private const ANDERSONS = 'The Andersons Inc.';

    // Monthly cost per client on the main accounts, from the sheet's
    // Billed rows; null is Not billed (internal, The Arts Commission,
    // tfrd, cadt, game-on dev, and the AWS-only sites nobody is billed for).
    private const WEIGHTS = [
        'LINODE . AKAMAI' => [
            'Bionix' => 29.00,
            'City of Toledo Communications' => 116.00,
            'Greater Toledo Community Foundation' => 14.50,
            'Detroit Public Library' => 116.00,
            'Pioneer Library System' => 24.00,
            'RJ Schinner' => 41.00,
            'Catholic Diocese of Toledo' => 29.00,
            'GM ASEP' => 41.67,
            "Robison, Curphey & O'Connell, LLC" => 14.50,
            'Dreicor, Inc.' => 7.00,
            'Harbor' => 58.00,
            'The Area Office on Aging of Northwestern Ohio, Inc.' => 14.50,
            'TRECA Digital Academy' => 58.00,
            'Health Partners of Western Ohio' => 14.50,
            'Grosse Pointe Public Library' => 14.50,
            'Toledo Mud Hens' => 36.00,
            'Maple Grove Companies' => 14.50,
            'Toledo Metropolitan Area Council of Governments' => 12.00,
            'ParkSmart' => 14.50,
            'Toledo Area Humane Society' => 14.50,
            'HCC Rare Coins' => 7.00,
            'Byrne Paint Company' => 7.00,
            'Toledo Design Collective' => 7.00,
            'Clark Fixtures' => 7.00,
            'Spengler Nathanson' => 14.50,
            'SA Rail' => 7.00,
            'Dedicated School Staffing' => 7.00,
            'Advanced Control Solutions, Inc.' => 7.00,
            'The Mannik & Smith Group, Inc.' => 14.50,
            'Public Works Collaborative' => 7.00,
            'Dream Louder Music' => 7.00,
            'Off Contact' => 7.00,
            'Lennonheads' => 7.00,
            'Good Grief of Northwest Ohio' => 7.00,
            'Michael Allen' => 7.00,
            'Ramge Acres' => 7.00,
            'Toledo Lucas County Port Authority' => 7.00,
            'Creative Financial Partners' => 7.00,
            'Village of Ottawa Hills' => 58.00,
            'Starbound Talent' => 14.50,
            'Mercy College of Ohio' => 116.00,
            'Flint Institute of Arts' => 29.00,
            'Toledo Engineering Co., Inc.' => 7.00,
            'Mennel Milling' => 14.50,
            null => 118.50, // internal 70, artscommission, tfrd, cadt 14.50 each, game-on dev 5
        ],
        'AWS' => [
            'Bionix' => 1.29,
            'City of Toledo Communications' => 53.00,
            'Greater Toledo Community Foundation' => 1.36,
            'Detroit Public Library' => 30.22,
            'Pioneer Library System' => 1.39,
            'RJ Schinner' => 5.75,
            'Catholic Diocese of Toledo' => 4.64,
            'GM ASEP' => 0.10,
            "Robison, Curphey & O'Connell, LLC" => 0.04,
            'Harbor' => 0.04,
            'The Area Office on Aging of Northwestern Ohio, Inc.' => 2.91,
            'TRECA Digital Academy' => 3.08,
            'Health Partners of Western Ohio' => 0.01,
            'Grosse Pointe Public Library' => 21.09,
            'Toledo Mud Hens' => 1.74,
            'Maple Grove Companies' => 0.55,
            'Toledo Metropolitan Area Council of Governments' => 19.47,
            'ParkSmart' => 5.77,
            'Toledo Area Humane Society' => 30.34,
            'Toledo Design Collective' => 14.41,
            'Clark Fixtures' => 0.02,
            'Spengler Nathanson' => 0.25,
            'Dedicated School Staffing' => 0.07,
            'Advanced Control Solutions, Inc.' => 0.10,
            'The Mannik & Smith Group, Inc.' => 0.84,
            'Public Works Collaborative' => 0.56,
            'Dream Louder Music' => 0.78,
            'Lennonheads' => 0.09,
            'Ramge Acres' => 0.05,
            'Toledo Lucas County Port Authority' => 0.03,
            'Creative Financial Partners' => 0.72,
            'Village of Ottawa Hills' => 1.51,
            'Starbound Talent' => 0.38,
            'Mercy College of Ohio' => 5.22,
            'Flint Institute of Arts' => 13.91,
            'Mennel Milling' => 6.57,
            null => 28.73, // internal 5.23, artscommission 4.08, Toledo Museum of Art 18.17, madhouse-talent, nswash, floorcraft, reflective-lighting
        ],
    ];

    public function handle(ExpensePoster $poster): int
    {
        $year = (int) $this->argument('year');
        $hosting = ExpenseCategory::where('name', 'Hosting')->value('id');
        if (! $hosting) {
            $this->error('No Hosting expense category.');

            return self::FAILURE;
        }

        // Every client named, matched by name; stop before changing
        // anything if one is missing.
        $names = collect(self::WEIGHTS)->flatMap(fn ($w) => array_keys($w))->push(self::ANDERSONS)->filter()->unique();
        $ids = Company::whereIn('name', $names)->pluck('id', 'name');
        $missing = $names->diff($ids->keys());
        if ($missing->isNotEmpty()) {
            $this->error('No client named: '.$missing->implode('; '));

            return self::FAILURE;
        }

        $split = 0;
        foreach (self::WEIGHTS as $vendor => $weights) {
            $main = collect($weights)->map(fn ($weight, $name) => ['company_id' => $name === '' ? null : $ids[$name], 'weight' => $weight])->values();
            $andersons = collect([['company_id' => $ids[self::ANDERSONS], 'weight' => 1]]);

            $bills = Expense::where('category_id', $hosting)->where('name', $vendor)->whereYear('date', $year)
                ->withCount('splits')->orderBy('date')->get()
                ->groupBy(fn (Expense $e) => $e->date->format('Y-m'));

            foreach ($bills as $month => $pair) {
                if ($pair->count() !== 2) {
                    $this->warn("{$month} {$vendor}: {$pair->count()} bills, not the main and the Andersons' -- skipped");

                    continue;
                }
                [$big, $small] = $pair->sortByDesc(fn (Expense $e) => (float) $e->amount)->values()->all();
                foreach ([[$big, $main, 'main'], [$small, $andersons, 'Andersons']] as [$bill, $shares, $account]) {
                    if ($bill->splits_count > 0 && ! $this->option('force')) {
                        $this->line("{$month} {$vendor} ({$account}) \${$bill->amount}: already split -- left alone");

                        continue;
                    }
                    $rows = $this->scale($shares, (float) $bill->amount);
                    $this->line(sprintf('%s %s (%s) $%s: %d shares', $month, $vendor, $account, $bill->amount, count($rows)));
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    DB::transaction(function () use ($bill, $rows, $poster) {
                        $bill->unsetRelation('splits');
                        $bill->splits()->delete();
                        foreach ($rows as $row) {
                            $bill->splits()->create($row);
                        }
                        if ($bill->is_billable) {
                            $bill->update(['is_billable' => false]); // a split cost is never billed
                        }
                        $poster->sync($bill->fresh());
                    });
                    $split++;
                }
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run: nothing changed.' : "Split {$split} bills.");

        return self::SUCCESS;
    }

    // Weights fitted to a total in whole cents that add up exactly, the
    // leftover cents to the shares that lost most to rounding (as the
    // expense form's "Use last split" does).
    private function scale(Collection $shares, float $total): array
    {
        $target = (int) round($total * 100);
        $base = $shares->sum('weight');
        $exact = $shares->map(fn ($s) => $target * $s['weight'] / $base);
        $cents = $exact->map(fn ($v) => (int) floor($v))->all();
        $leftover = $target - array_sum($cents);
        foreach ($exact->map(fn ($v, $i) => ['i' => $i, 'rem' => $v - floor($v)])->sortByDesc('rem')->take($leftover) as $r) {
            $cents[$r['i']]++;
        }

        return $shares->values()->map(fn ($s, $i) => ['company_id' => $s['company_id'], 'amount' => $cents[$i] / 100])
            ->filter(fn ($row) => $row['amount'] > 0)->values()->all();
    }
}
