<?php

namespace App\Http\Requests;

class UpdateBuildingRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'                            => 'sometimes|string|max:255',
            'floors'                          => 'sometimes|integer|min:1',
            'area'                            => 'sometimes|numeric|min:0.01',
            'power_consumption'               => 'sometimes|string|max:50',
            'solar_power'                     => 'sometimes|nullable|numeric|min:0',
            'existing_solar_power'            => 'sometimes|nullable|numeric|min:0',
            'solar_source'                    => 'sometimes|in:max,existing',
            'generator_power'                 => 'sometimes|nullable|numeric|min:0',
            'work_days'                       => 'sometimes|nullable|array',
            'work_days.*'                     => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'work_time_intervals'             => 'sometimes|nullable|array',
            'work_time_intervals.*.start'     => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'work_time_intervals.*.end'       => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'working_season_intervals'        => 'sometimes|nullable|array',
            'working_season_intervals.*.from' => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
            'working_season_intervals.*.to'   => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
        ];
    }
}
