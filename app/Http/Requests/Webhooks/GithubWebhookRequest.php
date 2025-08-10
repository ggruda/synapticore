<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Form request for validating incoming GitHub webhooks.
 * Validates signature and payload structure.
 */
class GithubWebhookRequest extends FormRequest
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
        $event = $this->header('X-GitHub-Event');

        // Base rules for all events
        $rules = [
            'action' => ['nullable', 'string'],
            'sender' => ['nullable', 'array'],
            'sender.login' => ['nullable', 'string'],
            'repository' => ['nullable', 'array'],
            'repository.full_name' => ['nullable', 'string'],
        ];

        // Add event-specific rules
        return match ($event) {
            'pull_request' => array_merge($rules, $this->pullRequestRules()),
            'push' => array_merge($rules, $this->pushRules()),
            'issues' => array_merge($rules, $this->issueRules()),
            'issue_comment' => array_merge($rules, $this->issueCommentRules()),
            default => $rules,
        };
    }

    /**
     * Validation rules for pull request events.
     */
    private function pullRequestRules(): array
    {
        return [
            'pull_request' => ['required', 'array'],
            'pull_request.id' => ['required', 'integer'],
            'pull_request.number' => ['required', 'integer'],
            'pull_request.state' => ['required', 'string'],
            'pull_request.title' => ['required', 'string'],
            'pull_request.body' => ['nullable', 'string'],
            'pull_request.html_url' => ['required', 'url'],
            'pull_request.head' => ['required', 'array'],
            'pull_request.head.ref' => ['required', 'string'],
            'pull_request.base' => ['required', 'array'],
            'pull_request.base.ref' => ['required', 'string'],
            'pull_request.draft' => ['nullable', 'boolean'],
            'pull_request.labels' => ['nullable', 'array'],
            'pull_request.labels.*.name' => ['string'],
        ];
    }

    /**
     * Validation rules for push events.
     */
    private function pushRules(): array
    {
        return [
            'ref' => ['required', 'string'],
            'before' => ['required', 'string'],
            'after' => ['required', 'string'],
            'commits' => ['nullable', 'array'],
            'commits.*.id' => ['string'],
            'commits.*.message' => ['string'],
            'commits.*.author' => ['array'],
            'commits.*.author.name' => ['string'],
            'commits.*.author.email' => ['email'],
        ];
    }

    /**
     * Validation rules for issue events.
     */
    private function issueRules(): array
    {
        return [
            'issue' => ['required', 'array'],
            'issue.number' => ['required', 'integer'],
            'issue.title' => ['required', 'string'],
            'issue.body' => ['nullable', 'string'],
            'issue.state' => ['required', 'string'],
            'issue.labels' => ['nullable', 'array'],
            'issue.labels.*.name' => ['string'],
        ];
    }

    /**
     * Validation rules for issue comment events.
     */
    private function issueCommentRules(): array
    {
        return [
            'issue' => ['required', 'array'],
            'issue.number' => ['required', 'integer'],
            'comment' => ['required', 'array'],
            'comment.id' => ['required', 'integer'],
            'comment.body' => ['required', 'string'],
            'comment.user' => ['required', 'array'],
            'comment.user.login' => ['required', 'string'],
        ];
    }

    /**
     * Verify webhook signature.
     */
    private function hasValidSignature(): bool
    {
        $signature = $this->header('X-Hub-Signature-256');

        if (! $signature) {
            Log::warning('No GitHub webhook signature found');

            return false;
        }

        $secret = config('services.github.webhook_secret');

        if (! $secret) {
            Log::warning('No GitHub webhook secret configured');

            // In development, you might want to return true here
            return app()->environment('local');
        }

        $payload = $this->getContent();
        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $valid = hash_equals($expectedSignature, $signature);

        if (! $valid) {
            Log::warning('Invalid GitHub webhook signature', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
        }

        return $valid;
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pull_request.required' => 'Pull request data is required for PR events.',
            'issue.required' => 'Issue data is required for issue events.',
            'comment.required' => 'Comment data is required for comment events.',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Invalid GitHub webhook signature.'
        );
    }
}
