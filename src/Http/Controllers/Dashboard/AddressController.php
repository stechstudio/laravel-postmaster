<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The suppression list — every tracked recipient address and its status.
 *
 * Read-only for now: clearing a suppression has to be synced with the
 * provider to mean anything, which waits on provider-API integration.
 */
class AddressController extends Controller
{
    public function index( Request $request )
    {
        $query = $this->addressQuery()->orderByDesc('updated_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($address = $request->query('address')) {
            // Case-insensitive "contains" — lower() is portable across the
            // database engines the package supports.
            $query->whereRaw('lower(address) like ?', ['%'.strtolower((string) $address).'%']);
        }

        return response()->view('postmaster::addresses', [
            'addresses' => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
        ]);
    }
}
