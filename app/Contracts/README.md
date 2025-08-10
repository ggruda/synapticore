# Contracts (Interfaces)

This directory contains all contract interfaces for the Synapticore system. These contracts define the API boundaries for external integrations and ensure extensibility.

## Core Principles

1. **Dependency Inversion**: Controllers and Services depend on contracts, not concrete implementations
2. **Extensibility**: New providers can be added by implementing the contracts
3. **Type Safety**: All methods use strict types and DTOs for data transfer
4. **Immutability**: All DTOs are immutable to prevent accidental mutations

## Available Contracts

### Ticket Management
- `TicketProviderContract`: Interface for ticket systems (Jira, Linear, Azure DevOps)

### Version Control
- `VcsProviderContract`: Interface for VCS providers (GitHub, GitLab, Bitbucket)

### AI Services
- `AiPlannerContract`: Interface for AI planning services
- `AiImplementerContract`: Interface for AI implementation services
- `AiReviewerContract`: Interface for AI code review services

### Infrastructure
- `EmbeddingProviderContract`: Interface for vector embedding services
- `RunnerContract`: Interface for command execution services
- `NotificationChannelContract`: Interface for notification services

## Usage Example

```php
// In a Service Provider
$this->app->bind(TicketProviderContract::class, JiraProvider::class);

// In a Controller/Service
public function __construct(
    private readonly TicketProviderContract $ticketProvider
) {}

// Usage
$ticket = $this->ticketProvider->fetchTicket('JIRA-123');
```

## Implementing a Contract

```php
namespace App\Providers\Jira;

use App\Contracts\TicketProviderContract;
use App\DTO\TicketDto;

class JiraProvider implements TicketProviderContract
{
    public function fetchTicket(string $externalKey): TicketDto
    {
        // Implementation
        return new TicketDto(...);
    }
    
    // Implement other methods...
}
```

## Testing

When testing, you can easily mock contracts:

```php
$mock = $this->mock(TicketProviderContract::class);
$mock->shouldReceive('fetchTicket')
     ->with('TEST-123')
     ->andReturn(new TicketDto(...));
```