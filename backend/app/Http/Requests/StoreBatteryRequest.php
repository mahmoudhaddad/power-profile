<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatteryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                  => 'required|string|max:255',
            'chemistry'             => 'required|string|in:lead_acid_flooded,lead_acid_agm,lead_acid_gel,lithium_lfp,lithium_nmc',
            'nominal_voltage_v'     => 'required|numeric|min:1|max:1000',
            'capacity_ah_per_unit'  => 'required|numeric|min:1|max:10000',
            'quantity'              => 'required|integer|min:1|max:10000',
            'series_count'          => 'required|integer|min:1|max:100',
            'parallel_count'        => 'required|integer|min:1|max:100',
            'installation_date'     => 'required|date|before_or_equal:today',
            'depth_of_discharge'    => 'nullable|numeric|min:0.1|max:1.0',
            'round_trip_efficiency' => 'nullable|numeric|min:0.5|max:1.0',
            'c_rate_charge'         => 'nullable|numeric|min:0.01|max:5.0',
            'c_rate_discharge'      => 'nullable|numeric|min:0.01|max:5.0',
            'rated_cycle_life'      => 'nullable|integer|min:100|max:20000',
            'current_soc'           => 'nullable|numeric|min:0.0|max:1.0',
            'is_active'             => 'nullable|boolean',
            'notes'                 => 'nullable|string|max:2000',
            'solar_system_id'       => 'nullable|integer|exists:solar_systems,id',
        ];
    }
}
