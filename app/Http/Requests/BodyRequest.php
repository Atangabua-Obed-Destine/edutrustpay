<?php

namespace App\Http\Requests;

use App\Models\Body;
use App\Services\BodyProvisioningService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $body = $this->route('body');

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191', 'alpha_dash', Rule::unique('bodies', 'slug')->ignore($body?->id)],
            /*
             * A free label, not a classification. Nothing branches on it, and a
             * closed list cannot describe a Regional Delegation, a congregation
             * or a trust — all of which are real bodies with institutions under
             * them. See the migration that opened this up.
             */
            'type' => ['nullable', 'string', 'max:60'],
            'parent_body_id' => ['nullable', 'exists:bodies,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'currency' => ['required', 'string', 'size:3'],
            // Nullable means "use the platform default"; the form says so.
            'not_reporting_days' => ['nullable', 'integer', 'between:1,365'],
            'is_active' => ['boolean'],

            /*
             * Identity. Both optional, and both inherited down the tree when
             * unset, so a deanery normally sets neither.
             *
             * The crest is size-capped and restricted to real image mimes: this
             * is the only file the platform accepts from anybody, and it is
             * served back to every user of the body.
             */
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:512'],
            'accent_colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $body = $this->route('body');

            if (! $body instanceof Body) {
                return;
            }

            // A body cannot be its own ancestor. Without this the path rebuild
            // recurses until memory runs out and leaves the tree half-written.
            if (app(BodyProvisioningService::class)->wouldCycle($body, $this->input('parent_body_id') ? (int) $this->input('parent_body_id') : null)) {
                $validator->errors()->add(
                    'parent_body_id',
                    __('A body cannot sit inside itself or inside one of its own deaneries.')
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_body_id' => __('parent body'),
            'fiscal_year_start_month' => __('fiscal year start'),
            'not_reporting_days' => __('silence threshold'),
            'accent_colour' => __('accent colour'),
            'logo' => __('crest'),
        ];
    }
}
