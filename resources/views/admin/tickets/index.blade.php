@extends('layouts.admin')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-semibold text-gray-900">Tickets</h2>
                    
                    <!-- Filters -->
                    <form method="GET" action="{{ route('admin.tickets.index') }}" class="flex gap-2">
                        <select name="project_id" onchange="this.form.submit()" 
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Projects</option>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}" {{ request('project_id') == $project->id ? 'selected' : '' }}>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                        
                        <select name="workflow_state" onchange="this.form.submit()" 
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All States</option>
                            <option value="INGESTED" {{ request('workflow_state') == 'INGESTED' ? 'selected' : '' }}>Ingested</option>
                            <option value="PLANNED" {{ request('workflow_state') == 'PLANNED' ? 'selected' : '' }}>Planned</option>
                            <option value="IMPLEMENTING" {{ request('workflow_state') == 'IMPLEMENTING' ? 'selected' : '' }}>Implementing</option>
                            <option value="TESTING" {{ request('workflow_state') == 'TESTING' ? 'selected' : '' }}>Testing</option>
                            <option value="REVIEWING" {{ request('workflow_state') == 'REVIEWING' ? 'selected' : '' }}>Reviewing</option>
                            <option value="PR_CREATED" {{ request('workflow_state') == 'PR_CREATED' ? 'selected' : '' }}>PR Created</option>
                            <option value="DONE" {{ request('workflow_state') == 'DONE' ? 'selected' : '' }}>Done</option>
                            <option value="FAILED" {{ request('workflow_state') == 'FAILED' ? 'selected' : '' }}>Failed</option>
                        </select>
                        
                        <input type="text" name="search" value="{{ request('search') }}" 
                               placeholder="Search tickets..." 
                               class="rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                            Search
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Ticket
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Project
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Title
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Workflow
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    PR
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($tickets as $ticket)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="{{ route('admin.tickets.show', $ticket) }}" 
                                           class="text-blue-600 hover:text-blue-800 font-medium">
                                            {{ $ticket->external_key }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $ticket->project->name }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        {{ Str::limit($ticket->title, 40) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs rounded-full 
                                            {{ $ticket->status === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                                            {{ $ticket->status === 'in_progress' ? 'bg-blue-100 text-blue-800' : '' }}
                                            {{ $ticket->status === 'todo' ? 'bg-gray-100 text-gray-800' : '' }}">
                                            {{ $ticket->status }}
                                        </span>
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
                                            <span class="text-gray-400 text-xs">None</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($ticket->pullRequests->count() > 0)
                                            <a href="{{ $ticket->pullRequests->first()->url }}" 
                                               target="_blank"
                                               class="text-green-600 hover:text-green-800">
                                                ✓ PR
                                            </a>
                                        @else
                                            <span class="text-gray-400 text-xs">-</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex gap-2">
                                            <a href="{{ route('admin.tickets.show', $ticket) }}" 
                                               class="text-indigo-600 hover:text-indigo-900">View</a>
                                            
                                            @if(!$ticket->workflow)
                                                <form action="{{ route('admin.tickets.start-workflow', $ticket) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-green-600 hover:text-green-900">Start</button>
                                                </form>
                                            @elseif($ticket->workflow->state === 'FAILED')
                                                <form action="{{ route('admin.tickets.retry-workflow', $ticket) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-yellow-600 hover:text-yellow-900">Retry</button>
                                                </form>
                                            @elseif(!in_array($ticket->workflow->state, ['DONE', 'FAILED']))
                                                <form action="{{ route('admin.tickets.cancel-workflow', $ticket) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-red-600 hover:text-red-900"
                                                            onclick="return confirm('Are you sure you want to cancel this workflow?')">
                                                        Cancel
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $tickets->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection