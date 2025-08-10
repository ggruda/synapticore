<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for validating incoming ticket webhooks.
 *
 * All external payloads must be validated through Form Requests.
 */
final class TicketWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Verify webhook signature
        return $this->hasValidSignature();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:created,updated,commented,status_changed,assigned'],
            'issue' => ['required', 'array'],
            'issue.key' => ['required', 'string', 'regex:/^[A-Z]+-\d+$/'],
            'issue.fields' => ['required', 'array'],
            'issue.fields.summary' => ['required', 'string', 'max:255'],
            'issue.fields.description' => ['nullable', 'string'],
            'issue.fields.priority' => ['required', 'array'],
            'issue.fields.priority.name' => ['required', 'string'],
            'issue.fields.status' => ['required', 'array'],
            'issue.fields.status.name' => ['required', 'string'],
            'issue.fields.labels' => ['nullable', 'array'],
            'issue.fields.labels.*' => ['string'],
            'user' => ['nullable', 'array'],
            'user.emailAddress' => ['nullable', 'email'],
            'timestamp' => ['required', 'integer'],
        ];
    }

    /**
     * Verify webhook signature.
     */
    private function hasValidSignature(): bool
    {
        $signature = $this->header('X-Webhook-Signature');
        if (! $signature) {
            return false;
        }

        $secret = config('services.webhooks.secret');
        $payload = $this->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event.in' => 'Invalid webhook event type.',
            'issue.key.regex' => 'Invalid issue key format. Expected format: PROJECT-123',
            'issue.fields.summary.required' => 'Issue summary is required.',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Invalid webhook signature.'
        );
    }
}
