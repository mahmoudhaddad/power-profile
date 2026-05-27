<?php

namespace App\Http\Requests;

class StoreGeneratorLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'   => 'required|string|max:255',
            'power'  => 'required|numeric|min:0',
            'phases' => 'required|in:1phase,3phase',
        ];
    }
}
