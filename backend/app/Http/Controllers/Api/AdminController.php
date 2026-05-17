<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('name', $request->name)
                    ->where('is_admin', true)
                    ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'name' => ['Invalid admin credentials.'],
            ]);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }

    public function users()
    {
        $users = User::select('id', 'name', 'email', 'avatar', 'is_admin', 'created_at')
                     ->orderBy('created_at', 'desc')
                     ->get();

        return response()->json(['data' => $users]);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|unique:users,email,' . $user->id,
            'is_admin' => 'sometimes|boolean',
        ]);

        $user->update($data);

        return response()->json([
            'data' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'avatar'   => $user->avatar,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }

    public function deleteUser(User $user)
    {
        if ($user->is_admin) {
            return response()->json(['message' => 'Cannot delete an admin user.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }
}
