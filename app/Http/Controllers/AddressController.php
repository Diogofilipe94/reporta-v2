<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAddressRequest;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index()
    {
        $address = Address::all();

        return response()->json($address);
    }

    public function store(StoreAddressRequest $request)
    {
        $address = new Address();
        $address->id;
        $address->street = $request->street;
        $address->number = $request->number;
        $address->city = $request->city;
        $address->cp = $request->cp;
        $address->save();


        return response()->json($address, 201);
    }
}
