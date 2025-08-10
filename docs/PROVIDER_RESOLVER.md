# Provider Resolver & Plan Comments

## Overview

This document describes the Provider Resolver system that allows per-project configuration overrides for different providers, and the plan comment posting functionality for ticket systems.

## Features Implemented

### 1. Provider Resolver (Prompt 15)

The `ProviderResolver` service allows different projects to use different providers without code changes:

- **Location**: `app/Services/Resolution/ProviderResolver.php`
- **Purpose**: Dynamically resolve provider implementations based on project-specific overrides or system defaults

#### How It Works

1. **Project Overrides**: Each project can have a `provider_overrides` JSON field that specifies which providers to use
2. **Fallback to System Config**: If no override exists, falls back to `config/synaptic.php` defaults
3. **Dynamic Instantiation**: Creates provider instances with appropriate configuration

#### Supported Provider Types

- `ticket_provider`: Jira, Linear, Azure DevOps
- `vcs_provider`: GitHub, GitLab, Bitbucket
- `ai.planner`: OpenAI, Anthropic, Azure OpenAI, Local AI
- `ai.implement`: OpenAI, Anthropic, Azure OpenAI, Local AI
- `ai.review`: OpenAI, Anthropic, Azure OpenAI, Local AI
- `embeddings`: pgvector, Pinecone, Weaviate, Qdrant
- `runner`: Docker, Kubernetes
- `notify`: Mail, Slack, Teams, Discord

#### Example Configuration

```php
// In projects table, provider_overrides column:
{
    "ticket_provider": "linear",
    "vcs_provider": "gitlab",
    "ai": {
        "planner": "anthropic",
        "implement": "anthropic",
        "review": "openai"
    },
    "embeddings": "pinecone",
    "runner": "kubernetes",
    "notify": "slack"
}
```

### 2. Plan Comment Posting (Prompt 16)

Fully implemented system for posting formatted plan comments to ticket systems:

#### Components

1. **TicketCommentFormatter** (`app/Services/Tickets/TicketCommentFormatter.php`)
   - Converts `PlanJson` DTOs to beautifully formatted Markdown
   - Includes emojis, risk assessment, time estimates, implementation steps
   - Formats dependencies, references, and acceptance criteria

2. **JiraTicketProvider** (`app/Services/Tickets/JiraTicketProvider.php`)
   - Implements `addComment()` with full Jira API integration
   - Includes retry logic with exponential backoff
   - Converts Markdown to Jira wiki format
   - Handles network errors and transient failures

3. **PlanTicketJob** (`app/Jobs/PlanTicketJob.php`)
   - Posts plan as comment after successful validation
   - Respects `config('synaptic.tickets.post_plan_comment')` setting
   - Tracks success/failure in workflow metadata

#### Comment Format

The generated comment includes:
- 📋 Summary section
- ⚠️ Risk assessment with color-coded levels
- ⏱️ Time estimates (hours/days)
- 📝 Implementation plan with numbered steps
- 🧪 Test strategy
- 📦 Dependencies list
- 🔗 References and documentation links
- Timestamp and next steps

#### Jira Integration Features

- **Basic Auth**: Uses username/token authentication
- **Retry Logic**: 3 attempts with exponential backoff
- **Format Conversion**: Markdown → Jira wiki markup
- **Emoji Mapping**: Converts emojis to Jira equivalents
- **Error Handling**: Logs failures without breaking workflow

## Usage

### Using Provider Resolver in Jobs/Controllers

```php
use App\Services\Resolution\ProviderResolver;
use App\Contracts\TicketProviderContract;

class MyJob
{
    public function handle(ProviderResolver $resolver)
    {
        $project = Project::find(1);
        
        // Resolve provider for this project
        $ticketProvider = $resolver->resolveForProject(
            $project, 
            TicketProviderContract::class
        );
        
        // Use the provider
        $ticketProvider->addComment('JIRA-123', 'Hello from resolver!');
    }
}
```

### Testing Provider Resolution

```bash
# Test provider overrides
php artisan resolver:test --test-overrides

# Test plan comment formatting
php artisan resolver:test --test-comment

# Test actual comment posting (requires valid project)
php artisan resolver:test --test-comment --project-id=1
```

### Configuration

#### Enable/Disable Plan Comments

```php
// config/synaptic.php
'tickets' => [
    'post_plan_comment' => env('SYNAPTIC_POST_PLAN_COMMENT', true),
],
```

#### Configure Jira Credentials

```php
// config/services.php
'jira' => [
    'url' => env('JIRA_URL', 'https://your-org.atlassian.net'),
    'username' => env('JIRA_USERNAME'),
    'token' => env('JIRA_TOKEN'),
],
```

## Benefits

### Per-Project Flexibility

- **Project A**: Can use GitHub + OpenAI
- **Project B**: Can use GitLab + Claude
- **Project C**: Can use Bitbucket + Azure OpenAI

All without any code changes, just configuration!

### Immediate Feedback

- Plans are posted directly to tickets
- Stakeholders see analysis results immediately
- Comments are formatted for readability
- Risk assessment helps with prioritization

### Robust Integration

- Retry logic handles transient failures
- Format conversion ensures compatibility
- Error tracking prevents workflow disruption
- Extensible to other ticket systems

## Example Output

When a plan is generated, the following comment is posted to the ticket:

```markdown
## 🤖 Synapticore Analysis Complete

### 📋 Summary
Implement user authentication with JWT tokens

### ⚠️ Risk Assessment
🟡 **Medium Risk** - Changes affecting multiple components

### ⏱️ Estimated Time
**4.5 hours**

### 📝 Implementation Plan

#### Step 1: Create authentication middleware
Add JWT authentication middleware to validate tokens

**Files to modify:**
- `app/Http/Middleware/JwtAuth.php`

**Rationale:** Secure API endpoints with token validation

**Acceptance Criteria:**
- [ ] Middleware validates JWT tokens
- [ ] Invalid tokens return 401 response
- [ ] Token expiry is checked

...
```

## Troubleshooting

### Provider Resolution Errors

1. **"Unknown provider"**: Check that the provider name in overrides matches exactly
2. **"No provider configured"**: Ensure either project override or system config is set
3. **Constructor errors**: Verify required credentials are configured in `config/services.php`

### Comment Posting Failures

1. **401 Unauthorized**: Check Jira credentials in `.env`
2. **404 Not Found**: Verify the ticket key exists in Jira
3. **Network errors**: Check connectivity to Jira instance
4. **Format issues**: Review the Markdown to Jira conversion in logs

## Future Enhancements

1. **More Ticket Systems**: Add support for GitHub Issues, Azure Boards
2. **Rich Formatting**: Use Atlassian Document Format (ADF) for better Jira formatting
3. **Attachments**: Include diagrams and charts in comments
4. **Two-way Sync**: Update plans based on ticket comments
5. **Templates**: Customizable comment templates per project