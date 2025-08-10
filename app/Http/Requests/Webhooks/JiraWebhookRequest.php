<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Form request for validating incoming Jira webhooks.
 * Validates signature and payload structure.
 */
class JiraWebhookRequest extends FormRequest
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
            'webhookEvent' => ['required', 'string'],
            'issue' => ['required', 'array'],
            'issue.key' => ['required', 'string'],
            'issue.fields' => ['required', 'array'],
            'issue.fields.summary' => ['nullable', 'string'],
            'issue.fields.description' => ['nullable'],
            'issue.fields.priority' => ['nullable', 'array'],
            'issue.fields.priority.name' => ['nullable', 'string'],
            'issue.fields.status' => ['nullable', 'array'],
            'issue.fields.status.name' => ['nullable', 'string'],
            'issue.fields.labels' => ['nullable', 'array'],
            'issue.fields.labels.*' => ['string'],
            'issue.fields.issuetype' => ['nullable', 'array'],
            'issue.fields.issuetype.name' => ['nullable', 'string'],
            'issue.fields.project' => ['nullable', 'array'],
            'issue.fields.project.key' => ['nullable', 'string'],
            'issue.fields.assignee' => ['nullable', 'array'],
            'issue.fields.assignee.emailAddress' => ['nullable', 'email'],
            'issue.fields.reporter' => ['nullable', 'array'],
            'issue.fields.reporter.emailAddress' => ['nullable', 'email'],
            'user' => ['nullable', 'array'],
            'user.emailAddress' => ['nullable', 'email'],
            'changelog' => ['nullable', 'array'],
            'comment' => ['nullable', 'array'],
        ];
    }

    /**
     * Verify webhook signature.
     */
    private function hasValidSignature(): bool
    {
        // Jira webhooks can use different auth methods:
        // 1. Basic auth (check Authorization header)
        // 2. JWT (check token)
        // 3. Shared secret (HMAC signature)

        $secret = config('services.jira.webhook_secret');

        // If no secret is configured, check for project-specific token in URL
        if (! $secret) {
            Log::warning('No Jira webhook secret configured, accepting webhook');

            return true; // In development, accept all
        }

        // Check for Atlassian Connect JWT
        if ($jwt = $this->query('jwt')) {
            return $this->verifyJWT($jwt, $secret);
        }

        // Check for shared secret signature
        if ($signature = $this->header('X-Hub-Signature')) {
            return $this->verifyHMACSignature($signature, $secret);
        }

        // Check basic auth
        if ($auth = $this->header('Authorization')) {
            return $this->verifyBasicAuth($auth, $secret);
        }

        Log::warning('No authentication method found for Jira webhook');

        return false;
    }

    /**
     * Verify JWT token.
     */
    private function verifyJWT(string $jwt, string $secret): bool
    {
        // TODO: Implement JWT verification for Atlassian Connect
        // For now, just check if JWT is present
        return ! empty($jwt);
    }

    /**
     * Verify HMAC signature.
     */
    private function verifyHMACSignature(string $signature, string $secret): bool
    {
        $payload = $this->getContent();
        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify basic authentication.
     */
    private function verifyBasicAuth(string $auth, string $secret): bool
    {
        // Extract credentials from Basic auth header
        if (strpos($auth, 'Basic ') !== 0) {
            return false;
        }

        $credentials = base64_decode(substr($auth, 6));
        [$username, $password] = explode(':', $credentials, 2);

        // Compare with configured credentials
        return $username === config('services.jira.webhook_username')
            && $password === $secret;
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'webhookEvent.required' => 'Webhook event type is required.',
            'issue.key.required' => 'Issue key is required.',
            'issue.fields.required' => 'Issue fields are required.',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Invalid webhook signature or authentication.'
        );
    }
}
