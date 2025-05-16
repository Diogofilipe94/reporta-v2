<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $emailExists = User::where("email", $request->email)->exists();

        if($emailExists) {
            return response()->json([
                "error" => "Email already registered"
            ], 400);
        }

        $user = new User();
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->email = $request->email;
        $user->telephone = $request->telephone;
        $user->password = Hash::make($request->password);
        $user->address_id = $request->address_id;
        $user->role_id = 1; // role "user" por default, mais tarde pode ser modificado para "curator" ou "admin"
        $user->save();

        $token = JWTAuth::claims([
            "role" => $user->role->role
        ])->fromUser($user);

        return response()->json([
            "user" => $user,
            "token" => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $login = JWTAuth::attempt([
            "email" => $request->email,
            "password" => $request->password
        ]);

        if(!$login) {
            return response()->json([
                "error" => "Wrong credentials"
            ], 400);
        }

        $user = auth()->user();

        $token = JWTAuth::claims([
            "role" => $user->role->role
        ])->fromUser($user);

        return response()->json([
            "token" => $token
        ]);
    }

    public function getUser()
    {
        $user = auth()->user()->load('address');

        return response()->json([
            "user" => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'created_at' => $user->created_at,
                'address' => $user->address ? [
                    'street' => $user->address->street,
                    'number' => $user->address->number,
                    'cp' => $user->address->cp,
                    'city' => $user->address->city,

                    ] : null,
            ]
        ]);
    }

    public function updateUser(UpdateProfileRequest $request)
    {
        $user = auth()->user();

        $user->fill($request->safe()->only([
            'first_name',
            'last_name',
            'telephone',
            'address_id',
            'email'
        ]));

        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            "user" => $user->only(['first_name', 'last_name', 'email', 'telephone'])
        ]);
    }
}
