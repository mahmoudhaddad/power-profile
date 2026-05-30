<?php

namespace App\Http\Requests;

class StoreRoomRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'type'              => 'sometimes|nullable|string',
            'area'              => 'required|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
        ];
    }
}
