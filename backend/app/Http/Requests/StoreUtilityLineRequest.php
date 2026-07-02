<?php

namespace App\Http\Requests;

class StoreUtilityLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'power'               => 'required|numeric|min:0|max:10000000',
            'phases'              => 'required|in:1phase,3phase',
            'tariff_per_kwh'      => 'nullable|numeric|min:0',
            'peak_tariff_per_kwh' => 'nullable|numeric|min:0',
            'peak_hours_start'    => 'nullable|integer|min:0|max:23',
            'peak_hours_end'      => 'nullable|integer|min:0|max:23',
        ];
    }
}
