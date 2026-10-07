<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Services\GoalProgressService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999999999'],
            // current_amount is not accepted: it is derived from the APE of policies issued in the range (GoalProgressService).
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(Goal::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return ['target_date.after_or_equal' => 'Date to cannot be before Date from.'];
    }

    public function attributes(): array
    {
        return ['start_date' => 'date from', 'target_date' => 'date to'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // An "achieved" goal must have actually reached its target (using the derived amount).
            if ($this->input('status') === 'achieved') {
                $current = app(GoalProgressService::class)->amountFor(
                    $this->date('start_date'),
                    $this->date('target_date'),
                );

                if ($current < (float) $this->input('target_amount')) {
                    $validator->errors()->add('status', 'A goal can only be marked achieved once the current amount reaches the target.');
                }
            }

        }];
    }
}
