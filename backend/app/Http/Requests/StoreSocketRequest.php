<?php

namespace App\Http\Requests;

class StoreSocketRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phase_type' => 'required|in:1phase,3phase',
            'power'      => 'required|numeric|min:0.01',
            'quantity'   => 'required|integer|min:1',
        ];
    }
}
