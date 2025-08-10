# Data Transfer Objects (DTOs)

This directory contains all immutable Data Transfer Objects using Spatie/laravel-data.

## Core Principles

1. **Immutability**: All DTOs are immutable with readonly properties
2. **Type Safety**: Strict typing for all properties
3. **Validation**: Built-in validation through Spatie/laravel-data
4. **Serialization**: Automatic conversion to/from arrays and JSON

## DTO Categories

### Ticket System DTOs
- `TicketDto`: Ticket information
- `TicketWebhookEventDto`: Webhook event data

### VCS DTOs
- `OpenPrDto`: Pull request creation data
- `PrCreatedDto`: Created pull request information

### AI Service DTOs
- `PlanningInputDto`: Input for AI planning
- `PlanJson`: AI-generated plan
- `ImplementInputDto`: Input for AI implementation
- `PatchSummaryJson`: Implementation patch summary
- `ReviewInputDto`: Input for AI review
- `ReviewResultDto`: Review results

### Infrastructure DTOs
- `VectorDto`: Vector embedding data
- `EmbeddingSearchHitDto`: Search result data
- `ProcessResultDto`: Command execution results
- `NotifyDto`: Notification data

## Usage Examples

### Creating a DTO

```php
use App\DTO\TicketDto;

$ticket = new TicketDto(
    externalKey: 'JIRA-123',
    title: 'Fix authentication bug',
    body: 'Users cannot log in...',
    status: 'in_progress',
    priority: 'high',
    source: 'jira',
    labels: ['bug', 'authentication'],
    acceptanceCriteria: ['User can log in', 'Session persists'],
);
```

### From Request Data

```php
use App\DTO\TicketDto;

// From request
$ticket = TicketDto::from($request->validated());

// From array
$ticket = TicketDto::from([
    'externalKey' => 'JIRA-123',
    'title' => 'Bug fix',
    // ...
]);
```

### Converting to Array/JSON

```php
// To array
$array = $ticket->toArray();

// To JSON
$json = $ticket->toJson();

// In responses
return response()->json($ticket);
```

### Collections

```php
use App\DTO\Collections\TicketCollection;

$tickets = TicketCollection::from($ticketsArray);

// Filter by status
$openTickets = $tickets->byStatus('open');

// Get high priority tickets
$urgent = $tickets->highPriority();
```

## Validation

DTOs automatically validate data on creation:

```php
use App\DTO\TicketDto;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\In;

class CustomDto extends Data
{
    public function __construct(
        #[Required]
        public readonly string $field,
        
        #[In(['option1', 'option2'])]
        public readonly string $choice,
    ) {}
}
```

## Type Hints in Services

Always use DTOs for method signatures:

```php
class TicketService
{
    public function process(TicketDto $ticket): PlanJson
    {
        // Process ticket
    }
    
    public function notify(NotifyDto $notification): void
    {
        // Send notification
    }
}
```

## Testing with DTOs

```php
public function test_ticket_processing()
{
    $ticketDto = new TicketDto(
        externalKey: 'TEST-123',
        title: 'Test Ticket',
        // ... other required fields
    );
    
    $service = new TicketService();
    $result = $service->process($ticketDto);
    
    $this->assertInstanceOf(PlanJson::class, $result);
}
```