<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\BodyContext;
use EdutrustPay\Contract\ContractVersion;
use Illuminate\Validation\Rule;

class InstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('institution') ? 'institution.update' : 'institution.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $institution = $this->route('institution');

        return [
            // Never into a body this person cannot see.
            'body_id' => ['required', Rule::in(BodyContext::accessibleIds())],
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191', 'alpha_dash', Rule::unique('institutions', 'slug')->ignore($institution?->id)],

            // The type decides the edition, and the edition decides which
            // capabilities are even applicable — a business has no enrolment.
            'type' => ['required', Rule::in(\App\Support\InstitutionType::all())],
            'system' => ['required', Rule::in(['edutrustshsm', 'paxhitestbed', 'posinnova'])],

            'endpoint' => ['nullable', 'url', 'max:191'],
            'connectivity_mode' => ['required', Rule::in(['push', 'tunnel'])],
            // A version this server cannot read is not a staged rollout, it is a
            // typo that goes unnoticed until the institution's reports bounce.
            'contract_version' => ['required', Rule::in(ContractVersion::SUPPORTED)],
            'city' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'not_reporting_days' => ['nullable', 'integer', 'between:1,365'],
            // Nullable means "inherit the body's"; the form says so.
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'live_from' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body_id' => __('body'),
            'not_reporting_days' => __('silence threshold'),
            'fiscal_year_start_month' => __('fiscal year start'),
            'connectivity_mode' => __('connectivity'),
        ];
    }
}
