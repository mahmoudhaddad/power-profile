<?php

namespace App\Http\Requests;

class UpdateGeneratorLineRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name'   => 'sometimes|string|max:255',
            'power'  => 'sometimes|numeric|min:0',
            'phases' => 'sometimes|in:1phase,3phase',
        ];
    }
}
