<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The suppression list — every tracked recipient address and its status,
 * with suppress / unsuppress actions for handling support requests.
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
            $query->where('address', 'like', $address.'%');
        }

        return response()->view('postmaster::addresses', [
            'addresses' => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
        ]);
    }

    public function suppress( Request $request, $address )
    {
        $this->addressQuery()->findOrFail($address)->suppress();

        return redirect()->route('postmaster.addresses', $request->query());
    }

    public function unsuppress( Request $request, $address )
    {
        $this->addressQuery()->findOrFail($address)->unsuppress();

        return redirect()->route('postmaster.addresses', $request->query());
    }
}
