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

        $postmaster->unsuppress($address);

        return redirect()->route('postmaster.addresses')
            ->with('postmasterFlash', "Unsuppressed {$address}.");
    }
}
