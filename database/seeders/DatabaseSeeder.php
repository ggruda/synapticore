<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patch;
use App\Models\Plan;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Repo;
use App\Models\Run;
use App\Models\Secret;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Worklog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@synapticore.local',
        ]);

        // Create regular users
        $users = User::factory(5)->create();

        // Create projects with full setup
        $projects = Project::factory(3)->create()->each(function ($project) {
            // Create repos for each project
            Repo::factory(2)->create(['project_id' => $project->id]);

            // Create secrets for each project
            Secret::factory()->github()->create(['project_id' => $project->id]);
            Secret::factory()->jira()->create(['project_id' => $project->id]);

            // Create tickets with full workflow
            Ticket::factory(10)->create(['project_id' => $project->id])->each(function ($ticket) {
                // Create workflow state
                $workflow = Workflow::factory()->create(['ticket_id' => $ticket->id]);

                // Create plan if workflow is past planning stage
                if (! in_array($workflow->state, [Workflow::STATE_INGESTED, Workflow::STATE_CONTEXT_READY])) {
                    Plan::factory()->create(['ticket_id' => $ticket->id]);
                }

                // Create patches if implementing or later
                if (in_array($workflow->state, [
                    Workflow::STATE_IMPLEMENTING,
                    Workflow::STATE_TESTING,
                    Workflow::STATE_REVIEWING,
                    Workflow::STATE_FIXING,
                    Workflow::STATE_PR_CREATED,
                    Workflow::STATE_DONE,
                ])) {
                    Patch::factory(rand(1, 3))->create(['ticket_id' => $ticket->id]);
                }

                // Create runs for testing phase
                if (in_array($workflow->state, [
                    Workflow::STATE_TESTING,
                    Workflow::STATE_REVIEWING,
                    Workflow::STATE_PR_CREATED,
                    Workflow::STATE_DONE,
                ])) {
                    Run::factory()->create([
                        'ticket_id' => $ticket->id,
                        'type' => Run::TYPE_LINT,
                        'status' => Run::STATUS_SUCCESS,
                    ]);

                    Run::factory()->testWithCoverage()->create(['ticket_id' => $ticket->id]);

                    if (rand(0, 1)) {
                        Run::factory()->create([
                            'ticket_id' => $ticket->id,
                            'type' => Run::TYPE_BUILD,
                            'status' => fake()->randomElement([Run::STATUS_SUCCESS, Run::STATUS_FAILED]),
                        ]);
                    }
                }

                // Create pull request if PR created or done
                if (in_array($workflow->state, [Workflow::STATE_PR_CREATED, Workflow::STATE_DONE])) {
                    $pr = PullRequest::factory()->create(['ticket_id' => $ticket->id]);

                    if ($workflow->state === Workflow::STATE_DONE) {
                        $pr->update(['is_draft' => false]);
                    }
                }

                // Create worklogs
                $phases = [];
                if (! in_array($workflow->state, [Workflow::STATE_INGESTED])) {
                    $phases[] = Worklog::PHASE_PLAN;
                }
                if (in_array($workflow->state, [
                    Workflow::STATE_IMPLEMENTING,
                    Workflow::STATE_TESTING,
                    Workflow::STATE_REVIEWING,
                    Workflow::STATE_FIXING,
                    Workflow::STATE_PR_CREATED,
                    Workflow::STATE_DONE,
                ])) {
                    $phases[] = Worklog::PHASE_IMPLEMENT;
                }
                if (in_array($workflow->state, [
                    Workflow::STATE_TESTING,
                    Workflow::STATE_REVIEWING,
                    Workflow::STATE_PR_CREATED,
                    Workflow::STATE_DONE,
                ])) {
                    $phases[] = Worklog::PHASE_TEST;
                }
                if (in_array($workflow->state, [
                    Workflow::STATE_REVIEWING,
                    Workflow::STATE_PR_CREATED,
                    Workflow::STATE_DONE,
                ])) {
                    $phases[] = Worklog::PHASE_REVIEW;
                }

                foreach ($phases as $phase) {
                    Worklog::factory()->create([
                        'ticket_id' => $ticket->id,
                        'phase' => $phase,
                    ]);
                }
            });

            // Create invoices for project
            Invoice::factory(3)
                ->sequence(
                    ['status' => Invoice::STATUS_PAID],
                    ['status' => Invoice::STATUS_SENT],
                    ['status' => Invoice::STATUS_DRAFT],
                )
                ->create(['project_id' => $project->id])
                ->each(function ($invoice) {
                    // Create invoice items
                    InvoiceItem::factory(rand(3, 8))->create(['invoice_id' => $invoice->id]);

                    // Update invoice totals based on items
                    $netTotal = $invoice->items()->sum('net_amount');
                    $grossTotal = $netTotal * (1 + $invoice->tax_rate / 100);

                    $invoice->update([
                        'net_total' => $netTotal,
                        'gross_total' => round($grossTotal, 2),
                    ]);
                });
        });

        // Output summary
        $this->command->info('Database seeded successfully!');
        $this->command->table(
            ['Entity', 'Count'],
            [
                ['Users', User::count()],
                ['Projects', Project::count()],
                ['Tickets', Ticket::count()],
                ['Workflows', Workflow::count()],
                ['Pull Requests', PullRequest::count()],
                ['Invoices', Invoice::count()],
            ]
        );
    }
}
