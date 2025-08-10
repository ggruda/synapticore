<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

// Base exception for all Synaptic-related errors
class SynapticException extends Exception {}

// Ticket Provider Exceptions
class TicketNotFoundException extends SynapticException {}
class ProviderConnectionException extends SynapticException {}
class InvalidStatusTransitionException extends SynapticException {}
class InvalidWebhookPayloadException extends SynapticException {}

// VCS Provider Exceptions
class RepositoryNotFoundException extends SynapticException {}
class CloneFailedException extends SynapticException {}
class BranchCreationFailedException extends SynapticException {}
class CommitFailedException extends SynapticException {}
class NothingToCommitException extends SynapticException {}
class PushFailedException extends SynapticException {}
class AuthenticationFailedException extends SynapticException {}
class PullRequestCreationFailedException extends SynapticException {}

// AI Service Exceptions
class AiServiceUnavailableException extends SynapticException {}
class PlanningFailedException extends SynapticException {}
class ImplementationFailedException extends SynapticException {}
class ReviewFailedException extends SynapticException {}

// Embedding Exceptions
class EmbeddingGenerationException extends SynapticException {}
class SearchFailedException extends SynapticException {}

// Runner Exceptions
class CommandExecutionException extends SynapticException {}
class TimeoutException extends SynapticException {}

// Notification Exceptions
class NotificationFailedException extends SynapticException {}

// Validation Exceptions
class ValidationFailedException extends SynapticException {}

// Security Exceptions
class CommandBlockedException extends SynapticException {}
class PathViolationException extends SynapticException {}

// Implementation Exception
class NotImplementedException extends SynapticException {}
