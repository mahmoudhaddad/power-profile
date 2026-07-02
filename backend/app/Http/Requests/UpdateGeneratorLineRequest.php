<?php

namespace App\Http\Requests;

class UpdateGeneratorLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'                 => 'sometimes|string|max:255',
            'power'                => 'sometimes|numeric|min:0|max:10000000',
            'phases'               => 'sometimes|in:1phase,3phase',
            'fuel_cost_per_liter'  => 'sometimes|nullable|numeric|min:0',
            'fuel_consumption_lph' => 'sometimes|nullable|numeric|min:0',
            'no_load_fuel_lph'     => 'sometimes|nullable|numeric|min:0',
            'min_load_pct'         => 'sometimes|integer|min:0|max:100',
            'optimal_load_pct'     => 'sometimes|integer|min:0|max:100',
        ];
    }
}
