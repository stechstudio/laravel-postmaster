<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use STS\Postmaster\Models\EmailAddress;

/**
 * The dashboard landing page: headline counts and a recent-activity chart.
 */
class OverviewController extends Controller
{
    public function __invoke()
    {
        $byStatus = $this->messageQuery()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->view('postmaster::overview', [
            'total'      => $this->messageQuery()->count(),
            'byStatus'   => $byStatus,
            'suppressed' => $this->addressQuery()->where('status', EmailAddress::STATUS_SUPPRESSED)->count(),
            'chart'      => $this->dailyCounts(14),
        ]);
    }

    /**
     * Messages recorded per day over the trailing window, as a single
     * conditional-aggregation query — one table scan, and portable across
     * database engines (no engine-specific date functions).
     *
     * @param int $days
     *
     * @return array<int, array{date: \Illuminate\Support\Carbon, count: int}>
     */
    protected function dailyCounts( $days )
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $query = $this->messageQuery()->where('created_at', '>=', $start);

        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);

            $query->selectRaw(
                "sum(case when created_at >= ? and created_at < ? then 1 else 0 end) as d{$i}",
                [$day, $day->copy()->addDay()]
            );

            $buckets[$i] = ['date' => $day, 'count' => 0];
        }

        $row = $query->first();

        foreach ($buckets as $i => $bucket) {
            $buckets[$i]['count'] = (int) ($row->{'d'.$i} ?? 0);
        }

        return $buckets;
    }
}
