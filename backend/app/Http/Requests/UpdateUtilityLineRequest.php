<?php

namespace App\Http\Requests;

class UpdateUtilityLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'                => 'sometimes|string|max:255',
            'power'               => 'sometimes|numeric|min:0|max:10000000',
            'phases'              => 'sometimes|in:1phase,3phase',
            'tariff_per_kwh'      => 'sometimes|nullable|numeric|min:0',
            'peak_tariff_per_kwh' => 'sometimes|nullable|numeric|min:0',
            'peak_hours_start'    => 'sometimes|nullable|integer|min:0|max:23',
            'peak_hours_end'      => 'sometimes|nullable|integer|min:0|max:23',
        ];
    }
}
