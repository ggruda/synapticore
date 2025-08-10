@extends('layouts.admin')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="text-2xl font-bold text-gray-900">{{ $stats['total_workflows'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">Total Workflows</div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="text-2xl font-bold text-green-600">{{ $stats['completed'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">Completed</div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="text-2xl font-bold text-yellow-600">{{ $stats['in_progress'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">In Progress</div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="text-2xl font-bold text-red-600">{{ $stats['failed'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">Failed</div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Active Workflows -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Active Workflows</h3>
                    <div class="space-y-3">
                        @forelse($activeWorkflows as $workflow)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div>
                                    <a href="{{ route('admin.tickets.show', $workflow->ticket) }}" 
                                       class="text-blue-600 hover:text-blue-800 font-medium">
                                        {{ $workflow->ticket->external_key }}
                                    </a>
                                    <div class="text-sm text-gray-600">{{ $workflow->ticket->title }}</div>
                                </div>
                                <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                    {{ $workflow->state }}
                                </span>
                            </div>
                        @empty
                            <p class="text-gray-500">No active workflows</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Failed Workflows -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Failed Workflows</h3>
                    <div class="space-y-3">
                        @forelse($failedWorkflows as $workflow)
                            <div class="flex items-center justify-between p-3 bg-red-50 rounded">
                                <div>
                                    <a href="{{ route('admin.tickets.show', $workflow->ticket) }}" 
                                       class="text-blue-600 hover:text-blue-800 font-medium">
                                        {{ $workflow->ticket->external_key }}
                                    </a>
                                    <div class="text-sm text-gray-600">
                                        Failed: {{ $workflow->updated_at->diffForHumans() }}
                                    </div>
                                </div>
                                <form action="{{ route('admin.tickets.retry-workflow', $workflow->ticket) }}" method="POST">
                                    @csrf
                                    <button type="submit" 
                                            class="px-3 py-1 bg-yellow-500 text-white text-xs rounded hover:bg-yellow-600">
                                        Retry
                                    </button>
                                </form>
                            </div>
                        @empty
                            <p class="text-gray-500">No failed workflows</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Projects Summary -->
        <div class="mt-8 bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">Projects</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Project
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Total Tickets
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Active Workflows
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($projects as $project)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="{{ route('admin.projects.show', $project) }}" 
                                           class="text-blue-600 hover:text-blue-800">
                                            {{ $project->name }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $project->tickets_count }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $project->active_workflows }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <a href="{{ route('admin.projects.show', $project) }}" 
                                           class="text-indigo-600 hover:text-indigo-900">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Tickets -->
        <div class="mt-8 bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">Recent Tickets</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Ticket
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Title
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Workflow State
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Created
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($recentTickets as $ticket)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="{{ route('admin.tickets.show', $ticket) }}" 
                                           class="text-blue-600 hover:text-blue-800">
                                            {{ $ticket->external_key }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        {{ Str::limit($ticket->title, 50) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($ticket->workflow)
                                            <span class="px-2 py-1 text-xs rounded-full 
                                                {{ $ticket->workflow->state === 'DONE' ? 'bg-green-100 text-green-800' : '' }}
                                                {{ $ticket->workflow->state === 'FAILED' ? 'bg-red-100 text-red-800' : '' }}
                                                {{ !in_array($ticket->workflow->state, ['DONE', 'FAILED']) ? 'bg-blue-100 text-blue-800' : '' }}">
                                                {{ $ticket->workflow->state }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">No workflow</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $ticket->created_at->diffForHumans() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection