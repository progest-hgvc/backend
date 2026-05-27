<?php

namespace App\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class PoloRequest extends BaseFormRequest
{
    public function rules()
    {
        $id = $this->input('id');

        return [
            'id'     => 'nullable|integer',
            'nome'   => 'required|string|min:3|max:191|unique:polos,nome,' . $id,
            'sigla'  => 'nullable|string|max:10',
            'status' => 'required|in:A,I'
        ];
    }

    public function messages()
    {
        return [
            'nome.required' => 'O nome do Polo é obrigatório.',
            'status.required' => 'O status do Polo é obrigatório.',
            'nome.min' => 'O nome deve ter no mínimo 3 caracteres.',
            'nome.max' => 'O nome deve ter no máximo 191 caracteres.',
            'nome.unique' => 'Este Polo já está cadastrado.',
            'status.in' => 'O status deve ser A (Ativo) ou I (Inativo).'
        ];
    }

    public function attributes()
    {
        return [
            'nome' => 'Nome',
            'status' => 'Status'
        ];
    }
}