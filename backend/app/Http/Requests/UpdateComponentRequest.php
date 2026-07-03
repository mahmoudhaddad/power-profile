<?php

namespace App\Http\Requests;

class UpdateComponentRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'component_name'               => 'sometimes|string|max:255',
            'power_w'                      => 'sometimes|numeric|min:0.01|max:1000000',
            'phases'                       => 'sometimes|in:1phase,3phase',
            'power_factor'                 => 'sometimes|numeric|min:0.01|max:1',
            'quantity'                     => 'sometimes|integer|min:1',
            'priority'                     => 'sometimes|in:critical,essential,non_critical,normal',
            'needs_socket'                 => 'sometimes|boolean',
            'usage_season'                 => 'sometimes|in:summer,winter,all',
            'usage_day_type'               => 'sometimes|in:weekday,weekend,all',
            'usage_time_intervals'         => 'sometimes|array|min:1',
            'usage_time_intervals.*.start' => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'usage_time_intervals.*.end'   => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'group_name'                   => 'sometimes|nullable|string|max:255',
            'load_flexibility'             => 'sometimes|in:fixed,shiftable,curtailable',
            'required_run_hours'           => 'sometimes|nullable|integer|min:1|max:24',
            'earliest_start_hour'          => 'sometimes|nullable|integer|min:0|max:23',
            'latest_end_hour'              => 'sometimes|nullable|integer|min:1|max:24',
            'min_continuous_run'           => 'sometimes|nullable|integer|min:1|max:24',
            'max_interruptions'            => 'sometimes|nullable|integer|min:0|max:10',
            'curtail_min_pct'              => 'sometimes|nullable|integer|min:0|max:100',
        ];
    }
}
