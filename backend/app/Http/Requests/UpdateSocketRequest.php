<?php

namespace App\Http\Requests;

class UpdateSocketRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phase_type' => 'sometimes|in:1phase,3phase',
            'power'      => 'sometimes|numeric|min:0.01',
            'quantity'   => 'sometimes|integer|min:1',
        ];
    }
}
