<?php

namespace App\Service\Project;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Project\ProjectContract;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * The example product feature, and the pattern to copy for a real one:
 *
 *   1. the controller asks WorkspacePolicy::write (role AND workspace state),
 *   2. the service checks the plan limit before writing (assertAllows),
 *   3. the count is written back as a gauge, so billing shows it and a
 *      downgrade that could not hold it is refused (assertPlanFits).
 */
class ProjectService implements ProjectContract
{
    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly UsageContract $usage,
    ) {}

    public function create(Workspace $workspace, User $creator, string $name, ?string $description): Project
    {
        return DB::transaction(function () use ($workspace, $creator, $name, $description) {
            // The workspace row is the lock, so two requests at the limit cannot
            // both pass the check. (Postgres refuses FOR UPDATE on a count.)
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

            $count = Project::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->count();

            $this->entitlements->assertAllows($workspace, Features::PROJECTS, $count + 1);

            $project = Project::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'description' => $description,
                'created_by_user_id' => $creator->id,
            ]);

            $this->usage->setGauge($workspace, Features::PROJECTS, $count + 1);

            return $project;
        });
    }

    public function update(Project $project, string $name, ?string $description): Project
    {
        $project->update(['name' => $name, 'description' => $description]);

        return $project->refresh();
    }

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $workspace = $project->workspace;

            $project->delete();

            $this->usage->setGauge(
                $workspace,
                Features::PROJECTS,
                Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count(),
            );

            // Freeing one up can lift an over-limit block (section 7).
            $this->usage->evaluate($workspace);
        });
    }
}
