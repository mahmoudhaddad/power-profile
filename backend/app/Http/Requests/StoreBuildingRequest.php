<?php

namespace App\Http\Requests;

class StoreBuildingRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'floors'            => 'sometimes|integer|min:1',
            'area'              => 'required|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
        ];
    }
}
