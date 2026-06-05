<?php

namespace App\Http\Requests;

class StoreGeneratorLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'                 => 'required|string|max:255',
            'power'                => 'required|numeric|min:0|max:10000000',
            'phases'               => 'required|in:1phase,3phase',
            'fuel_cost_per_liter'  => 'nullable|numeric|min:0',
            'fuel_consumption_lph' => 'nullable|numeric|min:0',
            'no_load_fuel_lph'     => 'nullable|numeric|min:0',
            'min_load_pct'         => 'sometimes|integer|min:0|max:100',
            'optimal_load_pct'     => 'sometimes|integer|min:0|max:100',
        ];
    }
}
