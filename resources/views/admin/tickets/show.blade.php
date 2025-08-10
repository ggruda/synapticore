@extends('layouts.admin')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Ticket Header -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
            <div class="p-6">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-900">{{ $ticket->external_key }}</h2>
                        <h3 class="text-lg text-gray-700 mt-2">{{ $ticket->title }}</h3>
                        <div class="mt-4 flex gap-2">
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">
                                {{ $ticket->project->name }}
                            </span>
                            <span class="px-2 py-1 text-xs rounded-full 
                                {{ $ticket->priority === 'urgent' ? 'bg-red-100 text-red-800' : '' }}
                                {{ $ticket->priority === 'high' ? 'bg-orange-100 text-orange-800' : '' }}
                                {{ $ticket->priority === 'medium' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                {{ $ticket->priority === 'low' ? 'bg-green-100 text-green-800' : '' }}">
                                {{ $ticket->priority }}
                            </span>
                            @foreach($ticket->labels ?? [] as $label)
                                <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                    {{ $label }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex gap-2">
                        @if(!$ticket->workflow)
                            <form action="{{ route('admin.tickets.start-workflow', $ticket) }}" method="POST">
                                @csrf
                                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Start Workflow
                                </button>
                            </form>
                        @elseif($ticket->workflow->state === 'FAILED')
                            <form action="{{ route('admin.tickets.retry-workflow', $ticket) }}" method="POST">
                                @csrf
                                <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700">
                                    Retry Workflow
                                </button>
                            </form>
                        @elseif(!in_array($ticket->workflow->state, ['DONE', 'FAILED']))
                            <form action="{{ route('admin.tickets.cancel-workflow', $ticket) }}" method="POST">
                                @csrf
                                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
                                        onclick="return confirm('Are you sure you want to cancel this workflow?')">
                                    Cancel Workflow
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="mt-6 prose max-w-none">
                    <h4 class="text-sm font-semibold text-gray-700">Description</h4>
                    <p class="text-gray-600">{{ $ticket->body }}</p>
                    
                    @if($ticket->acceptance_criteria && count($ticket->acceptance_criteria) > 0)
                        <h4 class="text-sm font-semibold text-gray-700 mt-4">Acceptance Criteria</h4>
                        <ul class="list-disc list-inside text-gray-600">
                            @foreach($ticket->acceptance_criteria as $criteria)
                                <li>{{ $criteria }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Workflow Status -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Workflow Status</h3>
                    
                    @if($ticket->workflow && $workflowStatus)
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">State:</span>
                                <span class="px-2 py-1 text-xs rounded-full 
                                    {{ $workflowStatus['current_state'] === 'DONE' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $workflowStatus['current_state'] === 'FAILED' ? 'bg-red-100 text-red-800' : '' }}
                                    {{ !in_array($workflowStatus['current_state'], ['DONE', 'FAILED']) ? 'bg-blue-100 text-blue-800' : '' }}">
                                    {{ $workflowStatus['current_state'] }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Started:</span>
                                <span>{{ $ticket->workflow->created_at->format('Y-m-d H:i') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Duration:</span>
                                <span>{{ $workflowStatus['duration_minutes'] }} minutes</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Retries:</span>
                                <span>{{ $workflowStatus['retries'] }}</span>
                            </div>
                            
                            @if($ticket->plan)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Plan:</span>
                                    <span class="text-green-600">✓ Generated</span>
                                </div>
                            @endif
                            
                            @if($ticket->patches->count() > 0)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Patches:</span>
                                    <span class="text-green-600">✓ {{ $ticket->patches->count() }} created</span>
                                </div>
                            @endif
                            
                            @if($ticket->pullRequests->count() > 0)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Pull Request:</span>
                                    <a href="{{ $ticket->pullRequests->first()->url }}" 
                                       target="_blank"
                                       class="text-blue-600 hover:text-blue-800">
                                        View PR ↗
                                    </a>
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-gray-500">No workflow started</p>
                    @endif
                </div>
            </div>

            <!-- Test Runs -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Test Runs</h3>
                    
                    @if($ticket->runs->count() > 0)
                        <div class="space-y-2">
                            @foreach($ticket->runs as $run)
                                <div class="p-3 bg-gray-50 rounded">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="font-medium">{{ ucfirst($run->type) }}</span>
                                            <span class="text-sm text-gray-500 ml-2">
                                                {{ $run->created_at->format('H:i:s') }}
                                            </span>
                                        </div>
                                        <span class="px-2 py-1 text-xs rounded-full 
                                            {{ $run->status === 'passed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $run->status }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-gray-500">No test runs yet</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Artifacts -->
        <div class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">Artifacts</h3>
                
                @if(count($artifacts) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Name
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Created
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($artifacts as $artifact)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $artifact['type'] }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $artifact['name'] }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ is_string($artifact['created_at']) ? $artifact['created_at'] : $artifact['created_at']->format('Y-m-d H:i:s') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <a href="{{ route('artifacts.download', ['path' => $artifact['path']]) }}" 
                                               class="text-indigo-600 hover:text-indigo-900">
                                                Download
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-gray-500">No artifacts available</p>
                @endif
            </div>
        </div>

        <!-- Pull Request Details -->
        @if($ticket->pullRequests->count() > 0)
            <div class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Pull Request</h3>
                    
                    @foreach($ticket->pullRequests as $pr)
                        <div class="border rounded-lg p-4 mb-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <a href="{{ $pr->url }}" target="_blank" 
                                       class="text-lg font-medium text-blue-600 hover:text-blue-800">
                                        View on {{ str_contains($pr->url, 'github') ? 'GitHub' : 'Git Provider' }} ↗
                                    </a>
                                    <div class="mt-2 text-sm text-gray-600">
                                        <p>Branch: <code class="bg-gray-100 px-2 py-1 rounded">{{ $pr->branch_name }}</code></p>
                                        <p class="mt-1">Draft: {{ $pr->is_draft ? 'Yes' : 'No' }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="flex gap-1">
                                        @foreach($pr->labels ?? [] as $label)
                                            <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800">
                                                {{ $label }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection