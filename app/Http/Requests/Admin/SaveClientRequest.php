<?php

namespace App\Http\Requests\Admin;

use App\Rules\RealMailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isSave = $this->input('action') === 'save_client';
        $clientId = $this->integer('client_id');

        return [
            'action' => ['required', Rule::in(['save_client', 'set_status'])],
            'client_id' => [$isSave ? 'nullable' : 'required', 'integer', Rule::exists('clients', 'client_id')],
            'client_name' => [$isSave ? 'required' : 'nullable', 'string', 'max:150', Rule::unique('clients', 'client_name')->ignore($clientId, 'client_id')],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:120', new RealMailDomain],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'return_tab' => ['nullable', Rule::in(['overview', 'accounts', 'shipments'])],
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required' => 'Company name is required.',
            'client_name.unique' => 'A client company with that name already exists.',
            'email.email' => 'Enter a valid email address of at most 120 characters.',
            'status.in' => 'Choose a valid client status.',
        ];
    }
}
