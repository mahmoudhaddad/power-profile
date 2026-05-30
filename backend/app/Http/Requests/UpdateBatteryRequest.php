<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBatteryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                  => 'sometimes|string|max:255',
            'chemistry'             => 'sometimes|string|in:lead_acid_flooded,lead_acid_agm,lead_acid_gel,lithium_lfp,lithium_nmc',
            'nominal_voltage_v'     => 'sometimes|numeric|min:1|max:1000',
            'capacity_ah_per_unit'  => 'sometimes|numeric|min:1|max:10000',
            'quantity'              => 'sometimes|integer|min:1|max:10000',
            'series_count'          => 'sometimes|integer|min:1|max:100',
            'parallel_count'        => 'sometimes|integer|min:1|max:100',
            'installation_date'     => 'sometimes|date|before_or_equal:today',
            'depth_of_discharge'    => 'sometimes|nullable|numeric|min:0.1|max:1.0',
            'round_trip_efficiency' => 'sometimes|nullable|numeric|min:0.5|max:1.0',
            'c_rate_charge'         => 'sometimes|nullable|numeric|min:0.01|max:5.0',
            'c_rate_discharge'      => 'sometimes|nullable|numeric|min:0.01|max:5.0',
            'rated_cycle_life'      => 'sometimes|nullable|integer|min:100|max:20000',
            'current_soc'           => 'sometimes|nullable|numeric|min:0.0|max:1.0',
            'is_active'             => 'sometimes|nullable|boolean',
            'notes'                 => 'sometimes|nullable|string|max:2000',
            'solar_system_id'       => 'sometimes|nullable|integer|exists:solar_systems,id',
        ];
    }
}
