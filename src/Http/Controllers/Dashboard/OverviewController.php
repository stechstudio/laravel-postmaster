<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Response;
use STS\Postmaster\Models\EmailAddress;

/**
 * The dashboard landing page: headline counts and an activity chart over a
 * selectable time window.
 */
class OverviewController extends Controller
{
    /**
     * Selectable timeframe windows: days => label.
     *
     * @var array<int, string>
     */
    protected array $ranges = [
        7   => '7 days',
        30  => '30 days',
        90  => '90 days',
        365 => '1 year',
    ];

    public function __invoke(): Response
    {
        $days = (int) request()->query('days', 30);

        if (! array_key_exists($days, $this->ranges)) {
            $days = 30;
        }

        // The headline stats are constrained to the selected window.
        $since = now()->subDays($days - 1)->startOfDay();

        $byStatus = $this->messageQuery()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $recentActivity = $this->recentActivity(0, 8);

        return response()->view('postmaster::overview', [
            'total'          => $this->messageQuery()->where('created_at', '>=', $since)->count(),
            'byStatus'       => $byStatus,
            'suppressed'     => $this->addressQuery()
                ->where('status', EmailAddress::STATUS_SUPPRESSED)
                ->where('suppressed_at', '>=', $since)
                ->count(),
            'chart'          => $this->messageBuckets($days),
            'days'           => $days,
            'ranges'         => $this->ranges,
            'recentMessages' => $this->messageQuery()->latest()->limit(8)->get(),
            'recentActivity' => $recentActivity->map(fn ($entry) => $this->presentActivity($entry))->values(),
            'recentLastId'   => $recentActivity->max('id') ?? 0,
        ]);
    }

    /**
     * Message counts bucketed across the window — daily for short ranges,
     * weekly or monthly for longer ones so the bar count stays readable.
     * One conditional-aggregation query, portable across database engines.
     *
     * @return array<int, array{date: \Illuminate\Support\Carbon, count: int, interval: int}>
     */
    protected function messageBuckets(int $days): array
    {
        $interval = match (true) {
            $days <= 31  => 1,
            $days <= 182 => 7,
            default      => 30,
        };

        $count = (int) ceil($days / $interval);
        $start = now()->subDays(($count * $interval) - 1)->startOfDay();
        $query = $this->messageQuery()->where('created_at', '>=', $start);

        $buckets = [];

        for ($i = 0; $i < $count; $i++) {
            $from = $start->copy()->addDays($i * $interval);

            $query->selectRaw(
                "sum(case when created_at >= ? and created_at < ? then 1 else 0 end) as d{$i}",
                [$from, $from->copy()->addDays($interval)]
            );

            $buckets[$i] = ['date' => $from, 'count' => 0, 'interval' => $interval];
        }

        $row = $query->first();

        foreach ($buckets as $i => $bucket) {
            $buckets[$i]['count'] = (int) ($row->{'d'.$i} ?? 0);
        }

        return $buckets;
    }
}
