<?php

namespace App\Http\Controllers\Project;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Project\ProjectContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The example product feature. Thin: rules live in ProjectService and the policies. */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectContract $projects,
        private readonly EntitlementContract $entitlements,
        private readonly UsageContract $usage,
    ) {}

    public function index(): Response|RedirectResponse
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if ($workspace === null) {
            return redirect()->route('dashboard');
        }

        Gate::authorize('view', $workspace);

        return Inertia::render('project/index', [
            'projects' => Project::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->with('creator:id,name')
                ->latest()
                ->get()
                ->map(fn (Project $project) => [
                    'ulid' => $project->ulid,
                    'name' => $project->name,
                    'description' => $project->description,
                    'created_by' => $project->creator?->name,
                    'created_at' => $project->created_at,
                ]),
            'usage' => [
                'used' => $this->usage->current($workspace, Features::PROJECTS),
                'limit' => $this->entitlements->limitFor($workspace, Features::PROJECTS),
            ],
            // Role AND state, from the same policy the writes below ask.
            'can_write' => Gate::allows('write', $workspace),
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $workspace = $this->writableWorkspace();

        $this->projects->create(
            $workspace,
            $request->user(),
            $request->validated('name'),
            $request->validated('description'),
        );

        return back();
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->assertInCurrentWorkspace($project, $this->writableWorkspace());

        $this->projects->update($project, $request->validated('name'), $request->validated('description'));

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->assertInCurrentWorkspace($project, $this->writableWorkspace());

        $this->projects->delete($project);

        return back();
    }

    private function writableWorkspace(): Workspace
    {
        $workspace = app(CurrentWorkspace::class)->get()
            ?? throw new NotFoundHttpException('No workspace selected.');

        Gate::authorize('write', $workspace);

        return $workspace;
    }

    /**
     * Route binding runs before ResolveWorkspace sets the tenant, so the
     * global scope has not filtered this project yet. Checked by hand, or a
     * ULID from another workspace could be edited from this one.
     */
    private function assertInCurrentWorkspace(Project $project, Workspace $workspace): void
    {
        if ((int) $project->workspace_id !== (int) $workspace->id) {
            throw new NotFoundHttpException;
        }
    }
}
