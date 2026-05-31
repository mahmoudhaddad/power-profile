<?php

namespace App\Http\Requests;

class StoreProjectRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'buildings_count' => 'sometimes|integer|min:0',
            'total_power'     => 'sometimes|string|max:50',
        ];
    }
}
