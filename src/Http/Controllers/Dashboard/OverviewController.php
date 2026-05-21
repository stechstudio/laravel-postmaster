<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use STS\Postmaster\Models\EmailAddress;

/**
 * The dashboard landing page: headline counts and an activity chart over a
 * selectable time window.
 */
class OverviewController extends Controller
{
    /**
     * Selectable chart windows, in days.
     *
     * @var array<int, int>
     */
    protected $ranges = [7, 30, 90, 365];

    public function __invoke()
    {
        $days = (int) request()->query('days', 30);

        if (! in_array($days, $this->ranges, true)) {
            $days = 30;
        }

        $byStatus = $this->messageQuery()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->view('postmaster::overview', [
            'total'      => $this->messageQuery()->count(),
            'byStatus'   => $byStatus,
            'suppressed' => $this->addressQuery()->where('status', EmailAddress::STATUS_SUPPRESSED)->count(),
            'chart'      => $this->activity($days),
            'days'       => $days,
            'ranges'     => $this->ranges,
        ]);
    }

    /**
     * Message counts bucketed across the window — daily for short ranges,
     * weekly or monthly for longer ones so the bar count stays readable.
     * One conditional-aggregation query, portable across database engines.
     *
     * @param int $days
     *
     * @return array<int, array{date: \Illuminate\Support\Carbon, count: int, interval: int}>
     */
    protected function activity( $days )
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
