<?php

namespace App\Http\Requests\Admin;

use App\Rules\RealMailDomain;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $action = $this->input('action');
        $userId = $this->integer('user_id');
        $isSave = in_array($action, ['add', 'edit'], true);

        return [
            'action' => ['required', Rule::in(['add', 'edit', 'toggle', 'delete'])],
            'user_id' => [$action === 'add' ? 'nullable' : 'required', 'integer', Rule::exists('users', 'user_id')],
            'full_name' => [$isSave ? 'required' : 'nullable', 'string', 'min:2', 'max:100'],
            'email' => [$isSave ? 'required' : 'nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($userId, 'user_id'), new RealMailDomain],
            'password' => [$action === 'add' ? 'required' : 'nullable', 'string', 'min:8', 'max:72'],
            'role' => [$isSave ? 'required' : 'nullable', Rule::in(['admin', 'staff', 'client'])],
            'status' => [$isSave ? 'required' : 'nullable', Rule::in(['active', 'inactive'])],
            'client_id' => [$isSave && $this->input('role') === 'client' ? 'required' : 'nullable', 'integer', Rule::exists('clients', 'client_id')],
            'confirm_text' => [$action === 'delete' ? 'required' : 'nullable', Rule::in(['DELETE'])],
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! PhoneNumber::isMobile((string) $value)) {
                    $fail('Enter a Philippine mobile number like 09171234567.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.min' => 'Enter a full name with at least 2 characters.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address is already in use.',
            'password.min' => 'Password must be 8 to 72 characters.',
            'password.max' => 'Password must be 8 to 72 characters.',
            'client_id.required' => 'Choose the client company for this account.',
            'confirm_text.in' => 'Type DELETE to confirm account deletion.',
        ];
    }
}
