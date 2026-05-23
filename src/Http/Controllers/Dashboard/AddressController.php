<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;
use STS\Postmaster\Postmaster;

/**
 * The suppression list — every tracked recipient address and its status.
 * Listed read-only by index(); a single unsuppress() POST endpoint lets
 * an operator lift a suppression, which Postmaster::unsuppress() mirrors
 * out to every configured provider's API.
 */
class AddressController extends Controller
{
    public function index( Request $request )
    {
        $query = $this->addressQuery()->orderByDesc('updated_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $this->applyContains($query, 'address', $request->query('address'));

        return response()->view('postmaster::addresses', [
            'addresses' => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
        ]);
    }

    /**
     * Lift the suppression on an address, locally and at every configured
     * provider. The actual provider call goes through Postmaster::unsuppress(),
     * which iterates every provider's SuppressionSync and asks it to drop
     * the address from its list.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function unsuppress( Request $request, Postmaster $postmaster )
    {
        $address = trim((string) $request->input('address'));

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('postmaster.addresses')
                ->with('postmasterError', 'That is not a valid email address.');
        }

        $result = $postmaster->unsuppress($address);

        return redirect()->route('postmaster.addresses')
            ->with('postmasterFlash', $this->flashFor($address, $result));
    }

    /**
     * Build a single-line flash message describing what got cleared and
     * what still needs manual cleanup at the provider's dashboard.
     *
     * @param string $address
     * @param array{cleared: array<int, string>, manual: array<int, string>} $result
     *
     * @return string
     */
    protected function flashFor( string $address, array $result ): string
    {
        $parts = ["Unsuppressed {$address}."];

        if (! empty($result['cleared'])) {
            $parts[] = 'Cleared at: '.implode(', ', $result['cleared']).'.';
        }

        if (! empty($result['manual'])) {
            $parts[] = 'Manual cleanup needed at: '.implode(', ', $result['manual']).'.';
        }

        return implode(' ', $parts);
    }
}
