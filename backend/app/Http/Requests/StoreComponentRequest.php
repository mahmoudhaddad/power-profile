<?php

namespace App\Http\Requests;

class StoreComponentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'component_name'               => 'required|string|max:255',
            'power_w'                      => 'required|numeric|min:0.01|max:1000000',
            'phases'                       => 'sometimes|in:1phase,3phase',
            'power_factor'                 => 'sometimes|numeric|min:0.01|max:1',
            'quantity'                     => 'required|integer|min:1',
            'priority'                     => 'required|in:critical,essential,non_critical,normal',
            'needs_socket'                 => 'sometimes|boolean',
            'usage_season'                 => 'sometimes|in:summer,winter,all',
            'usage_day_type'               => 'sometimes|in:weekday,weekend,all',
            'usage_time_intervals'         => 'sometimes|array|min:1',
            'usage_time_intervals.*.start' => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'usage_time_intervals.*.end'   => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'group_name'                   => 'sometimes|nullable|string|max:255',
        ];
    }
}
