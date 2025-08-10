# Worklog Tracking & Sync

## Overview

This document describes the worklog tracking system that automatically records time spent on different phases of work and optionally syncs to external ticket systems like Jira.

## Features Implemented (Prompt 17)

### 1. Time Tracking Service

The `TrackedSection` service (`app/Services/Time/TrackedSection.php`) provides comprehensive time tracking:

#### Synchronous Tracking

```php
$tracker->run($ticket, 'plan', function () {
    // Your work here
    return $result;
}, 'Optional notes');
```

- Automatically captures start/end times
- Computes duration in seconds
- Persists to database
- Returns the closure result
- Handles exceptions properly

#### Asynchronous Tracking

```php
// Start tracking
$worklog = $tracker->startAsync($ticket, 'implement', 'Starting implementation');

// Do work...

// Complete tracking
$tracker->completeAsync($worklog);
```

#### Supported Phases

- `plan` - Planning & Analysis
- `implement` - Implementation
- `test` - Running Tests & Checks
- `review` - Code Review
- `pr` - Creating Pull Request
- `repair` - Self-Healing Repair
- `context` - Building Context

### 2. Jira Worklog Integration

Fully implemented `addWorklog()` in `JiraTicketProvider`:

- **API Integration**: Uses Jira REST API v2 `/issue/{key}/worklog`
- **Time Format**: Converts seconds to Jira format (e.g., "1h 30m")
- **Minimum Time**: Enforces 60 seconds minimum (Jira requirement)
- **Timezone Support**: Properly formats ISO 8601 timestamps with timezone
- **Retry Logic**: 3 attempts with exponential backoff
- **Error Handling**: Distinguishes between retryable and permanent failures

#### Request Format

```json
{
    "timeSpentSeconds": 300,
    "started": "2025-08-10T14:30:00.000+02:00",
    "comment": "Synapticore Bot - Planning & Analysis\n\nAutomated time tracking"
}
```

### 3. Job Integration

All major jobs now track time automatically:

#### PlanTicketJob

```php
$tracker->run($this->ticket, 'plan', function () use (...) {
    // Planning logic
}, 'Generating implementation plan');
```

#### RunChecksJob

```php
$tracker->run($this->ticket, 'test', function () use (...) {
    // Testing logic
}, 'Running tests and checks');
```

### 4. Database Schema

Enhanced `worklogs` table with tracking fields:

- `user_id` - User who performed the work
- `status` - in_progress, completed, failed
- `synced_at` - When synced to external system
- `sync_status` - success, failed
- `sync_error` - Error message if sync failed
- `ended_at` - Now nullable for async tracking

### 5. Configuration

#### Push Mode

```php
// config/synaptic.php
'worklog' => [
    'push_mode' => env('SYNAPTIC_WORKLOG_PUSH', 'immediate'), // immediate|batch
],
```

- **immediate**: Syncs to Jira immediately after work completes
- **batch**: Stores locally for later batch sync

#### Jira Credentials

```env
JIRA_URL=https://your-org.atlassian.net
JIRA_USERNAME=user@example.com
JIRA_TOKEN=your-api-token
```

## Usage

### Testing Worklog Tracking

```bash
# Test basic time tracking
php artisan worklog:test --test-tracking

# Test Jira sync (requires valid credentials)
php artisan worklog:test --test-sync

# Test batch sync
php artisan worklog:test --test-batch

# Use specific ticket
php artisan worklog:test --ticket-id=123
```

### Getting Total Time

```php
$tracker = new TrackedSection();
$totals = $tracker->getTotalTime($ticket);

// Returns:
[
    'total_seconds' => 180,
    'total_formatted' => '3m',
    'by_phase' => [
        'plan' => 120,
        'test' => 60,
    ],
    'by_phase_formatted' => [
        'plan' => '2m',
        'test' => '1m',
    ],
]
```

### Batch Sync

```php
// Sync up to 100 unsynced worklogs
$synced = $tracker->batchSync(100);
```

## Example Output

### Database Record

```sql
SELECT * FROM worklogs WHERE ticket_id = 123;

| id | ticket_id | user_id | phase | seconds | started_at          | ended_at            | status    | synced_at           |
|----|-----------|---------|-------|---------|-------------------|-------------------|-----------|-------------------|
| 1  | 123       | 1       | plan  | 120     | 2025-08-10 14:00 | 2025-08-10 14:02 | completed | 2025-08-10 14:02 |
| 2  | 123       | 1       | test  | 60      | 2025-08-10 14:03 | 2025-08-10 14:04 | completed | 2025-08-10 14:04 |
```

### Jira Worklog

In Jira, the worklog appears as:

```
Time Spent: 2m
Started: Aug 10, 2025 2:00 PM
Author: synapticore-bot
Comment: Synapticore Bot - Planning & Analysis

Generating implementation plan

Automated time tracking by Synapticore
```

## Benefits

### Accurate Time Tracking

- **Automatic**: No manual time entry needed
- **Precise**: Tracks actual execution time
- **Phase-specific**: Separates planning, implementation, testing, etc.
- **Failed Work**: Still tracks time even if work fails

### Transparency

- **Stakeholder Visibility**: Time logged directly in Jira
- **Audit Trail**: Complete history of work performed
- **Cost Tracking**: Accurate time for billing/invoicing

### Performance Insights

- **Phase Analysis**: See which phases take longest
- **Trend Detection**: Identify performance improvements/degradations
- **Resource Planning**: Better estimates based on historical data

## Error Handling

### Network Failures

- Automatic retry with exponential backoff
- Falls back to local storage on persistent failure
- Batch sync can recover failed syncs

### Invalid Data

- Minimum time enforcement (60 seconds for Jira)
- Timezone handling for international teams
- Proper error messages for debugging

## Security Considerations

- **Authentication**: Uses Jira API tokens, not passwords
- **Rate Limiting**: Respects Jira rate limits with backoff
- **Data Privacy**: Only syncs necessary information
- **Error Sanitization**: Sensitive data removed from logs

## Future Enhancements

1. **Advanced Analytics**: Dashboards showing time trends
2. **Cost Calculation**: Automatic invoice generation from worklogs
3. **Team Tracking**: Multi-user time tracking
4. **Custom Fields**: Support for Jira custom worklog fields
5. **Webhook Support**: Real-time sync via webhooks
6. **Mobile App**: Track time from mobile devices