<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * PUT /api/user/profile
     *
     * Updates the accreditation profile fields of the authenticated user only
     * (no user id is accepted, so no cross-user writes are possible).
     */
    public function update(Request $request): UserResource
    {
        $user = auth('api')->user();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'branch' => ['nullable', 'string', Rule::in(['print', 'tv', 'online', 'radio', 'photo', 'other'])],
            'position' => ['nullable', 'string', 'max:255'],
            'vest_available' => ['nullable', 'boolean'],
            'vest_number' => ['nullable', 'string', 'max:50'],
        ]);

        $user->update($validated);

        return (new UserResource($user->fresh(['roles', 'media'])))
            ->additional(['message' => 'Profil aktualisiert.']);
    }
}
