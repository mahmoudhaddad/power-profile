<?php

namespace App\Http\Requests;

class StoreProjectRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'currency_symbol' => 'sometimes|string|max:5',
            'buildings_count' => 'sometimes|integer|min:0',
            'total_power'     => 'sometimes|string|max:50',
        ];
    }
}
